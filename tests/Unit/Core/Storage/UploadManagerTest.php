<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Storage;

use CommunityFusion\Core\Storage\UploadException;
use CommunityFusion\Core\Storage\UploadManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UploadManagerTest extends TestCase
{
    private string $storageDir;
    private UploadManager $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDir = sys_get_temp_dir() . '/bluprint-upload-test-' . bin2hex(random_bytes(6));
        $this->uploads     = new UploadManager($this->storageDir, maxBytes: 1024 * 1024, testMode: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageDir);
        parent::tearDown();
    }

    #[Test]
    public function storeAcceptsARealPngAndReturnsARandomFilename(): void
    {
        $file = $this->fakeUploadedPng();

        $relative = $this->uploads->store($file, 'avatars');

        self::assertStringStartsWith('avatars/', $relative);
        self::assertStringEndsWith('.png', $relative);
        self::assertFileExists($this->storageDir . '/' . $relative);
        self::assertNotSame('original-name-should-never-survive.png', basename($relative));
    }

    #[Test]
    public function storeRejectsATextFileDisguisedAsAnImage(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($tmp, '<?php echo "dit is geen plaatje"; ?>');

        $file = [
            'name'     => 'shell.jpg', // client claimt .jpg, maar is PHP-tekst
            'type'     => 'image/jpeg', // client claimt image/jpeg
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmp),
        ];

        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('niet toegestaan');

        try {
            $this->uploads->store($file);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function storeRejectsAFileOverTheSizeLimit(): void
    {
        $file = $this->fakeUploadedPng();
        $file['size'] = 2 * 1024 * 1024; // groter dan de 1MB-limiet uit setUp()

        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('MB');
        $this->uploads->store($file);
    }

    #[Test]
    public function storeRejectsAnUploadError(): void
    {
        $file = $this->fakeUploadedPng();
        $file['error'] = UPLOAD_ERR_PARTIAL;

        $this->expectException(UploadException::class);
        $this->uploads->store($file);
    }

    #[Test]
    public function resolveRejectsPathTraversalAttempts(): void
    {
        self::assertNull($this->uploads->resolve('../../config/config.php'));
        self::assertNull($this->uploads->resolve('avatars/../../../etc/passwd'));
    }

    #[Test]
    public function resolveReturnsNullForANonExistentFile(): void
    {
        self::assertNull($this->uploads->resolve('avatars/does-not-exist.png'));
    }

    #[Test]
    public function resolveReturnsTheRealPathForAStoredFile(): void
    {
        $relative = $this->uploads->store($this->fakeUploadedPng(), 'avatars');

        $resolved = $this->uploads->resolve($relative);

        self::assertNotNull($resolved);
        self::assertFileExists($resolved);
    }

    #[Test]
    public function deleteRemovesAStoredFile(): void
    {
        $relative = $this->uploads->store($this->fakeUploadedPng(), 'avatars');
        $full     = $this->storageDir . '/' . $relative;
        self::assertFileExists($full);

        $this->uploads->delete($relative);

        self::assertFileDoesNotExist($full);
    }

    /**
     * Bouwt een $_FILES-achtig array met een echte, minimale 1×1 PNG.
     * UploadManager draait hier in testMode (zie setUp()), wat alleen de
     * is_uploaded_file()/move_uploaded_file()-calls vervangt door
     * is_file()/copy() — buiten een echte HTTP multipart-request geeft
     * is_uploaded_file() altijd false, dus dat is niet zinvol te unit-testen.
     * Alle overige validatie (MIME-whitelist, grootte, upload-errors,
     * path-traversal) loopt door de echte code.
     */
    private function fakeUploadedPng(): array
    {
        // Minimale geldige 1×1 transparante PNG.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
        );
        $tmp = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($tmp, $png);

        return [
            'name'     => 'original-name-should-never-survive.png',
            'type'     => 'image/png',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmp),
        ];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = "{$dir}/{$item}";
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
