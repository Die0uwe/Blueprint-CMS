<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
//
// This work is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This work is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Mail;

/**
 * Minimale, dependency-vrije SMTP-mailer.
 *
 * De installer genereert al sinds Sprint 1 een volledige SMTP-configuratie-
 * sectie (config/config.php → 'mail' => [...]), maar er bestond geen enkele
 * klasse die daadwerkelijk een socket opende en een e-mail verstuurde —
 * ContactController sloeg berichten alleen op. composer.json bevat geen
 * mail-library (PHPMailer/Symfony Mailer), en packagist is in deze sandbox
 * onbereikbaar (geen composer.lock te regenereren), dus dit is een eigen,
 * kleine SMTP-client via raw sockets — geen nieuwe dependency nodig.
 *
 * Ondersteunt: platte verbinding, STARTTLS (poort 587) en implicit TLS
 * (poort 465, ssl://), AUTH LOGIN, en valt terug op PHP's ingebouwde mail()
 * wanneer geen host is geconfigureerd (bv. lokale sendmail op een host
 * zonder extern SMTP-account).
 */
final class Mailer
{
    public function __construct(
        private readonly string $host,
        private readonly int    $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly string $encryption = 'tls', // 'tls' (STARTTLS), 'ssl' (implicit), '' (plain)
        private readonly int    $timeout = 10,
    ) {}

    /**
     * Het geconfigureerde afzenderadres — bruikbaar als er (nog) geen apart
     * "meldingen naar"-adres is ingesteld (zie ContactController).
     */
    public function getFromAddress(): string
    {
        return $this->fromAddress;
    }

    /**
     * Verstuur een platte-tekst e-mail. Geeft true/false terug i.p.v. te
     * gooien — een mislukte mail mag een contactformulier-submit niet laten
     * crashen; de aanroeper logt/toont zelf een nette melding.
     */
    public function send(string $to, string $subject, string $textBody, ?string $replyTo = null): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("Mailer: ongeldig ontvanger-adres geweigerd: {$to}");
            return false;
        }

        if ($this->host === '') {
            return $this->sendViaNativeMail($to, $subject, $textBody, $replyTo);
        }

        try {
            $this->sendViaSmtp($to, $subject, $textBody, $replyTo);
            return true;
        } catch (\Throwable $e) {
            error_log('Mailer: SMTP-verzending mislukt: ' . $e->getMessage());
            return false;
        }
    }

    private function sendViaSmtp(string $to, string $subject, string $textBody, ?string $replyTo): void
    {
        $scheme = $this->encryption === 'ssl' ? 'ssl://' : '';
        $conn   = @fsockopen($scheme . $this->host, $this->port, $errno, $errstr, $this->timeout);
        if ($conn === false) {
            throw new \RuntimeException("kan geen verbinding maken met {$this->host}:{$this->port} ({$errstr})");
        }
        stream_set_timeout($conn, $this->timeout);

        try {
            $this->expect($conn, 220); // server greeting
            $this->command($conn, 'EHLO ' . $this->localHostname(), 250);

            if ($this->encryption === 'tls') {
                $this->command($conn, 'STARTTLS', 220);
                if (!@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS-handshake mislukt');
                }
                // Protocol vereist een nieuwe EHLO ná de TLS-handshake.
                $this->command($conn, 'EHLO ' . $this->localHostname(), 250);
            }

            if ($this->username !== '') {
                $this->command($conn, 'AUTH LOGIN', 334);
                $this->command($conn, base64_encode($this->username), 334);
                $this->command($conn, base64_encode($this->password), 235);
            }

            $this->command($conn, "MAIL FROM:<{$this->fromAddress}>", 250);
            $this->command($conn, "RCPT TO:<{$to}>", 250);
            $this->command($conn, 'DATA', 354);

            $headers = $this->buildHeaders($to, $subject, $replyTo);
            $body    = $this->dotStuff($textBody);
            fwrite($conn, $headers . "\r\n" . $body . "\r\n.\r\n");
            $this->expect($conn, 250);

            fwrite($conn, "QUIT\r\n");
        } finally {
            fclose($conn);
        }
    }

    /**
     * @param resource $conn
     */
    private function command($conn, string $line, int $expectedCode): string
    {
        fwrite($conn, $line . "\r\n");
        return $this->expect($conn, $expectedCode);
    }

    /**
     * @param resource $conn
     */
    private function expect($conn, int $expectedCode): string
    {
        $response = '';
        do {
            $line = fgets($conn, 512);
            if ($line === false) {
                throw new \RuntimeException('verbinding onverwacht gesloten door de SMTP-server');
            }
            $response .= $line;
            // Een multi-line SMTP-response heeft "-" na de code op alle
            // regels behalve de laatste (bv. "250-" ... "250 ").
            $continues = isset($line[3]) && $line[3] === '-';
        } while ($continues);

        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new \RuntimeException("onverwachte SMTP-respons: {$response}");
        }
        return $response;
    }

    private function buildHeaders(string $to, string $subject, ?string $replyTo): string
    {
        $fromHeader = $this->fromName !== ''
            ? sprintf('%s <%s>', $this->encodeHeaderWord($this->fromName), $this->fromAddress)
            : $this->fromAddress;

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $fromHeader,
            'To: ' . $to,
            'Subject: ' . $this->encodeHeaderWord($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->localHostname() . '>',
        ];
        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        return implode("\r\n", $headers) . "\r\n";
    }

    /** RFC 2047 encoded-word voor niet-ASCII headers (namen, onderwerpen). */
    private function encodeHeaderWord(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'ASCII')) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** SMTP "dot-stuffing": een regel die met "." begint krijgt een extra "." (RFC 5321 §4.5.2). */
    private function dotStuff(string $body): string
    {
        $body = str_replace(["\r\n", "\r", "\n"], "\r\n", $body);
        return preg_replace('/^\./m', '..', $body) ?? $body;
    }

    private function localHostname(): string
    {
        return $_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost';
    }

    /**
     * Fallback zonder SMTP-host geconfigureerd: PHP's ingebouwde mail()
     * (lokale sendmail/MTA). Geen garantie dat die er is — retourneert wat
     * mail() teruggeeft.
     */
    private function sendViaNativeMail(string $to, string $subject, string $textBody, ?string $replyTo): bool
    {
        $headers = [
            'From: ' . ($this->fromName !== '' ? "{$this->fromName} <{$this->fromAddress}>" : $this->fromAddress),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];
        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        return mail($to, $this->encodeHeaderWord($subject), $textBody, implode("\r\n", $headers));
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : Mailer.php                                           ║
// ║  Role         : Core                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-09-28                                           ║
// ║  Status       : New — Wave 2 (SMTP-configuratie bestond, mail-code niet) ║
// ║  Notes        : Raw-socket SMTP client + mail() fallback, geen nieuwe dep ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
