<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Contract: elke POST-route valideert CSRF (direct of via een eigen helper in dezelfde klasse),
 * behalve de bewust uitgezonderde endpoints. Statische controle op de broncode.
 */
final class RouteCsrfContractTest extends TestCase
{
    /** Uitzonderingen met reden. */
    private const ALLOWED = [
        'CommunityFusion\\Api\\V1\\AuthController::login' => 'JWT-login met inloggegevens in de body; geen sessie/cookie, dus geen CSRF-vector',
        'OllamaApiController::chat'      => 'publieke, anonieme widget-API (rate-limited, geen sessie-acties)',
        'OllamaApiController::summarize' => 'publieke, anonieme widget-API (rate-limited, geen sessie-acties)',
        // disconnect() delegeert naar OAuthLoginFlow::disconnect(), die CsrfProtection::validateRequest() aanroept
        'BattleNetOAuthController::disconnect' => 'via OAuthLoginFlow',
        'DiscordOAuthController::disconnect'   => 'via OAuthLoginFlow',
        'GitHubOAuthController::disconnect'    => 'via OAuthLoginFlow',
        'GoogleOAuthController::disconnect'    => 'via OAuthLoginFlow',
        'TwitchOAuthController::disconnect'    => 'via OAuthLoginFlow',
    ];

    #[Test]
    public function everyPostHandlerChecksCsrfOrIsExplicitlyAllowed(): void
    {
        $root = dirname(__DIR__, 3);

        // 1. POST-routes verzamelen
        $routes = [];
        $sources = array_merge(
            [$root . '/src/Core/Router.php', $root . '/src/Core/Application.php'],
            glob($root . '/modules/*/src/*Module.php') ?: [],
            glob($root . '/modules/*/routes.php') ?: [],
        );
        foreach ($sources as $f) {
            $code = (string) file_get_contents($f);
            if (preg_match_all("/->post\(\s*'([^']+)'\s*,\s*'([^@']+)@(\w+)'/", $code, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    $routes[$x[1] . '|' . $x[2] . '|' . $x[3]] = [$x[1], $x[2], $x[3]];
                }
            }
        }
        $this->assertGreaterThan(50, count($routes), 'Routes niet gevonden — is de regex nog actueel?');

        // 2. klasse → bestand
        $index = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $fi) {
            $p = $fi->getPathname();
            if (!str_ends_with($p, '.php') || str_contains($p, '/vendor/') || str_contains($p, '/tests/')) {
                continue;
            }
            $index[basename($p, '.php')][] = $p;
        }

        // 3. controleren
        $missing = [];
        foreach ($routes as [$path, $class, $method]) {
            $short = substr(strrchr('\\' . $class, '\\'), 1);
            if (isset(self::ALLOWED["{$short}::{$method}"]) || isset(self::ALLOWED["{$class}::{$method}"])) {
                continue;
            }
            // Meerdere klassen delen een korte naam (Users\AuthController / Api\V1\AuthController):
            // kies het bestand waarvan de namespace bij de volledige klassenaam hoort.
            $ns   = substr($class, 0, (int) strrpos($class, '\\'));
            $file = null;
            foreach ($index[$short] ?? [] as $candidate) {
                if (str_contains((string) file_get_contents($candidate), 'namespace ' . $ns . ';')) {
                    $file = $candidate;
                    break;
                }
            }
            $this->assertNotNull($file, "Bestand voor {$class} niet gevonden");
            $src  = (string) file_get_contents($file);

            $body = $this->body($src, $method);
            $ok   = $body !== null && str_contains($body, 'Csrf');
            if (!$ok && $body !== null && preg_match_all('/\$this->(\w+)\(/', $body, $calls)) {
                foreach ($calls[1] as $helper) {
                    $hb = $this->body($src, $helper);
                    if ($hb !== null && str_contains($hb, 'Csrf')) {
                        $ok = true;
                        break;
                    }
                }
            }
            if (!$ok) {
                $missing[] = "{$path} → {$short}::{$method}";
            }
        }

        $this->assertSame([], $missing, "POST-routes zonder CSRF-validatie:\n" . implode("\n", $missing));
    }

    private function body(string $src, string $method): ?string
    {
        return preg_match('/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{(.*?)\n    \}\n/s', $src, $b) === 1 ? $b[1] : null;
    }
}
