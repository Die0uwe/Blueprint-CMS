<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Modules;

use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Modules\Gallery\GalleryDeduplicator;
use CommunityFusion\Modules\Gallery\GalleryRepository;
use CommunityFusion\Modules\Gallery\GalleryTaxonomy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Galerij-taxonomie: hoofdcategorieën, stijl-tags, duplicaat-detectie/-samenvoeging, subalbums, filters. */
final class GalleryTaxonomyTest extends TestCase
{
    private \PDO $pdo;
    private GalleryRepository $repo;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->pdo->exec("CREATE TABLE cf_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER NULL, type TEXT NOT NULL, slug TEXT NOT NULL, name TEXT NOT NULL, description TEXT NULL, position INTEGER NOT NULL DEFAULT 0)");
        $this->pdo->exec("CREATE TABLE cf_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT)");
        $this->pdo->exec("INSERT INTO cf_users VALUES (1, 'admin', 'Admin')");
        $this->items(true);

        $rc = new \ReflectionClass(Connection::class);
        $db = $rc->newInstanceWithoutConstructor();
        foreach (['pdo' => $this->pdo, 'prefix' => 'cf_'] as $prop => $val) {
            $rc->getProperty($prop)->setValue($db, $val);
        }
        $this->cacheDir = sys_get_temp_dir() . '/cf_galtax_' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0755, true);
        $this->repo = new GalleryRepository($db, new CacheManager(['driver' => 'file', 'path' => $this->cacheDir, 'ttl' => 60]));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cacheDir);
    }

    private function items(bool $withTaxonomy): void
    {
        $this->pdo->exec("DROP TABLE IF EXISTS cf_gallery_items");
        $extra = $withTaxonomy ? ", style TEXT NULL, tags TEXT NULL" : '';
        $this->pdo->exec("CREATE TABLE cf_gallery_items (id INTEGER PRIMARY KEY AUTOINCREMENT, album_id INTEGER NOT NULL, author_id INTEGER NOT NULL,
            media_type TEXT NOT NULL, title TEXT NULL, description TEXT NULL, file_path TEXT NOT NULL, thumbnail_path TEXT NULL,
            original_filename TEXT NOT NULL, file_size INTEGER NOT NULL DEFAULT 0, width INTEGER NULL, height INTEGER NULL,
            is_published INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NULL, deleted_at TEXT NULL {$extra})");
    }

    private function album(string $name, ?int $parent = null, ?string $slug = null): int
    {
        return $this->repo->createAlbum($slug ?? GalleryTaxonomy::slugify($name), $name, '', 0, $parent);
    }

    private function item(int $album, ?string $style = null, ?string $tags = null, string $title = 't'): int
    {
        return $this->repo->createItem($album, 1, 'image', $title, null, "gallery/{$title}.jpg", null, "{$title}.jpg", 10, 1, 1, $style, $tags);
    }

    // ── Taxonomie (pure regels) ─────────────────────────────────────────────

    #[Test]
    public function hasExactlyFiveMainCategoriesWithTheDocumentedStyles(): void
    {
        $this->assertCount(GalleryTaxonomy::MAX_MAIN, GalleryTaxonomy::MAIN);
        $this->assertSame(['3d-art', 'digital-paintings', 'illustrations', 'photorealistic', 'ui-graphics'], array_keys(GalleryTaxonomy::MAIN));
        $this->assertArrayHasKey('pixar', GalleryTaxonomy::stylesFor('3d-art'));
        $this->assertArrayHasKey('hud', GalleryTaxonomy::stylesFor('ui-graphics'));
        $this->assertSame([], GalleryTaxonomy::stylesFor('eigen-album'));
    }

    #[Test]
    public function treatsSpellingVariantsOfTheSameNameAsDuplicates(): void
    {
        $this->assertSame(GalleryTaxonomy::normalizeName('3D Art'), GalleryTaxonomy::normalizeName('3d-art'));
        $this->assertSame(GalleryTaxonomy::normalizeName('3D_ART '), GalleryTaxonomy::normalizeName('3D-Art'));
        $this->assertSame(GalleryTaxonomy::normalizeName('Café'), GalleryTaxonomy::normalizeName('cafe'));
        $this->assertNotSame(GalleryTaxonomy::normalizeName('Illustrations'), GalleryTaxonomy::normalizeName('Illustration'));
    }

    #[Test]
    public function validatesStylesAgainstTheMainCategory(): void
    {
        $this->assertTrue(GalleryTaxonomy::isAllowedStyle('3d-art', 'Pixar'));
        $this->assertTrue(GalleryTaxonomy::isAllowedStyle('3d-art', ''));
        $this->assertFalse(GalleryTaxonomy::isAllowedStyle('3d-art', 'watercolor'));
        $this->assertTrue(GalleryTaxonomy::isAllowedStyle('mijn-eigen-album', 'wat-dan-ook'));
    }

    #[Test]
    public function parsesTagsDedupedAndLimited(): void
    {
        $this->assertSame(['orc', 'avatar', 'green-skin'], GalleryTaxonomy::parseTags("Orc, avatar;ORC\nGreen Skin,, "));
        $this->assertCount(GalleryTaxonomy::MAX_TAGS, GalleryTaxonomy::parseTags(implode(',', range(1, 40))));
        $this->assertSame(',orc,avatar,', GalleryTaxonomy::packTags(['orc', 'avatar']));
        $this->assertNull(GalleryTaxonomy::packTags([]));
        $this->assertSame(['orc', 'avatar'], GalleryTaxonomy::unpackTags(',orc,avatar,'));
        $this->assertSame([], GalleryTaxonomy::unpackTags(null));
    }

    #[Test]
    public function buildsTheConventionalFilename(): void
    {
        $this->assertSame('3d_pixar_orc-warrior_01.jpg', GalleryTaxonomy::conventionalFilename('3d-art', 'pixar', 'Orc Warrior', 1, 'JPG'));
        $this->assertSame('photorealistic_macro_dauw_12.png', GalleryTaxonomy::conventionalFilename('photorealistic', 'Macro', 'Dauw!', 12, '.png'));
        $this->assertSame('ui_logo_01.webp', GalleryTaxonomy::conventionalFilename('ui-graphics', 'logo', '', 0, 'webp'));
    }

    // ── Deduplicator ────────────────────────────────────────────────────────

    #[Test]
    public function seedsOnlyTheMissingMainCategories(): void
    {
        $this->album('3D Art', null, '3d-art-legacy');   // andere slug, zelfde naam: niet nogmaals zaaien
        $seeded = (new GalleryDeduplicator($this->pdo))->seedMainCategories();

        $this->assertSame(['digital-paintings', 'illustrations', 'photorealistic', 'ui-graphics'], $seeded);
        $this->assertSame([], (new GalleryDeduplicator($this->pdo))->seedMainCategories(), 'tweede run is idempotent');
    }

    #[Test]
    public function mergesDuplicateAlbumsAndKeepsEveryItem(): void
    {
        $keep = $this->album('3D Art', null, '3d-art');
        $dupA = $this->album('3d-art', null, '3d-art-a1b2');
        $dupB = $this->album('3D_ART', null, '3d-art-c3d4');
        $other = $this->album('Illustrations');
        $this->item($keep, null, null, 'k');
        $this->item($dupA, null, null, 'a1');
        $this->item($dupA, null, null, 'a2');
        $this->item($dupB, null, null, 'b');
        $this->item($other, null, null, 'o');
        $sub = $this->album('Renders', $dupA);   // subalbum van een duplicaat verhuist mee

        $this->assertCount(1, $this->repo->findDuplicateGroups());

        $report = (new GalleryDeduplicator($this->pdo))->mergeDuplicates();

        $this->assertCount(1, $report);
        $this->assertSame($keep, $report[0]['kept']);
        $this->assertSame([$dupA, $dupB], $report[0]['removed']);
        $this->assertSame(3, $report[0]['items_moved']);
        $this->assertSame(4, $this->repo->countItemsInAlbum($keep));
        $this->assertSame(1, $this->repo->countItemsInAlbum($other));
        $this->assertSame($keep, (int) $this->repo->findAlbumById($sub)['parent_id']);
        $this->assertSame([], $this->repo->findDuplicateGroups());
        $this->assertSame([], (new GalleryDeduplicator($this->pdo))->mergeDuplicates(), 'idempotent');
    }

    #[Test]
    public function sameNameUnderDifferentParentsIsNotADuplicate(): void
    {
        $a = $this->album('3D-Art');
        $b = $this->album('Illustrations');
        $this->album('Character Renders', $a);
        $this->album('Character Renders', $b);

        $this->assertSame([], $this->repo->findDuplicateGroups());
        $this->assertSame([], (new GalleryDeduplicator($this->pdo))->mergeDuplicates());
        $this->assertNotNull($this->repo->findAlbumByName('character renders', $a));
        $this->assertNull($this->repo->findAlbumByName('Character Renders', null));
    }

    #[Test]
    public function mergesDuplicateSubalbumsThatEndUpUnderTheSameParent(): void
    {
        $keep = $this->album('3D-Art', null, '3d-art');
        $dup  = $this->album('3d art', null, '3d-art-x');
        $s1 = $this->album('Renders', $keep);
        $s2 = $this->album('renders', $dup);
        $this->item($s2, null, null, 'r');

        (new GalleryDeduplicator($this->pdo))->mergeDuplicates();

        $this->assertSame(1, $this->repo->countItemsInAlbum($s1));
        $this->assertNull($this->repo->findAlbumById($s2));
    }

    // ── Repository: boom, tellingen, merge, filters ─────────────────────────

    #[Test]
    public function buildsAnAdminTreeAndCountsSubalbumItemsInTheParentOnTheIndex(): void
    {
        $top = $this->album('3D-Art');
        $sub = $this->album('Character Renders', $top);
        $this->item($top, null, null, 'x');
        $this->item($sub, null, null, 'y');
        $this->item($sub, null, null, 'z');

        $tree = $this->repo->getAllAlbumsForAdmin();
        $this->assertSame([0, 1], array_column($tree, 'depth'));
        $this->assertSame('3D-Art', $tree[1]['parent_name']);

        $index = $this->repo->getAlbums();
        $this->assertCount(1, $index, 'subalbums staan niet los op de index');
        $this->assertSame(3, (int) $index[0]['item_count']);
        $this->assertSame(1, (int) $index[0]['subalbum_count']);
        $this->assertCount(1, $this->repo->getSubalbums($top));
        $this->assertTrue($this->repo->hasSubalbums($top));
        $this->assertSame([$top], array_map(static fn($r) => (int) $r['id'], $this->repo->getParentCandidates()));
        $this->assertSame([], $this->repo->getParentCandidates($top));
    }

    #[Test]
    public function mergeAlbumsMovesItemsAndSubalbums(): void
    {
        $from = $this->album('Oud');
        $into = $this->album('Nieuw');
        $sub  = $this->album('Kind', $from);
        $this->item($from, null, null, 'a');
        $this->item($from, null, null, 'b');

        $this->assertSame(2, $this->repo->mergeAlbums($from, $into));
        $this->assertNull($this->repo->findAlbumById($from));
        $this->assertSame(2, $this->repo->countItemsInAlbum($into));
        $this->assertSame($into, (int) $this->repo->findAlbumById($sub)['parent_id']);
        $this->assertSame(0, $this->repo->mergeAlbums($into, $into), 'samenvoegen met zichzelf doet niets');
    }

    #[Test]
    public function filtersPublishedItemsByStyleAndExactTag(): void
    {
        $a = $this->album('3D-Art', null, '3d-art');
        $this->item($a, 'pixar', GalleryTaxonomy::packTags(['orc', 'avatar']), 'one');
        $this->item($a, 'pixar', GalleryTaxonomy::packTags(['orcish']), 'two');
        $this->item($a, 'voxel', GalleryTaxonomy::packTags(['orc']), 'three');
        $this->item($a, null, null, 'four');

        $this->assertSame(['pixar' => 2, 'voxel' => 1], $this->repo->getStyleCounts($a));
        $this->assertSame(2, $this->repo->countPublishedItems($a, 'pixar'));
        $this->assertSame(2, $this->repo->countPublishedItems($a, null, 'orc'), 'tag "orc" matcht niet "orcish"');
        $this->assertSame(1, $this->repo->countPublishedItems($a, 'pixar', 'orc'));
        $this->assertSame(4, $this->repo->countPublishedItems($a));
        $titles = array_column($this->repo->getPublishedItems($a, 10, 0, 'voxel'), 'title');
        $this->assertSame(['three'], $titles);
    }

    #[Test]
    public function updateItemChangesStyleAndTagsAndExportMatchesTheConceptJson(): void
    {
        $top = $this->album('3D-Art', null, '3d-art');
        $sub = $this->album('Character Renders', $top);
        $id  = $this->item($sub, null, null, 'Orc Warrior');
        $this->repo->updateItem($id, 'Orc Warrior Avatar', 'groen', 'pixar', GalleryTaxonomy::packTags(['orc', 'avatar']));

        $row = $this->repo->exportItems()[0];
        $this->assertSame('img-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT), $row['id']);
        $this->assertSame('Orc Warrior Avatar', $row['title']);
        $this->assertSame('3D-Art', $row['category']);
        $this->assertSame('album-' . $top, $row['parent_album_id']);
        $this->assertSame('Character Renders', $row['sub_album']);
        $this->assertSame('pixar', $row['style']);
        $this->assertSame(['orc', 'avatar'], $row['tags']);
    }

    #[Test]
    public function keepsWorkingBeforeTheMigrationHasAddedStyleAndTagColumns(): void
    {
        $this->items(false);
        $a = $this->album('3D-Art');

        $this->assertFalse($this->repo->supportsTaxonomy());
        $id = $this->item($a, 'pixar', ',orc,', 'legacy');   // style/tags worden genegeerd i.p.v. een SQL-fout
        $this->assertGreaterThan(0, $id);
        $this->assertSame([], $this->repo->getStyleCounts($a));
        $this->assertSame(1, $this->repo->countPublishedItems($a, 'pixar', 'orc'));
        $this->repo->updateItem($id, 'nieuw', null, 'pixar', ',x,');
        $this->assertSame('nieuw', $this->repo->findItemById($id)['title']);
    }
}
