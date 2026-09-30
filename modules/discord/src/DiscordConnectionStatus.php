<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

/** Resultaat van DiscordApi::testConnection(). null = onbekend / niet kunnen controleren. */
final class DiscordConnectionStatus
{
    public ?bool   $tokenValid = null;
    public string  $botName = '';
    public ?bool   $inGuild = null;
    public string  $guildName = '';
    public ?int    $memberCount = null;
    public ?bool   $widgetEnabled = null;
    public ?string $widgetChannelId = null;
    public string  $widgetChannelName = '';
    /** @var list<string>|null leesbare rechten van de bot, null = niet te bepalen */
    public ?array  $permissions = null;
    public ?bool   $canManageGuild = null;
    /** @var list<string> */
    public array   $problems = [];

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $a): self
    {
        $s = new self();
        foreach (get_object_vars($s) as $k => $_) {
            if (array_key_exists($k, $a)) {
                $s->$k = $a[$k];
            }
        }
        return $s;
    }
}
