<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

// CF_ROOT staat mogelijk al gedefinieerd wanneer dit bestand niet als
// losse entry point wordt aangeroepen maar vanuit public/index.php wordt
// geïncluded (zie het commentaar daar) — define() op een bestaande
// constante geeft anders een "already defined"-warning (de waarde is
// sowieso identiek: beide berekenen dirname() van de echte projectroot).
if (!defined('CF_ROOT')) {
    define('CF_ROOT', dirname(__DIR__));
}
define('INSTALLER_PATH', __DIR__);

require_once __DIR__ . '/InstallerCore.php';
InstallerCore::init();

// Al geïnstalleerd?
if (InstallerCore::isCompleted()) {
    header('Location: /');
    exit;
}

$step    = (int) ($_GET['step'] ?? InstallerCore::getCurrentStep());
$step    = max(1, min(5, $step));
$errors  = [];
$success = false;

// POST afhandelen per stap
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stepFile = __DIR__ . '/steps/Step' . $step . '.php';
    if (file_exists($stepFile)) {
        $result = require $stepFile;
        if ($result === true) {
            InstallerCore::setStep($step + 1);
            header('Location: ?step=' . ($step + 1));
            exit;
        }
        $errors = is_array($result) ? $result : ['Er is een fout opgetreden.'];
    }
}

// Toon de juiste stap
$stepData = InstallerCore::STEPS[$step] ?? InstallerCore::STEPS[1];
$currentStep = $step;

include __DIR__ . '/templates/layout.php';
