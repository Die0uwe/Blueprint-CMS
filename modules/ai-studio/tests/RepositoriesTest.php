<?php

// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Tests;

require_once __DIR__ . '/Support/bootstrap.php';

use CommunityFusion\Modules\AiStudio\ConversationRepository;
use CommunityFusion\Modules\AiStudio\MessageRepository;
use CommunityFusion\Modules\AiStudio\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RepositoriesTest extends TestCase
{
    private PDO $pdo;
    private ConversationRepository $conversations;
    private MessageRepository $messages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = TestDb::pdo();
        $db = TestDb::connection($this->pdo);
        $this->conversations = new ConversationRepository($db);
        $this->messages = new MessageRepository($db);
    }

    #[Test]
    public function testCreateAndFindOnlyForTheOwner(): void
    {
        $id = $this->conversations->create(7, 'Mijn <b>gesprek</b>', 'openai', 'gpt-4o-mini');

        $found = $this->conversations->find($id, 7);
        self::assertNotNull($found);
        self::assertSame(7, $found['user_id']);
        self::assertSame('openai', $found['provider']);
        self::assertNull($this->conversations->find($id, 8), 'Een andere gebruiker ziet het gesprek niet.');
        self::assertNull($this->conversations->find($id + 100, 7));
    }

    #[Test]
    public function testTitleIsCleanedAndDefaulted(): void
    {
        $a = $this->conversations->create(1, "  kop\x00\x07  ", '', '');
        $b = $this->conversations->create(1, '   ', '', '');
        $c = $this->conversations->create(1, str_repeat('x', 500), '', '');

        self::assertSame('kop', $this->conversations->find($a, 1)['title'] ?? null);
        self::assertSame('Nieuw gesprek', $this->conversations->find($b, 1)['title'] ?? null);
        self::assertSame(ConversationRepository::TITLE_MAX, mb_strlen($this->conversations->find($c, 1)['title'] ?? ''));
    }

    #[Test]
    public function testListIsPerUserAndNewestFirst(): void
    {
        $first = $this->conversations->create(1, 'eerste', '', '');
        $second = $this->conversations->create(1, 'tweede', '', '');
        $this->conversations->create(2, 'van een ander', '', '');
        $this->pdo->exec("UPDATE cf_ai_conversations SET updated_at = '2020-01-01 00:00:00' WHERE id = {$first}");

        $titles = array_column($this->conversations->listForUser(1), 'title');
        self::assertSame(['tweede', 'eerste'], $titles);
        self::assertCount(1, $this->conversations->listForUser(2));
        self::assertSame($second, $this->conversations->listForUser(1)[0]['id']);
    }

    #[Test]
    public function testDeleteIsOwnerOnlyAndRemovesMessages(): void
    {
        $id = $this->conversations->create(1, 't', '', '');
        $this->messages->add($id, 'user', 'hoi');
        $this->messages->add($id, 'assistant', 'hallo', 'openai', 'm');

        self::assertFalse($this->conversations->delete($id, 2), 'Niet van jou.');
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM cf_ai_messages')->fetchColumn());

        self::assertTrue($this->conversations->delete($id, 1));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM cf_ai_messages')->fetchColumn());
        self::assertNull($this->conversations->find($id, 1));
        self::assertFalse($this->conversations->delete($id, 1));
    }

    #[Test]
    public function testRenameIsOwnerOnly(): void
    {
        $id = $this->conversations->create(1, 'oud', '', '');
        self::assertFalse($this->conversations->rename($id, 2, 'gekaapt'));
        self::assertTrue($this->conversations->rename($id, 1, 'nieuw'));
        self::assertFalse($this->conversations->rename($id, 1, '  '));
        self::assertSame('nieuw', $this->conversations->find($id, 1)['title'] ?? null);
    }

    #[Test]
    public function testMessagesComeBackOldestFirstLimitedToTheLastN(): void
    {
        $id = $this->conversations->create(1, 't', '', '');
        for ($i = 1; $i <= 5; $i++) {
            $this->messages->add($id, $i % 2 === 1 ? 'user' : 'assistant', "bericht {$i}");
        }

        $recent = $this->messages->recent($id, 3);
        self::assertSame(['bericht 3', 'bericht 4', 'bericht 5'], array_column($recent, 'content'));
        self::assertSame('user', $recent[0]['role']);
    }

    #[Test]
    public function testMessageRoleIsWhitelistedAndContentCapped(): void
    {
        $id = $this->conversations->create(1, 't', '', '');
        try {
            $this->messages->add($id, 'system', 'x');
            self::fail('Rol system hoort niet in de opslag.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $mid = $this->messages->add($id, 'assistant', str_repeat('é', MessageRepository::CONTENT_MAX_BYTES));
        $stored = (string) $this->pdo->query("SELECT content FROM cf_ai_messages WHERE id = {$mid}")->fetchColumn();
        self::assertLessThanOrEqual(MessageRepository::CONTENT_MAX_BYTES, strlen($stored));
        self::assertTrue(mb_check_encoding($stored, 'UTF-8'));
    }

    #[Test]
    public function testMessagesAreStoredVerbatimAsDataNotMarkup(): void
    {
        $id = $this->conversations->create(1, 't', '', '');
        $html = '<script>alert(1)</script> \' OR 1=1; --';
        $this->messages->add($id, 'assistant', $html);

        self::assertSame($html, $this->messages->recent($id)[0]['content'], 'Opslag is letterlijk; weergave gebeurt als tekst.');
    }

    #[Test]
    public function testProposalLookupIsOwnerOnlyAndAssistantOnly(): void
    {
        $id = $this->conversations->create(1, 't', '', '');
        $user = $this->messages->add($id, 'user', 'vraag');
        $bot = $this->messages->add($id, 'assistant', 'antwoord');
        $this->messages->setProposal($bot, "--- a\n+++ b\n", str_repeat('a', 64));
        $this->messages->setProposal($user, 'x', str_repeat('b', 64));

        $own = $this->messages->findOwnedProposal($bot, 1);
        self::assertNotNull($own);
        self::assertSame($id, $own['conversation_id']);
        self::assertSame(str_repeat('a', 64), $own['proposal_base_sha256']);

        self::assertNull($this->messages->findOwnedProposal($bot, 2), 'Voorstel van een ander gesprek is onbereikbaar.');
        self::assertNull($this->messages->findOwnedProposal($user, 1), 'Alleen assistant-berichten hebben voorstellen.');
        self::assertNull($this->messages->findOwnedProposal(9999, 1));
    }

    #[Test]
    public function testMarkAppliedIsReflectedInRecent(): void
    {
        $id = $this->conversations->create(1, 't', '', '');
        $bot = $this->messages->add($id, 'assistant', 'antwoord');
        $this->messages->setProposal($bot, 'diff', str_repeat('a', 64));

        self::assertTrue($this->messages->recent($id)[0]['has_proposal']);
        self::assertFalse($this->messages->recent($id)[0]['applied']);
        $this->messages->markApplied($bot);
        self::assertTrue($this->messages->recent($id)[0]['applied']);
    }

    #[Test]
    public function testSqlInjectionAttemptsStayData(): void
    {
        $id = $this->conversations->create(1, "x'); DROP TABLE cf_ai_messages; --", '', '');
        self::assertNotNull($this->conversations->find($id, 1));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM cf_ai_messages')->fetchColumn());
    }
}
