<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Cli\Commands;

/**
 * Queue Worker Command
 * Verwerkt jobs uit de database queue.
 *
 * Gebruik: php cli/console.php queue:work [--queue=default] [--sleep=3] [--tries=3]
 */
final class QueueWorkerCommand
{
    public function handle(array $argv): void
    {
        $queue  = $this->getOption($argv, 'queue', 'default');
        $sleep  = (int) $this->getOption($argv, 'sleep', '3');
        $tries  = (int) $this->getOption($argv, 'tries', '3');

        echo "🔄 Queue Worker gestart — Queue: {$queue} | Sleep: {$sleep}s | Max tries: {$tries}\n";
        echo "   Ctrl+C om te stoppen\n\n";

        // Bootstrap de applicatie. boot() was private tot Wave 2 (zie
        // Application::boot() docblock) — zonder deze aanroep registreert
        // niets de Connection-singleton en gooide make() hieronder altijd
        // "Kan parameter '$config' niet resolven voor Connection."
        $app = require CF_ROOT . '/src/Core/Application.php';
        $app->boot();
        $db  = $app->make(\CommunityFusion\Core\Database\Connection::class);

        while (true) {
            $job = $db->fetchOne(
                "SELECT * FROM cf_queue_jobs
                 WHERE queue = ? AND reserved_at IS NULL AND available_at <= NOW() AND failed_at IS NULL
                 ORDER BY id ASC LIMIT 1",
                [$queue]
            );

            if ($job === null) {
                sleep($sleep);
                continue;
            }

            // Reserveer de job
            $db->execute(
                "UPDATE cf_queue_jobs SET reserved_at = NOW(), attempts = attempts + 1 WHERE id = ?",
                [$job['id']]
            );

            echo "[" . date('H:i:s') . "] Verwerking job #{$job['id']} (queue: {$job['queue']})\n";

            try {
                // Alleen Job-subklassen deserialiseren (geen willekeurige klassen/gadgets uit de database)
                $payload = (string) $job['payload'];
                if (!preg_match('/^O:\d+:"([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)":/', $payload, $cm)
                    || !is_subclass_of($cm[1], \CommunityFusion\Core\Queue\Job::class)) {
                    throw new \RuntimeException("Ongeldig job payload (geen Job-klasse)");
                }
                $jobInstance = unserialize($payload, ['allowed_classes' => [$cm[1]]]);

                if (!is_object($jobInstance) || !method_exists($jobInstance, 'handle')) {
                    throw new \RuntimeException("Ongeldig job payload");
                }

                $jobInstance->handle();

                // Job geslaagd: verwijder
                $db->execute("DELETE FROM cf_queue_jobs WHERE id = ?", [$job['id']]);
                echo "   ✅ Klaar\n";

            } catch (\Throwable $e) {
                echo "   ❌ Fout: {$e->getMessage()}\n";

                if ((int) $job['attempts'] >= $tries) {
                    // Max pogingen bereikt: markeer als mislukt
                    $db->execute(
                        "UPDATE cf_queue_jobs SET failed_at = NOW(), last_error = ?, reserved_at = NULL WHERE id = ?",
                        [substr($e->getMessage(), 0, 500), $job['id']]
                    );
                    echo "   💀 Job gemarkeerd als mislukt na {$tries} pogingen\n";
                } else {
                    // Opnieuw proberen: vrijgeven
                    $db->execute(
                        "UPDATE cf_queue_jobs SET reserved_at = NULL, available_at = DATE_ADD(NOW(), INTERVAL 60 SECOND) WHERE id = ?",
                        [$job['id']]
                    );
                    echo "   🔁 Opnieuw proberen over 60 seconden\n";
                }
            }
        }
    }

    private function getOption(array $argv, string $name, string $default): string
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, "--{$name}=")) {
                return substr($arg, strlen("--{$name}="));
            }
        }
        return $default;
    }
}
