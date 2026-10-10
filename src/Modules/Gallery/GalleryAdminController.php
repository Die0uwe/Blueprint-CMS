<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Gallery;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Core\Storage\UploadManager;
use CommunityFusion\Core\Storage\UploadException;

/**
 * /admin/gallery — Media-galerijbeheer (S11).
 *
 * Albums = cf_categories(type=gallery), beheerd naar het bewezen
 * BoardAdminController-patroon (incl. bescherming tegen het per ongeluk
 * cascade-verwijderen van items — zie delete()). Item-upload volgt het
 * DownloadsController-patroon (eigen UploadManager-instantie, want de
 * container kent maar één UploadManager-binding — zie UploadManager.php),
 * met als extra stap: voor afbeeldingen ook een miniatuur genereren via
 * GalleryThumbnailer (video-items krijgen bewust geen miniatuur, zie
 * GalleryThumbnailer's klassecommentaar).
 *
 * BEWUSTE SCOPE-KEUZE: alleen staff met `gallery.manage` kan albums en
 * items beheren — geen lid-uploads/moderatiewachtrij in deze wave (zelfde
 * "staff-curated" model als Downloads/News, in tegenstelling tot Blog waar
 * elk lid zijn eigen content beheert). Ook geen per-item publish-toggle in
 * de UI (de `is_published`-kolom bestaat voor toekomstig gebruik, maar
 * S11 upload = altijd direct zichtbaar; verwijderen is het enige
 * zichtbaarheidscommando in deze wave).
 */
final class GalleryAdminController
{
    private const STORAGE_SUBDIR = 'gallery';
    private const MAX_BYTES      = 25 * 1024 * 1024; // 25MB — ruimer dan afbeeldingen (5MB) i.v.m. video

    /** @var array<string, string> extensie => media_type */
    private const EXTENSION_TYPES = [
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image',
        'mp4' => 'video', 'webm' => 'video',
    ];

    private UploadManager $uploads;

    /** Max. aantal bestanden per upload-actie. */
    public const MAX_BATCH = 20;

    public function __construct(
        private readonly GalleryRepository  $repo,
        private readonly AuthManager        $auth,
        private readonly AuditLogger        $audit,
        private readonly GalleryThumbnailer $thumbnailer,
        private readonly Connection         $db,
    ) {
        // Geworteld op storage/uploads/ (NIET .../gallery/) — store() krijgt
        // 'gallery' zelf al als subdir mee (zie upload()). Bug gevonden tijdens
        // livetest: was eerst dubbel geneste 'gallery/gallery/xxx.jpg' omdat
        // beide de root ÉN de subdir-parameter 'gallery' bevatten. Deze root
        // moet gelijk zijn aan de DI-singleton UploadManager's root, want
        // /media/{path} (MediaController) en MediaAdminController::isReferenced()
        // resolven galerijbestanden allebei relatief aan storage/uploads/.
        $this->uploads = UploadManager::forGallery(
            CF_ROOT . '/storage/uploads',
            self::MAX_BYTES,
        );
    }

    // ─── ALBUMS ─────────────────────────────────────────────────────────────

    /** GET /admin/gallery */
    public function index(Request $request): Response
    {
        $albums     = $this->repo->getAllAlbumsForAdmin();
        $duplicates = $this->repo->findDuplicateGroups();
        $taxonomy   = $this->repo->supportsTaxonomy();
        $mainSlugs  = array_keys(GalleryTaxonomy::MAIN);
        $flash  = $request->query('ok');
        $error  = $request->query('error');

        ob_start();
        include __DIR__ . '/views/admin_index.php';
        return Response::html(ob_get_clean());
    }

    /** GET /admin/gallery/nieuw */
    public function createForm(Request $request): Response
    {
        $album   = null;
        $parents = $this->repo->getParentCandidates();
        $error   = $request->query('error');

        ob_start();
        include __DIR__ . '/views/admin_album_form.php';
        return Response::html(ob_get_clean());
    }

    /** POST /admin/gallery */
    public function store(Request $request): Response
    {
        CsrfProtection::validateRequest();
        return $this->save($request, null);
    }

    /** GET /admin/gallery/{id}/beheer — album bewerken + items uploaden/beheren */
    public function manage(Request $request): Response
    {
        $id    = (int) $request->param('id');
        $album = $this->repo->findAlbumById($id);

        if ($album === null) {
            return Response::html('<h1>404 — Album niet gevonden</h1>', 404);
        }

        $items       = $this->repo->getAllItemsForAdmin($id);
        $parents     = $this->repo->getParentCandidates($id);
        $hasChildren = $this->repo->hasSubalbums($id);
        $taxonomy    = $this->repo->supportsTaxonomy();
        $styles      = $this->stylesForAlbum($album);
        $mergeTargets = array_values(array_filter(
            $this->repo->getAllAlbumsForAdmin(),
            static fn(array $a) => (int) $a['id'] !== $id
        ));
        $error = $request->query('error');
        $flash = $request->query('ok');

        ob_start();
        include __DIR__ . '/views/admin_album_manage.php';
        return Response::html(ob_get_clean());
    }

    /** POST /admin/gallery/{id}/bewerk */
    public function update(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $id = (int) $request->param('id');
        return $this->save($request, $id);
    }

    /** POST /admin/gallery/{id}/verwijder */
    public function delete(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $id    = (int) $request->param('id');
        $album = $this->repo->findAlbumById($id);

        if ($album === null) {
            return Response::html('<h1>404 — Album niet gevonden</h1>', 404);
        }

        if ($this->repo->hasSubalbums($id)) {
            return Response::redirect('/admin/gallery?error=' . urlencode(
                "Album \"{$album['name']}\" heeft nog subalbums — verwijder of verplaats die eerst."
            ));
        }

        if ($this->repo->countItemsInAlbum($id) > 0) {
            return Response::redirect('/admin/gallery?error=' . urlencode(
                "Album \"{$album['name']}\" bevat nog foto's/video's — verwijder die eerst."
            ));
        }

        $this->repo->deleteAlbum($id);
        $this->logAction('gallery.album.delete', ['album_id' => $id, 'name' => $album['name']]);
        return Response::redirect('/admin/gallery?ok=verwijderd');
    }

    // ─── ITEMS ──────────────────────────────────────────────────────────────

    /** POST /admin/gallery/{id}/upload */
    public function upload(Request $request): Response
    {
        $albumId = (int) $request->param('id');

        // Is de upload groter dan post_max_size, dan leegt PHP $_POST én $_FILES — de CSRF-
        // controle zou dan met een misleidende 403 falen. Herken dat eerst en leg het uit.
        if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            return Response::redirect("/admin/gallery/{$albumId}/beheer?error=" . urlencode(
                'Het bestand is groter dan wat de server toestaat (post_max_size = ' . (ini_get('post_max_size') ?: '?')
                . ', upload_max_filesize = ' . (ini_get('upload_max_filesize') ?: '?') . '). Verhoog die limieten of kies een kleiner bestand.'
            ));
        }

        CsrfProtection::validateRequest();
        $album   = $this->repo->findAlbumById($albumId);

        if ($album === null) {
            return Response::html('<h1>404 — Album niet gevonden</h1>', 404);
        }

        // Tot MAX_BATCH bestanden per keer: velden file_0..file_N met eigen title_N / description_N / poster_data_N.
        // Het oude enkelvoudige veld "file" (title, description, poster_data) blijft werken.
        $files = $request->files();
        $slots = [];
        for ($n = 0; $n < self::MAX_BATCH; $n++) {
            if (isset($files["file_{$n}"]) && $this->hasUpload($files["file_{$n}"])) {
                $slots[] = [
                    'file'  => $files["file_{$n}"],
                    'title' => trim((string) $request->input("title_{$n}", '')),
                    'desc'  => trim((string) $request->input("description_{$n}", '')),
                    'poster' => (string) $request->input("poster_data_{$n}", ''),
                ];
            }
        }
        if ($slots === [] && isset($files['file']) && $this->hasUpload($files['file'])) {
            $slots[] = [
                'file'  => $files['file'],
                'title' => trim((string) $request->input('title', '')),
                'desc'  => trim((string) $request->input('description', '')),
                'poster' => (string) $request->input('poster_data', ''),
            ];
        }
        if ($slots === []) {
            return Response::redirect("/admin/gallery/{$albumId}/beheer?error=" . urlencode('Geen bestand geselecteerd.'));
        }

        // Stijl + tags gelden voor alle bestanden in deze upload (per item later aan te passen).
        $styles = $this->stylesForAlbum($album);
        $style  = GalleryTaxonomy::slugify((string) $request->input('style', ''));
        if ($style !== '' && !GalleryTaxonomy::isAllowedStyle($this->mainSlugFor($album), $style)) {
            return Response::redirect("/admin/gallery/{$albumId}/beheer?error=" . urlencode(
                'Onbekende stijl "' . $style . '" voor dit album. Kies er een uit de lijst.'
            ));
        }
        $tags = GalleryTaxonomy::packTags(GalleryTaxonomy::parseTags((string) $request->input('tags', '')));
        $seq  = $this->repo->countItemsInAlbum($albumId);

        $ok = 0;
        $errors = [];
        foreach ($slots as $slot) {
            $err = $this->storeOne($albumId, $slot['file'], $slot['title'], $slot['desc'], $slot['poster'], $album, $style, $tags, ++$seq);
            if ($err === null) {
                $ok++;
            } else {
                $errors[] = (string) ($slot['file']['name'] ?? 'bestand') . ': ' . $err;
            }
        }

        if ($errors !== []) {
            $msg = ($ok > 0 ? "{$ok} geüpload, " : '') . count($errors) . ' mislukt — ' . implode(' | ', $errors);
            return Response::redirect("/admin/gallery/{$albumId}/beheer?error=" . urlencode(mb_substr($msg, 0, 600)));
        }
        return Response::redirect("/admin/gallery/{$albumId}/beheer?ok=" . ($ok > 1 ? 'geupload_n&n=' . $ok : 'geupload'));
    }

    /** Is dit een echt gekozen bestand (geen lege file-input)? */
    private function hasUpload(mixed $f): bool
    {
        return is_array($f) && (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && (string) ($f['name'] ?? '') !== '';
    }

    /** Verwerk één bestand. Geeft null bij succes, anders de foutmelding. */
    private function storeOne(int $albumId, array $file, string $title, string $description, string $posterData, array $album, string $style, ?string $tags, int $sequence): ?string
    {
        try {
            $relative = $this->uploads->store($file, self::STORAGE_SUBDIR);
        } catch (UploadException $e) {
            return $e->getMessage();
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $mediaType = self::EXTENSION_TYPES[$extension] ?? 'image';

        $thumbnailRelative = null;
        $width             = null;
        $height            = null;

        if ($mediaType === 'image') {
            $sourceAbs = $this->uploads->resolve($relative);
            $thumbBase = pathinfo($relative, PATHINFO_FILENAME) . '.jpg';
            $thumbRel  = self::STORAGE_SUBDIR . '/thumbs/' . $thumbBase;
            $thumbAbs  = CF_ROOT . '/storage/uploads/' . $thumbRel;

            if ($sourceAbs !== null) {
                $dims = $this->thumbnailer->generate($sourceAbs, $thumbAbs);
                if ($dims !== null) {
                    $width  = $dims['width'];
                    $height = $dims['height'];
                    if (is_file($thumbAbs)) {
                        $thumbnailRelative = $thumbRel;
                    }
                }
            }
        }

        if ($mediaType === 'video') {
            $posterRel = $this->storePoster($posterData, $relative);
            if ($posterRel !== null) {
                $thumbnailRelative = $posterRel;
            }
        }

        $itemId = $this->repo->createItem(
            albumId:          $albumId,
            authorId:         (int) $this->auth->id(),
            mediaType:        $mediaType,
            title:             $title,
            description:       $description,
            filePath:          $relative,
            thumbnailPath:     $thumbnailRelative,
            originalFilename:  $this->conventionalName($album, $style, $title, $sequence, $file),
            fileSize:          (int) ($file['size'] ?? 0),
            width:             $width,
            height:            $height,
            style:             $style !== '' ? $style : null,
            tags:              $tags,
        );

        $this->logAction('gallery.item.upload', [
            'item_id' => $itemId, 'album_id' => $albumId, 'media_type' => $mediaType,
        ]);
        return null;
    }

    /** POST /admin/gallery/items/{itemId}/verwijder */
    public function deleteItem(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $itemId = (int) $request->param('itemId');
        $item   = $this->repo->findItemById($itemId);

        if ($item === null) {
            return Response::html('<h1>404 — Item niet gevonden</h1>', 404);
        }

        $this->repo->deleteItem($itemId);
        $this->uploads->delete($item['file_path']);
        if (!empty($item['thumbnail_path'])) {
            $this->uploads->delete($item['thumbnail_path']);
        }

        $this->logAction('gallery.item.delete', ['item_id' => $itemId, 'album_id' => $item['album_id']]);

        return Response::redirect("/admin/gallery/{$item['album_id']}/beheer?ok=verwijderd");
    }

    /** POST /admin/gallery/items/{itemId}/bewerk — titel, omschrijving, stijl en tags */
    public function updateItem(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $itemId = (int) $request->param('itemId');
        $item   = $this->repo->findItemById($itemId);

        if ($item === null) {
            return Response::html('<h1>404 — Item niet gevonden</h1>', 404);
        }

        $album = $this->repo->findAlbumById((int) $item['album_id']);
        $back  = "/admin/gallery/{$item['album_id']}/beheer";
        $style = GalleryTaxonomy::slugify((string) $request->input('style', ''));
        if ($album !== null && $style !== '' && !GalleryTaxonomy::isAllowedStyle($this->mainSlugFor($album), $style)) {
            return Response::redirect($back . '?error=' . urlencode('Onbekende stijl "' . $style . '" voor dit album.'));
        }

        $this->repo->updateItem(
            $itemId,
            mb_substr(trim((string) $request->input('title', '')), 0, 255),
            trim((string) $request->input('description', '')),
            $style,
            GalleryTaxonomy::packTags(GalleryTaxonomy::parseTags((string) $request->input('tags', ''))),
        );
        $this->logAction('gallery.item.update', ['item_id' => $itemId, 'album_id' => $item['album_id']]);
        return Response::redirect($back . '?ok=item_bijgewerkt');
    }

    /** POST /admin/gallery/{id}/samenvoegen — verplaatst alles naar een ander album en verwijdert dit album */
    public function merge(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $fromId = (int) $request->param('id');
        $intoId = (int) $request->input('target_id', 0);
        $from   = $this->repo->findAlbumById($fromId);
        $into   = $this->repo->findAlbumById($intoId);

        if ($from === null || $into === null || $fromId === $intoId) {
            return Response::redirect('/admin/gallery?error=' . urlencode('Kies een ander, bestaand album om mee samen te voegen.'));
        }
        // Een album kan niet in zijn eigen subalbum opgaan (dan hangt de boom los).
        if ($into['parent_id'] !== null && (int) $into['parent_id'] === $fromId) {
            return Response::redirect("/admin/gallery/{$fromId}/beheer?error=" . urlencode('Je kunt een album niet samenvoegen met een van zijn eigen subalbums.'));
        }

        $moved = $this->repo->mergeAlbums($fromId, $intoId);
        $this->logAction('gallery.album.merge', ['from' => $fromId, 'into' => $intoId, 'items_moved' => $moved]);
        return Response::redirect("/admin/gallery/{$intoId}/beheer?ok=samengevoegd&n={$moved}");
    }

    /** POST /admin/gallery/ontdubbel — voegt alle dubbele albums automatisch samen en zaait de hoofdcategorieën */
    public function dedupe(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $report = (new GalleryDeduplicator($this->db->getPdo(), $this->db->getPrefix()))->run();
        $this->cacheClear();

        $merged = count($report['merged']);
        $this->logAction('gallery.dedupe', ['merged_groups' => $merged, 'seeded' => $report['seeded']]);
        return Response::redirect('/admin/gallery?ok=ontdubbeld&n=' . $merged . '&s=' . count($report['seeded']));
    }

    /** GET /admin/gallery/export.json — alle items in de JSON-structuur van het taxonomie-concept */
    public function exportJson(Request $request): Response
    {
        return Response::json(['items' => $this->repo->exportItems()]);
    }

    // ─── HELPERS ────────────────────────────────────────────────────────────

    private function cacheClear(): void
    {
        // Repository wist de cache bij elke mutatie; een lege update bereikt dat zonder extra afhankelijkheid.
        $this->repo->flushCache();
    }

    /** Slug van de hoofdcategorie waar dit album onder valt (zichzelf als het top-level is). */
    private function mainSlugFor(array $album): string
    {
        if ($album['parent_id'] !== null) {
            $parent = $this->repo->findAlbumById((int) $album['parent_id']);
            if ($parent !== null) {
                return (string) $parent['slug'];
            }
        }
        return (string) $album['slug'];
    }

    /** @return array<string,string> toegestane stijlen (slug => label) voor dit album; leeg = vrij */
    private function stylesForAlbum(array $album): array
    {
        return GalleryTaxonomy::stylesFor($this->mainSlugFor($album));
    }

    /**
     * Bestandsnaam volgens [categorie]_[stijl]_[onderwerp]_[nn].ext. Zonder stijl
     * en titel blijft de originele bestandsnaam staan (geen betekenisloze naam bedenken).
     */
    private function conventionalName(array $album, string $style, string $title, int $sequence, array $file): string
    {
        $original = (string) ($file['name'] ?? 'bestand');
        if ($style === '' && $title === '') {
            return $original;
        }
        $subject = $title !== '' ? $title : pathinfo($original, PATHINFO_FILENAME);
        return GalleryTaxonomy::conventionalFilename(
            $this->mainSlugFor($album), $style, $subject, $sequence, pathinfo($original, PATHINFO_EXTENSION)
        );
    }

    /** Poster opslaan naast de miniaturen; relatief pad of null (ongeldig/geen poster). */
    private function storePoster(string $dataUrl, string $videoRelative): ?string
    {
        $name = GalleryPoster::save(
            $dataUrl,
            CF_ROOT . '/storage/uploads/' . self::STORAGE_SUBDIR . '/thumbs',
            pathinfo($videoRelative, PATHINFO_FILENAME)
        );
        return $name !== null ? self::STORAGE_SUBDIR . '/thumbs/' . $name : null;
    }

    private function logAction(string $action, array $context): void
    {
        $this->audit->log($action, $this->auth->id(), $this->auth->user()['username'] ?? null, $context);
    }

    private function save(Request $request, ?int $id): Response
    {
        $name        = trim((string) $request->input('name', ''));
        $description = trim((string) $request->input('description', ''));
        $position    = (int) $request->input('position', 0);
        $slugInput   = trim((string) $request->input('slug', ''));
        $parentRaw   = (int) $request->input('parent_id', 0);
        $parentId    = $parentRaw > 0 ? $parentRaw : null;
        $target      = $id !== null ? "/admin/gallery/{$id}/beheer" : '/admin/gallery/nieuw';

        if ($name === '') {
            return Response::redirect($target . '?error=' . urlencode('Naam is verplicht.'));
        }

        // Subalbums: één niveau diep, ouder moet een bestaand top-level album zijn.
        if ($parentId !== null) {
            $parent = $this->repo->findAlbumById($parentId);
            if ($parent === null || $parent['parent_id'] !== null || $parentId === $id) {
                return Response::redirect($target . '?error=' . urlencode('Ongeldig bovenliggend album (max. één niveau subalbums).'));
            }
            if ($id !== null && $this->repo->hasSubalbums($id)) {
                return Response::redirect($target . '?error=' . urlencode('Dit album heeft zelf subalbums en kan dus geen subalbum worden.'));
            }
        }

        // Geen dubbele categorieën: "3D Art" bestaat al als "3d-art"? Dan geen tweede aanmaken.
        $existing = $this->repo->findAlbumByName($name, $parentId, $id);
        if ($existing !== null) {
            return Response::redirect($target . '?error=' . urlencode(
                "Er bestaat al een album \"{$existing['name']}\" op dit niveau — kies een andere naam of voeg ze samen."
            ));
        }

        $slug = $this->slugify($slugInput !== '' ? $slugInput : $name);
        if ($slug === '') {
            $slug = 'album-' . bin2hex(random_bytes(3));
        }
        // Slug bezet door een ander album (ander niveau/andere naam): oplopend nummer i.p.v. willekeurige hex.
        $base = $slug;
        for ($n = 2; $this->repo->albumSlugTaken($slug, $id); $n++) {
            $slug = $base . '-' . $n;
        }

        if ($id === null) {
            $newId = $this->repo->createAlbum($slug, $name, $description, $position, $parentId);
            $this->logAction('gallery.album.create', ['album_id' => $newId, 'slug' => $slug, 'name' => $name, 'parent_id' => $parentId]);
            return Response::redirect('/admin/gallery?ok=aangemaakt');
        }

        $album = $this->repo->findAlbumById($id);
        if ($album === null) {
            return Response::html('<h1>404 — Album niet gevonden</h1>', 404);
        }

        $this->repo->updateAlbum($id, $slug, $name, $description, $position, $parentId);
        $this->logAction('gallery.album.update', ['album_id' => $id, 'slug' => $slug, 'name' => $name, 'parent_id' => $parentId]);
        return Response::redirect("/admin/gallery/{$id}/beheer?ok=bijgewerkt");
    }

    private function slugify(string $text): string
    {
        return GalleryTaxonomy::slugify($text);
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: GalleryAdminController.php | Role: Core | Version: 1.1.0     ║
// ║  Created: 2026-09-29 | Status: Updated — Galerij-taxonomie 1.35.0   ║
// ╚══════════════════════════════════════════════════════════════════════╝
