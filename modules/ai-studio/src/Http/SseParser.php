<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Http;

/**
 * Incrementele parser voor text/event-stream (SSE). Brokken mogen midden in
 * een regel eindigen; een event is pas compleet na een lege regel.
 */
final class SseParser
{
    private string $buffer = '';
    private string $event = 'message';
    /** @var list<string> */
    private array $data = [];

    /**
     * @return list<array{event: string, data: string}>
     */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        $events = [];

        while (($pos = $this->nextLineEnd()) !== null) {
            $line = substr($this->buffer, 0, $pos[0]);
            $this->buffer = substr($this->buffer, $pos[0] + $pos[1]);

            if ($line === '') {
                $event = $this->flushEvent();
                if ($event !== null) {
                    $events[] = $event;
                }
                continue;
            }
            $this->handleLine($line);
        }

        return $events;
    }

    /**
     * Roep aan als de stream stopt: een laatste event zonder afsluitende lege regel.
     *
     * @return list<array{event: string, data: string}>
     */
    public function finish(): array
    {
        $events = [];
        if ($this->buffer !== '') {
            $this->handleLine(rtrim($this->buffer, "\r"));
            $this->buffer = '';
        }
        $event = $this->flushEvent();
        if ($event !== null) {
            $events[] = $event;
        }
        return $events;
    }

    /**
     * @return array{0: int, 1: int}|null positie van de regeleinde en lengte van het scheidingsteken
     */
    private function nextLineEnd(): ?array
    {
        if (preg_match('/\r\n|\n|\r(?=.)/s', $this->buffer, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        return [$m[0][1], strlen($m[0][0])];
    }

    private function handleLine(string $line): void
    {
        if (str_starts_with($line, ':')) {
            return; // commentaar / keep-alive
        }
        $colon = strpos($line, ':');
        if ($colon === false) {
            $field = $line;
            $value = '';
        } else {
            $field = substr($line, 0, $colon);
            $value = substr($line, $colon + 1);
            if (str_starts_with($value, ' ')) {
                $value = substr($value, 1);
            }
        }

        if ($field === 'event') {
            $this->event = $value === '' ? 'message' : $value;
        } elseif ($field === 'data') {
            $this->data[] = $value;
        }
    }

    /**
     * @return array{event: string, data: string}|null
     */
    private function flushEvent(): ?array
    {
        if ($this->data === []) {
            $this->event = 'message';
            return null;
        }
        $event = ['event' => $this->event, 'data' => implode("\n", $this->data)];
        $this->event = 'message';
        $this->data = [];
        return $event;
    }
}
