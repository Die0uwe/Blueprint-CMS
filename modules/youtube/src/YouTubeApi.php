<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\YouTube;

/**
 * YouTubeApi — dunne client voor de YouTube Data API v3.
 *
 * Golf 10a. Dit is BEWUST geen OAuth-client zoals GoogleOAuth (login) —
 * de YouTube Data API voor publieke kanaalgegevens (video's, livestatus,
 * kanaalinfo) werkt met een simpele server-side API-sleutel, geen
 * gebruikersautorisatie. Zie modules/youtube/module.json: 'api_key' i.p.v.
 * 'client_id'/'client_secret'. Eén sleutel via de Google Cloud Console met
 * "YouTube Data API v3" ingeschakeld volstaat — zie de uitleg in
 * Marketplace\views\module_settings.php::oauth_provider_hint().
 *
 * Elke methode geeft null/[] terug bij een API-fout i.p.v. te gooien —
 * zelfde "degradeer netjes" patroon als TwitchLiveBlock::fetchLiveStatus():
 * een verkeerd/leeg ingestelde sleutel mag een blok nooit laten crashen,
 * alleen een nette "niet beschikbaar"-melding tonen.
 */
final class YouTubeApi
{
    private const API = 'https://www.googleapis.com/youtube/v3';

    public function __construct(private readonly string $apiKey) {}

    /**
     * Kanaalinfo: titel, beschrijving, thumbnail, abonnee-/video-aantallen,
     * en de 'uploads'-playlist-ID (nodig voor getLatestVideos()).
     */
    public function getChannel(string $channelId): ?array
    {
        $data = $this->get('/channels', [
            'part' => 'snippet,statistics,contentDetails',
            'id'   => $channelId,
        ]);

        $item = $data['items'][0] ?? null;
        if ($item === null) return null;

        return [
            'id'              => $item['id'],
            'title'           => $item['snippet']['title'] ?? '',
            'description'     => $item['snippet']['description'] ?? '',
            'thumbnail'       => $item['snippet']['thumbnails']['medium']['url']
                                  ?? $item['snippet']['thumbnails']['default']['url'] ?? '',
            'subscriberCount' => (int) ($item['statistics']['subscriberCount'] ?? 0),
            'videoCount'      => (int) ($item['statistics']['videoCount'] ?? 0),
            'viewCount'       => (int) ($item['statistics']['viewCount'] ?? 0),
            'uploadsPlaylist' => $item['contentDetails']['relatedPlaylists']['uploads'] ?? null,
        ];
    }

    /**
     * Laatste N video's van een kanaal, via de 'uploads'-playlist
     * (playlistItems.list) — quota-goedkoper dan search.list, dat Google
     * zelf afraadt voor dit doel omdat het 100x zoveel quota kost.
     */
    public function getLatestVideos(string $channelId, int $limit = 6): array
    {
        $channel = $this->getChannel($channelId);
        $uploadsPlaylist = $channel['uploadsPlaylist'] ?? null;
        if ($uploadsPlaylist === null) return [];

        $data = $this->get('/playlistItems', [
            'part'       => 'snippet',
            'playlistId' => $uploadsPlaylist,
            'maxResults' => max(1, min(50, $limit)),
        ]);

        $videos = [];
        foreach ($data['items'] ?? [] as $item) {
            $snippet = $item['snippet'] ?? [];
            $videoId = $snippet['resourceId']['videoId'] ?? null;
            if ($videoId === null) continue;

            $videos[] = [
                'id'        => $videoId,
                'title'     => $snippet['title'] ?? '',
                'thumbnail' => $snippet['thumbnails']['medium']['url']
                                ?? $snippet['thumbnails']['default']['url'] ?? '',
                'publishedAt' => $snippet['publishedAt'] ?? '',
                'url'       => 'https://www.youtube.com/watch?v=' . $videoId,
            ];
        }
        return $videos;
    }

    /**
     * Is dit kanaal op dit moment live? Gebruikt search.list met
     * eventType=live — dit ís de door Google gedocumenteerde manier om
     * live-status te detecteren (er bestaat geen goedkoper endpoint
     * hiervoor); vandaar dat YouTubeLiveBlock dit resultaat 5 minuten
     * cachet i.p.v. Twitch's 90 seconden, om de duurdere quotakosten
     * (100 quota-eenheden per aanroep, tegen 1 voor de meeste andere calls;
     * een gratis Google Cloud-project krijgt 10.000 eenheden/dag) te sparen.
     */
    public function getLiveStream(string $channelId): ?array
    {
        $data = $this->get('/search', [
            'part'       => 'snippet',
            'channelId'  => $channelId,
            'eventType'  => 'live',
            'type'       => 'video',
            'maxResults' => 1,
        ]);

        $item = $data['items'][0] ?? null;
        if ($item === null) return null;

        return [
            'videoId'   => $item['id']['videoId'] ?? '',
            'title'     => $item['snippet']['title'] ?? '',
            'thumbnail' => $item['snippet']['thumbnails']['medium']['url']
                            ?? $item['snippet']['thumbnails']['default']['url'] ?? '',
        ];
    }

    // ─── HTTP HELPER ────────────────────────────────────────────────────────

    private function get(string $path, array $params): array
    {
        if ($this->apiKey === '') return [];

        $params['key'] = $this->apiKey;
        $url = self::API . $path . '?' . http_build_query($params);

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 6,
            ]);
            $body   = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($status !== 200 || $body === false) return [];

            return json_decode($body, true, flags: JSON_THROW_ON_ERROR) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: YouTubeApi.php | Role: Core | Version: 1.0.0                 ║
// ║  Created: 2026-09-29 | Status: New — Golf 10a                       ║
// ╚══════════════════════════════════════════════════════════════════════╝
