<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Marketplace;

/**
 * ZipInspector — keurt een ZIP goed of af VÓÓR er iets wordt uitgepakt.
 *
 * Weert: path traversal (../, backslash, drive-letters, null-bytes, absolute paden),
 * symlinks, zip-bombs (aantal, totale/individuele grootte, compressieratio),
 * verborgen bestanden en niet-toegestane bestandstypen (.phar, .phtml, .htaccess, ...).
 */
final class ZipInspector
{
    public const MAX_ENTRIES = 2000;
    public const MAX_TOTAL_BYTES = 52428800;   // 50 MB uitgepakt
    public const MAX_ENTRY_BYTES = 10485760;   // 10 MB per bestand
    public const MAX_RATIO = 100;              // uitgepakt / gecomprimeerd, vanaf 100 KB
    public const MAX_NAME_LENGTH = 255;

    /** Toegestane extensies (PHP hoort bij modules; uitvoering gebeurt pas na activatie). */
    public const ALLOWED_EXTENSIONS = [
        'php', 'twig', 'json', 'js', 'css', 'md', 'txt', 'sql', 'html',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2', 'ttf',
    ];

    /** Bestanden zonder extensie die we accepteren. */
    public const ALLOWED_PLAIN = ['LICENSE', 'README', 'CHANGELOG', 'NOTICE', 'AUTHORS', '.gitkeep'];

    /**
     * @return array{entries:int,bytes:int}
     * @throws PackageException
     */
    public function inspect(\ZipArchive $zip): array
    {
        $count = $zip->numFiles;
        if ($count < 1) {
            throw new PackageException('De ZIP is leeg.');
        }
        if ($count > self::MAX_ENTRIES) {
            throw new PackageException('De ZIP bevat te veel bestanden (maximaal ' . self::MAX_ENTRIES . ').');
        }

        $total = 0;
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new PackageException('Beschadigde ZIP (onleesbaar onderdeel).');
            }
            $name = (string)$stat['name'];
            $this->assertSafeName($name);

            if ($this->isSymlink($zip, $i)) {
                throw new PackageException("Symlink in ZIP geweigerd: {$this->show($name)}");
            }

            if (str_ends_with($name, '/')) {
                continue; // map
            }
            $this->assertAllowedFile($name);

            $size = (int)$stat['size'];
            $comp = (int)$stat['comp_size'];
            if ($size > self::MAX_ENTRY_BYTES) {
                throw new PackageException("Bestand te groot in ZIP: {$this->show($name)}");
            }
            if ($size > 102400 && $comp > 0 && intdiv($size, $comp) > self::MAX_RATIO) {
                throw new PackageException("Verdachte compressieratio (zip-bomb?): {$this->show($name)}");
            }
            $total += $size;
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new PackageException('De ZIP is uitgepakt te groot (maximaal 50 MB).');
            }
        }

        return ['entries' => $count, 'bytes' => $total];
    }

    private function assertSafeName(string $name): void
    {
        if ($name === '' || strlen($name) > self::MAX_NAME_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/', $name)
            || str_contains($name, '\\')
            || str_contains($name, ':')
            || str_starts_with($name, '/')) {
            throw new PackageException("Onveilige bestandsnaam in ZIP: {$this->show($name)}");
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new PackageException("Onveilig pad in ZIP: {$this->show($name)}");
            }
        }
    }

    private function assertAllowedFile(string $name): void
    {
        $base = basename($name);
        if (in_array($base, self::ALLOWED_PLAIN, true)) {
            return;
        }
        if ($base[0] === '.') {
            throw new PackageException("Verborgen bestand niet toegestaan: {$this->show($name)}");
        }
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new PackageException("Bestandstype niet toegestaan: {$this->show($name)}");
        }
        // Dubbele extensies zoals x.php.jpg of x.phtml.txt zijn verdacht.
        if (preg_match('/\.(?:php\d?|phtml|phar|pht)\./i', $base)) {
            throw new PackageException("Verdachte dubbele extensie: {$this->show($name)}");
        }
    }

    private function isSymlink(\ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }
        if ($opsys !== \ZipArchive::OPSYS_UNIX) {
            return false;
        }
        return ((($attr >> 16) & 0170000) === 0120000);
    }

    private function show(string $name): string
    {
        return mb_substr(preg_replace('/[^\x20-\x7E]/', '?', $name) ?? '', 0, 80);
    }
}
