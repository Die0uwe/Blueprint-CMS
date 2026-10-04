<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Forum;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Security\CsrfProtection;

/**
 * Moderatie-overzicht in de admin (permissie `forum.moderate`, afgedwongen door
 * de route): alle topics en de laatste reacties van alle borden, met pinnen,
 * sluiten, verplaatsen en verwijderen op één plek.
 */
final class ForumModerationController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly ForumRepository $repo,
        private readonly AuthManager     $auth,
        private readonly AuditLogger     $audit,
    ) {}

    public function index(Request $request): Response
    {
        $page   = $request->page();
        $topics = $this->repo->getTopicsForAdmin(self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $total  = $this->repo->countAllTopics();
        $pages  = max(1, (int) ceil($total / self::PER_PAGE));
        $posts  = $this->repo->getRecentPostsForAdmin(15);
        $boards = $this->repo->getAllBoardsForAdmin();
        $flash  = $request->query('ok');

        ob_start();
        include __DIR__ . '/views/admin_moderation.php';
        return Response::html((string) ob_get_clean());
    }

    public function pin(Request $request): Response
    {
        return $this->withTopic($request, function (array $t): string {
            $new = !((bool) $t['is_pinned']);
            $this->repo->setPinned((int) $t['id'], $new);
            $this->log($new ? 'forum.topic.pin' : 'forum.topic.unpin', $t);
            return $new ? 'vastgezet' : 'losgemaakt';
        });
    }

    public function lock(Request $request): Response
    {
        return $this->withTopic($request, function (array $t): string {
            $new = !((bool) $t['is_locked']);
            $this->repo->setLocked((int) $t['id'], $new);
            $this->log($new ? 'forum.topic.lock' : 'forum.topic.unlock', $t);
            return $new ? 'gesloten' : 'heropend';
        });
    }

    public function move(Request $request): Response
    {
        return $this->withTopic($request, function (array $t) use ($request): string {
            $target = (int) $request->input('board_id', 0);
            if (!$this->repo->moveTopic((int) $t['id'], $target)) {
                return 'mislukt';
            }
            $this->log('forum.topic.move', $t);
            return 'verplaatst';
        });
    }

    public function deleteTopic(Request $request): Response
    {
        return $this->withTopic($request, function (array $t): string {
            $this->repo->deleteTopic((int) $t['id']);
            $this->log('forum.topic.delete', $t);
            return 'verwijderd';
        });
    }

    public function deletePost(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $post = $this->repo->findPostById((int) $request->param('id'));
        if ($post === null) {
            return Response::html('<h1>404 — Reactie niet gevonden</h1>', 404);
        }
        if (!$this->repo->deletePost((int) $post['id'])) {
            return Response::redirect('/admin/forum/moderatie?ok=mislukt');
        }
        $this->audit->log('forum.post.delete', $this->auth->id(), $this->auth->user()['username'] ?? null, [
            'post_id'  => (int) $post['id'],
            'topic_id' => (int) $post['topic_id'],
        ]);
        return Response::redirect('/admin/forum/moderatie?ok=reactie-verwijderd');
    }

    /** @param callable(array):string $action geeft het flash-label terug */
    private function withTopic(Request $request, callable $action): Response
    {
        CsrfProtection::validateRequest();
        $topic = $this->repo->findTopicById((int) $request->param('id'));
        if ($topic === null) {
            return Response::html('<h1>404 — Topic niet gevonden</h1>', 404);
        }
        return Response::redirect('/admin/forum/moderatie?ok=' . $action($topic));
    }

    private function log(string $action, array $topic): void
    {
        $this->audit->log($action, $this->auth->id(), $this->auth->user()['username'] ?? null, [
            'topic_id' => (int) $topic['id'],
            'title'    => $topic['title'],
        ]);
    }
}
