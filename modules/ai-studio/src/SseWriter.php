<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio;

/**
 * Schrijft text/event-stream. Alle I/O is injecteerbaar zodat tests geen
 * echte headers of output nodig hebben; forOutput() geeft de productie-variant.
 */
final class SseWriter
{
    private const EVENTS = ['start', 'delta', 'proposal', 'notice', 'error', 'done'];

    /** @var callable(string): void */
    private $write;
    /** @var callable(string): void */
    private $header;
    /** @var callable(): bool */
    private $aborted;
    /** @var callable(): void */
    private $prepare;
    private bool $started = false;

    /**
     * @param callable(string): void $write
     * @param callable(string): void $header
     * @param callable(): bool       $aborted
     * @param callable(): void       $prepare
     */
    public function __construct(callable $write, callable $header, callable $aborted, callable $prepare)
    {
        $this->write = $write;
        $this->header = $header;
        $this->aborted = $aborted;
        $this->prepare = $prepare;
    }

    public static function forOutput(): self
    {
        return new self(
            static function (string $s): void {
                echo $s;
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            },
            static function (string $h): void {
                header($h);
            },
            static fn (): bool => connection_aborted() === 1,
            static function (): void {
                @set_time_limit(300);
                @ini_set('zlib.output_compression', '0');
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
            },
        );
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        ($this->prepare)();
        ($this->header)('Content-Type: text/event-stream; charset=utf-8');
        ($this->header)('Cache-Control: no-cache, no-transform');
        ($this->header)('X-Accel-Buffering: no');
        ($this->header)('X-Content-Type-Options: nosniff');
        ($this->write)(": ai-studio stream\n\n");
    }

    /**
     * @param array<string, mixed> $data
     * @return bool false als de client weg is (stop dan met streamen)
     */
    public function event(string $name, array $data): bool
    {
        if (!in_array($name, self::EVENTS, true)) {
            throw new \InvalidArgumentException('Onbekend SSE-event.');
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        ($this->write)('event: ' . $name . "\ndata: " . $json . "\n\n");
        return !($this->aborted)();
    }
}
