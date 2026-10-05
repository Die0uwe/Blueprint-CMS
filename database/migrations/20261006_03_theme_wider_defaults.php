<?php
// De standaard-breedte van het thema ging van 1280 px (zijbalk 260) naar 1600 px (zijbalk 280).
// Alleen waarden die nog exact de OUDE standaard zijn worden meegenomen: een bewust gekozen
// breedte of preset blijft onaangeroerd. Idempotent.

declare(strict_types=1);

return function (\PDO $pdo, string $prefix): void {
    $t = $prefix . 'settings';
    foreach ([['layout_width', '1280', '1600'], ['sidebar_width', '260', '280']] as [$key, $old, $new]) {
        $st = $pdo->prepare("UPDATE `{$t}` SET `value` = ? WHERE `group` = 'theme' AND `key` = ? AND `value` = ?");
        $st->execute([$new, $key, $old]);
    }
};
