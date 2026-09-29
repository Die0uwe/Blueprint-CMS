<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Kick;

/**
 * KickApi — dunne client voor Kick-kanaal- en livestreamgegevens.
 *
 * S10 (na Golf 10a/YouTube). Net als YouTubeApi is dit BEWUST geen
 * OAuth-client: Kick heeft weliswaar sinds 2024/2025 een officiële,
 * OAuth2-beveiligde Developer API (`https://api.kick.com/public/v1`, login
 * via `https://id.kick.com/oauth/authorize` + `/oauth/token`, OAuth 2.1 met
 * verplichte PKCE) — maar die is bedoeld voor kanaal-eigenaren die hun eigen
 * kanaal beheren (chat, moderatie, beloningen), niet voor "toon de
 * live-status van willekeurig kanaal X op mijn website" zonder dat die
 * streamer zelf moet inloggen. Voor precies dát doel — hetzelfde als
 * TwitchLiveBlock/YouTubeLiveBlock — gebruikt deze client het publieke,
 * ongeauthenticeerde kanaal-endpoint `kick.com/api/v2/channels/{slug}`.
 *
 * EERLIJKE KANTTEKENING (zie ook README "Bekende beperkingen"): dit v2-
 * endpoint is NIET door Kick gedocumenteerd of officieel ondersteund — het
 * is het endpoint dat kick.com's eigen website intern gebruikt, en wordt zo
 * ook door de meeste bestaande open-source Kick-tools gebruikt bij gebrek
 * aan een officieel "publiek kanaal opzoeken zonder login"-endpoint. Kick
 * kan dit zonder aankondiging wijzigen of achter sterkere Cloudflare-
 * bescherming zetten. Een User-Agent-header die op een browser lijkt is
 * daarom bewust toegevoegd (een kale PHP-curl-UA wordt door Kick's
 * Cloudflare vaker geblokkeerd) — dit maakt het endpoint betrouwbaarder
 * maar niet gegarandeerd. Elke aanroep degradeert netjes naar null/[] bij
 * een fout, exact hetzelfde patroon als TwitchApi/YouTubeApi: een blok mag
 * hierdoor nooit crashen, alleen een nette "niet beschikbaar"-melding tonen.
 */
final class KickApi
{
    private const BASE = 'https://kick.com/api/v2';

    /**
     * Kanaal + livestream-status in één response (Kick's v2 endpoint geeft
     * dit altijd samen terug — geen apart livestream-endpoint nodig).
     *
     * @return array{username:string,avatar:string,followers:int,live:?array}|null
     */
    public function getChannel(string $slug): ?array
    {
        $data = $this->get('/channels/' . rawurlencode(strtolower($slug)));
        if ($data === null || empty($data['user'])) {
            return null;
        }

        $livestream = $data['livestream'] ?? null;
        $live = null;
        if (is_array($livestream) && !empty($livestream)) {
            $live = [
                'title'       => $livestream['session_title'] ?? '',
                'viewerCount' => (int) ($livestream['viewer_count'] ?? 0),
                'thumbnail'   => $livestream['thumbnail']['url'] ?? '',
                'category'    => $livestream['category']['name'] ?? '',
            ];
        }

        return [
            'username'  => $data['user']['username'] ?? $data['slug'] ?? $slug,
            'avatar'    => $data['user']['profile_pic'] ?? '',
            'followers' => (int) ($data['followers_count'] ?? 0),
            'live'      => $live,
        ];
    }

    // ─── HTTP HELPER ────────────────────────────────────────────────────────

    private function get(string $path): ?array
    {
        try {
            $ch = curl_init(self::BASE . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 6,
                // Zie klassecommentaar: Kick's Cloudflare blokkeert de kale
                // PHP-curl-UA vaker dan een browser-achtige UA.
                CURLOPT_HTTPHEADER     => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                        . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
                    'Accept: application/json',
                ],
            ]);
            $body   = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($status !== 200 || $body === false) {
                return null;
            }

            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: KickApi.php | Role: Core | Version: 1.0.0                    ║
// ║  Created: 2026-09-29 | Status: New — S10 (Kick-integratie)          ║
// ╚══════════════════════════════════════════════════════════════════════╝
