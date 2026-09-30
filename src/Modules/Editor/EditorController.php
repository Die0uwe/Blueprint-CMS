<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Editor;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Hook\HookManager;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Core\Security\RateLimiter;
use CommunityFusion\Core\Template\MarkupException;
use CommunityFusion\Core\Template\MarkupRenderer;
use CommunityFusion\Modules\Blog\BlogRepository;
use CommunityFusion\Modules\News\NewsRepository;
use CommunityFusion\Modules\Pages\PageRepository;

/**
 * EditorController — Bericht-editor (Editor A) voor Pagina's, Nieuws en Blog.
 *
 * Routes (alle achter AuthMiddleware + PermissionMiddleware:editor.use):
 *   GET  /admin/editor/{type}/{id}        editor-UI
 *   POST /admin/editor/preview            server-side preview (HTML in een sandbox-iframe)
 *   POST /admin/editor/draft              autosave-concept
 *   POST /admin/editor/{type}/{id}/save   opslaan
 *
 * Model: `content_markup` bewaart de bron (HTML + Twig); `content` bevat het gerenderde HTML en
 * is wat de publieke pagina's al tonen. Blog is platte tekst (geen markup). PHP-tags worden nooit
 * uitgevoerd; wie ze mag bewerken bepaalt `editor.markup.php` (server-side afgedwongen).
 */
final class EditorController
{
    private const TYPES = ['page', 'news', 'blog'];
    private const PREVIEWS_PER_MINUTE = 60;
    private const SAVES_PER_MINUTE = 30;
    private const DRAFTS_PER_MINUTE = 30;
    private const BLOG_MAX_BYTES = 102400;

    public function __construct(
        private readonly PageRepository $pages,
        private readonly NewsRepository $news,
        private readonly BlogRepository $blog,
        private readonly AuthManager $auth,
        private readonly Connection $db,
        private readonly CacheManager $cache,
        private readonly HookManager $hooks,
        private readonly AuditLogger $audit,
        private readonly ?MarkupRenderer $renderer = null,
    ) {}

    // ── UI ───────────────────────────────────────────────────────────────

    public function edit(Request $request): Response
    {
        $this->auth->authorize('editor.use');
        $type = (string)$request->param('type');
        $id = (int)$request->param('id');
        if (!in_array($type, self::TYPES, true)) {
            return Response::html('<h1>404 — Onbekend type</h1>', 404);
        }
        $item = $this->load($type, $id);
        if ($item === null) {
            return Response::html('<h1>404 — Niet gevonden</h1>', 404);
        }
        if (!$this->canEdit($type, $item)) {
            return Response::html('<h1>403 — Geen toegang tot dit item</h1>', 403);
        }

        $canPhp = $this->auth->can('editor.markup.php');
        $isBlog = $type === 'blog';
        $initial = $isBlog ? (string)$item['content'] : (string)($item['content_markup'] ?? $item['content'] ?? '');
        $draft = $this->findDraft($type, $id);
        $draftText = null;
        if ($draft !== null && $draft['content'] !== $initial) {
            $draftText = $draft['content'];
            $draftAt = $draft['updated_at'];
        }
        $toolbar = $isBlog ? [] : $this->toolbar($type, $canPhp);
        $csrf = CsrfProtection::getToken();
        $config = [
            'type' => $type, 'id' => $id, 'canPhp' => $canPhp, 'plainOnly' => $isBlog,
            'toolbar' => $toolbar, 'draft' => $draftText, 'draftAt' => $draftAt ?? null,
            'maxBytes' => $isBlog ? self::BLOG_MAX_BYTES : $this->renderer()->maxBytes(),
        ];
        $title = (string)$item['title'];
        $backUrl = match ($type) {
            'page' => '/admin/pages/' . $id . '/bewerk',
            'news' => '/admin/news/' . $id . '/bewerk',
            default => '/blog',
        };

        ob_start();
        include __DIR__ . '/views/editor.php';
        return Response::html((string)ob_get_clean());
    }

    // ── API ──────────────────────────────────────────────────────────────

    public function preview(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $this->auth->authorize('editor.use');
        if ($this->limiter()->tooMany('editor.preview.' . $this->auth->id(), self::PREVIEWS_PER_MINUTE)) {
            return Response::json(['error' => 'Te veel previews; wacht even.'], 429);
        }

        $type = (string)$request->input('type', 'page');
        $markup = $request->input('markup', '');
        if (!in_array($type, self::TYPES, true) || !is_string($markup)) {
            return Response::json(['error' => 'Ongeldige invoer.'], 422);
        }

        try {
            $html = $this->renderBody($type, $markup, (string)$request->input('title', ''));
        } catch (MarkupException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        } catch (\DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        }
        return Response::json(['html' => $this->previewDocument($html)]);
    }

    public function draft(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $this->auth->authorize('editor.use');
        if ($this->limiter()->tooMany('editor.draft.' . $this->auth->id(), self::DRAFTS_PER_MINUTE)) {
            return Response::json(['error' => 'Te veel opslagacties; wacht even.'], 429);
        }

        $type = (string)$request->input('type', '');
        $id = (int)$request->input('id', 0);
        $content = $request->input('content', '');
        if (!in_array($type, self::TYPES, true) || $id < 1 || !is_string($content)) {
            return Response::json(['error' => 'Ongeldige invoer.'], 422);
        }
        $item = $this->load($type, $id);
        if ($item === null || !$this->canEdit($type, $item)) {
            return Response::json(['error' => 'Geen toegang.'], 403);
        }
        if (strlen($content) > ($type === 'blog' ? self::BLOG_MAX_BYTES : $this->renderer()->maxBytes())) {
            return Response::json(['error' => 'De inhoud is te groot.'], 413);
        }

        $this->db->execute(
            'INSERT INTO cf_editor_drafts (user_id, target_type, target_id, content) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE content = VALUES(content), updated_at = NOW()',
            [$this->auth->id(), $type, $id, $content]
        );
        return Response::json(['ok' => true, 'saved_at' => date('H:i:s')]);
    }

    public function save(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $this->auth->authorize('editor.use');
        if ($this->limiter()->tooMany('editor.save.' . $this->auth->id(), self::SAVES_PER_MINUTE)) {
            return Response::json(['error' => 'Te veel opslagacties; wacht even.'], 429);
        }

        $type = (string)$request->param('type');
        $id = (int)$request->param('id');
        $markup = $request->input('markup', null);
        if (!in_array($type, self::TYPES, true) || !is_string($markup)) {
            return Response::json(['error' => 'Ongeldige invoer.'], 422);
        }
        $item = $this->load($type, $id);
        if ($item === null) {
            return Response::json(['error' => 'Niet gevonden.'], 404);
        }
        if (!$this->canEdit($type, $item)) {
            return Response::json(['error' => 'Geen toegang tot dit item.'], 403);
        }

        try {
            $html = $this->renderBody($type, $markup, (string)$item['title']);
        } catch (MarkupException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        } catch (\DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 403);
        }

        $hasPhp = $type !== 'blog' && $this->renderer()->containsPhp($markup);
        match ($type) {
            'page' => $this->pages->update($id, ['content' => $html, 'content_markup' => $markup]),
            'news' => $this->news->update($id, ['content' => $html, 'content_markup' => $markup]),
            'blog' => $this->blog->updateContent($id, $markup),
        };
        $this->db->execute(
            'DELETE FROM cf_editor_drafts WHERE user_id = ? AND target_type = ? AND target_id = ?',
            [$this->auth->id(), $type, $id]
        );
        $this->audit->log('editor.save', $this->auth->id(), (string)($this->auth->user()['username'] ?? ''), [
            'type' => $type, 'id' => $id, 'bytes' => strlen($markup), 'php_tags' => $hasPhp,
        ]);

        return Response::json(['ok' => true, 'saved_at' => date('H:i:s'), 'php_tags' => $hasPhp]);
    }

    // ── Intern ───────────────────────────────────────────────────────────

    /**
     * Bron → HTML. Pagina/nieuws: Twig-sandbox. Blog: platte tekst (escapen + regeleinden, geen markup).
     *
     * @throws MarkupException bij een fout in de markup
     * @throws \DomainException als de gebruiker PHP-tags gebruikt zonder editor.markup.php
     */
    private function renderBody(string $type, string $markup, string $title): string
    {
        if ($type === 'blog') {
            if (strlen($markup) > self::BLOG_MAX_BYTES) {
                throw new MarkupException('De inhoud is te groot (maximaal 100 KB).');
            }
            return nl2br(htmlspecialchars($markup, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }
        $renderer = $this->renderer();
        if ($renderer->containsPhp($markup) && !$this->auth->can('editor.markup.php')) {
            throw new \DomainException('PHP-tags zijn niet toegestaan voor jouw rol (recht editor.markup.php).');
        }
        // Veilige context: geen auth, settings, zones of objecten
        $context = ['title' => $title, 'today' => date('Y-m-d')];
        return $renderer->render($markup, $context);
    }

    /** Volledig document voor <iframe sandbox srcdoc>: eigen CSS inline en een strikte CSP. */
    private function previewDocument(string $bodyHtml): string
    {
        $cssFile = (defined('CF_ROOT') ? CF_ROOT : dirname(__DIR__, 3)) . '/public/assets/css/cf-prose.css';
        $css = is_file($cssFile) ? (string)file_get_contents($cssFile) : '';
        $css = str_replace('</style', '<\/style', $css);
        return '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src https: data:; font-src https: data:; media-src https: data:">'
            . '<base target="_blank"><style>' . $css . '</style></head><body class="cf-prose">' . $bodyHtml . '</body></html>';
    }

    /** @return array<string,mixed>|null */
    private function load(string $type, int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        return match ($type) {
            'page' => $this->pages->findById($id),
            'news' => $this->news->findById($id),
            'blog' => $this->blog->findById($id),
            default => null,
        };
    }

    /** @param array<string,mixed> $item */
    private function canEdit(string $type, array $item): bool
    {
        return match ($type) {
            'page' => $this->auth->can('pages.manage'),
            'news' => $this->auth->can('news.create'),
            'blog' => (int)$item['author_id'] === $this->auth->id() || $this->auth->can('blog.moderate'),
            default => false,
        };
    }

    /** @return array{content:string,updated_at:string}|null */
    private function findDraft(string $type, int $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT content, updated_at FROM cf_editor_drafts WHERE user_id = ? AND target_type = ? AND target_id = ?',
            [$this->auth->id(), $type, $id]
        );
        return $row === null ? null : ['content' => (string)$row['content'], 'updated_at' => (string)$row['updated_at']];
    }

    /**
     * Knoppenbalk: standaardknoppen + (via filter editor.toolbar.register) knoppen van plugins.
     * Plugins leveren alleen platte invoeg-tekst; er wordt geen plugin-JavaScript uitgevoerd.
     *
     * @return list<array{id:string,label:string,title:string,before:string,after:string}>
     */
    private function toolbar(string $type, bool $canPhp): array
    {
        $buttons = [
            ['id' => 'strong', 'label' => 'B', 'title' => 'Vet', 'before' => '<strong>', 'after' => '</strong>'],
            ['id' => 'em', 'label' => 'I', 'title' => 'Cursief', 'before' => '<em>', 'after' => '</em>'],
            ['id' => 'a', 'label' => 'Link', 'title' => 'Link', 'before' => '<a href="">', 'after' => '</a>'],
            ['id' => 'ul', 'label' => '• Lijst', 'title' => 'Lijst', 'before' => "<ul>\n  <li>", 'after' => "</li>\n</ul>"],
            ['id' => 'ol', 'label' => '1. Lijst', 'title' => 'Genummerde lijst', 'before' => "<ol>\n  <li>", 'after' => "</li>\n</ol>"],
            ['id' => 'li', 'label' => 'li', 'title' => 'Lijstitem', 'before' => '<li>', 'after' => '</li>'],
            ['id' => 'code', 'label' => '</>', 'title' => 'Code (inline)', 'before' => '<code>', 'after' => '</code>'],
            ['id' => 'pre', 'label' => 'pre', 'title' => 'Codeblok', 'before' => '<pre>', 'after' => '</pre>'],
            ['id' => 'blockquote', 'label' => '❝', 'title' => 'Citaat', 'before' => '<blockquote>', 'after' => '</blockquote>'],
            ['id' => 'twig-var', 'label' => '{{ }}', 'title' => 'Twig-variabele', 'before' => '{{ ', 'after' => ' }}'],
            ['id' => 'twig-tag', 'label' => '{% %}', 'title' => 'Twig-tag', 'before' => '{% ', 'after' => ' %}'],
            ['id' => 'twig-comment', 'label' => '{# #}', 'title' => 'Twig-commentaar', 'before' => '{# ', 'after' => ' #}'],
        ];
        if ($canPhp) {
            $buttons[] = ['id' => 'php', 'label' => '<?php ?>', 'title' => 'PHP-tags (worden niet uitgevoerd)', 'before' => '<?php ', 'after' => ' ?>'];
        }

        $filtered = $this->hooks->applyFilters('editor.toolbar.register', $buttons, ['type' => $type]);
        $clean = [];
        $seen = [];
        foreach (is_array($filtered) ? $filtered : [] as $b) {
            if (!is_array($b) || !isset($b['id'], $b['label'], $b['before']) || !is_string($b['id']) || !is_string($b['label']) || !is_string($b['before'])
                || !preg_match('/^[a-z0-9][a-z0-9_-]{0,40}$/', $b['id']) || isset($seen[$b['id']])) {
                continue;
            }
            $after = isset($b['after']) && is_string($b['after']) ? $b['after'] : '';
            $title = isset($b['title']) && is_string($b['title']) ? $b['title'] : $b['label'];
            if (mb_strlen($b['label']) > 20 || mb_strlen($title) > 80 || strlen($b['before']) > 500 || strlen($after) > 500) {
                continue;
            }
            // PHP-tags in een knop: alleen voor wie ze mag gebruiken (server-side; de save-gate geldt sowieso)
            if (!$canPhp && (str_contains($b['before'], '<?') || str_contains($after, '<?'))) {
                continue;
            }
            $seen[$b['id']] = true;
            $clean[] = ['id' => $b['id'], 'label' => $b['label'], 'title' => $title, 'before' => $b['before'], 'after' => $after];
        }
        return $clean;
    }

    private function renderer(): MarkupRenderer
    {
        return $this->renderer ?? new MarkupRenderer();
    }

    private function limiter(): RateLimiter
    {
        return new RateLimiter($this->cache);
    }
}
