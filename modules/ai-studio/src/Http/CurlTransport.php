<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Http;

/**
 * Productie-transport op basis van de ingebouwde cURL-extensie (geen libs).
 *
 * Veiligheidskeuzes: geen redirects (voorkomt SSRF-via-redirect en lekken van
 * een API-key naar een tweede host), TLS-verificatie altijd aan, protocollen
 * beperkt tot https (http alleen als de request dat expliciet toestaat),
 * verbinding vastgepind op het door SsrfGuard gecontroleerde IP, en een
 * harde bovengrens op de responsegrootte.
 */
final class CurlTransport implements HttpTransportInterface
{
    private const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;
    private const MAX_ERROR_BODY = 2000;

    /**
     * @return array<int, mixed>
     */
    public function buildOptions(HttpRequest $request): array
    {
        $headers = [];
        foreach ($request->headers as $name => $value) {
            if (preg_match('/[\r\n]/', $name . $value) === 1) {
                throw new TransportException('Ongeldige header.');
            }
            $headers[] = $name . ': ' . $value;
        }

        $parts = parse_url($request->url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new TransportException('Ongeldige URL.');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && !($scheme === 'http' && $request->allowHttp)) {
            throw new TransportException('Protocol niet toegestaan.');
        }

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => $request->allowHttp ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(5, $request->timeoutSeconds),
            CURLOPT_NOSIGNAL => true,
        ];

        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }

        if ($request->pinnedIp !== null) {
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
            $ip = str_contains($request->pinnedIp, ':') ? '[' . $request->pinnedIp . ']' : $request->pinnedIp;
            $options[CURLOPT_RESOLVE] = [$parts['host'] . ':' . $port . ':' . $ip];
        }

        return $options;
    }

    public function stream(HttpRequest $request): iterable
    {
        /** @var list<string> $buffer */
        $buffer = [];
        $status = 0;
        $total = 0;
        $errorBody = '';

        $options = $this->buildOptions($request);
        $options[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (&$status): int {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
            return strlen($line);
        };
        $options[CURLOPT_WRITEFUNCTION] = static function ($ch, string $data) use (&$buffer, &$status, &$total, &$errorBody): int {
            $total += strlen($data);
            if ($total > self::MAX_RESPONSE_BYTES) {
                return 0; // afbreken
            }
            if ($status >= 200 && $status < 300) {
                $buffer[] = $data;
            } elseif (strlen($errorBody) < self::MAX_ERROR_BODY) {
                $errorBody .= substr($data, 0, self::MAX_ERROR_BODY - strlen($errorBody));
            }
            return strlen($data);
        };

        $ch = curl_init();
        $multi = curl_multi_init();
        try {
            curl_setopt_array($ch, $options);
            curl_multi_add_handle($multi, $ch);

            do {
                $code = curl_multi_exec($multi, $running);
                while ($buffer !== []) {
                    yield (string) array_shift($buffer);
                }
                if ($running > 0) {
                    curl_multi_select($multi, 0.25);
                }
            } while ($running > 0 && $code === CURLM_OK);

            $error = curl_error($ch);
            if ($error !== '' || $status === 0) {
                throw new TransportException('Verbinding mislukt' . ($error !== '' ? ': ' . $error : '.'));
            }
            if ($status < 200 || $status >= 300) {
                throw new TransportException('HTTP ' . $status, $status, $errorBody);
            }
        } finally {
            curl_multi_remove_handle($multi, $ch);
            curl_multi_close($multi);
            curl_close($ch);
        }
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $body = '';
        $options = $this->buildOptions($request);
        $options[CURLOPT_RETURNTRANSFER] = true;
        $options[CURLOPT_WRITEFUNCTION] = static function ($ch, string $data) use (&$body): int {
            if (strlen($body) + strlen($data) > self::MAX_RESPONSE_BYTES) {
                return 0;
            }
            $body .= $data;
            return strlen($data);
        };

        $ch = curl_init();
        try {
            curl_setopt_array($ch, $options);
            curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($ch);
        }

        if ($error !== '' || $status === 0) {
            throw new TransportException('Verbinding mislukt' . ($error !== '' ? ': ' . $error : '.'));
        }
        return new HttpResponse($status, $body);
    }
}
