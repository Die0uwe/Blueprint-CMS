<?php
// Samengestelde index voor de inlog-rem (LoginThrottle): telt per IP + actie
// binnen een tijdvenster. Zonder index scant dit de hele auditlog.

declare(strict_types=1);

return function (\PDO $pdo, string $prefix): void {
    $table = $prefix . 'audit_log';
    try {
        $pdo->exec("CREATE INDEX `idx_throttle` ON `{$table}` (`action`, `ip_address`, `created_at`)");
    } catch (\PDOException $e) {
        // 1061 = index bestaat al (idempotent); SQLite meldt "already exists".
        if (($e->errorInfo[1] ?? null) !== 1061 && !str_contains($e->getMessage(), 'already exists')) {
            throw $e;
        }
    }
};
