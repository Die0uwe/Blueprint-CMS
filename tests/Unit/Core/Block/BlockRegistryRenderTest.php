<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Block;

use CommunityFusion\Blocks\BlockInterface;
use CommunityFusion\Core\Block\BlockRegistry;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Een renderfout mag niet voor de hele cache-TTL blijven hangen. */
final class BlockRegistryRenderTest extends TestCase
{
    #[Test]
    public function renderErrorsAreNotCachedButSuccessIs(): void
    {
        $cache = new CacheManager(['driver' => 'file', 'path' => sys_get_temp_dir() . '/cf_br_' . bin2hex(random_bytes(4))]);
        $db    = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
        $reg   = new BlockRegistry($db, $cache);

        $block = new class implements BlockInterface {
            public int $calls = 0;
            public bool $fail = true;
            public function getSlug(): string { return 'flaky'; }
            public function getName(): string { return 'Flaky'; }
            public function getConfigSchema(): array { return []; }
            public function validateConfig(array $config): void {}
            public function render(array $config, array $context = []): string
            {
                $this->calls++;
                if ($this->fail) { throw new \RuntimeException('db weg'); }
                return '<p>ok</p>';
            }
            public function getCacheTtl(): int { return 300; }
        };
        $reg->register($block);
        $row = ['id' => 7, 'type_slug' => 'flaky', 'config' => '{}', 'title' => null, 'cache_ttl' => 300];

        $this->assertStringContainsString('Block render fout', $reg->renderBlock($row));
        $block->fail = false;
        $this->assertStringContainsString('<p>ok</p>', $reg->renderBlock($row));   // niet de gecachete fout
        $before = $block->calls;
        $this->assertStringContainsString('<p>ok</p>', $reg->renderBlock($row));
        $this->assertSame($before, $block->calls);                                 // nu wél uit cache
    }
}
