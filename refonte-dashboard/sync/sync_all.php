<?php
require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../includes/learnworlds.class.php';
require_once __DIR__ . '/../includes/functions.php';

/**
 * Script principal de synchronisation complète
 * À exécuter via CRON quotidien ou hebdomadaire
 * 
 * Ordre d'exécution :
 * 1. Sync Students (utilisateurs)
 * 2. Sync Temps Niveau (temps total par niveau)
 * 3. Sync Progression (% d'avancement par niveau)
 * 4. Sync Temps Week (temps hebdomadaire)
 * 
 * OPTIONS :
 * - php sync_all.php          → Reprend là où ça s'est arrêté
 * - php sync_all.php --reset  → Recommence depuis le début
 */

logMessage("╔═══════════════════════════════════════════════════════════╗");
logMessage("║   SYNCHRONISATION COMPLÈTE D-PHI ALPHA DASHBOARD          ║");
logMessage("╚═══════════════════════════════════════════════════════════╝");
logMessage("");

// Vérifier si on veut forcer un reset
$forceReset = in_array('--reset', $argv ?? []);

if ($forceReset) {
    logMessage("⚠️ Mode RESET : Toutes les progressions seront réinitialisées");
    clearBatchProgress('students_page');
    clearBatchProgress('temps_niveau_index');
    clearBatchProgress('progression_index');
    clearBatchProgress('temps_week_index');
    logMessage("");
}

$startTime = microtime(true);

// ============================================================================
// ÉTAPE 1 : SYNCHRONISATION DES UTILISATEURS
// ============================================================================

logMessage("┌─────────────────────────────────────────────────────────┐");
logMessage("│ ÉTAPE 1/4 : Synchronisation des utilisateurs            │");
logMessage("└─────────────────────────────────────────────────────────┘");

$students_start = microtime(true);

// ✅ NE PAS réinitialiser ici (permet de reprendre)
// clearBatchProgress('students_page'); ← SUPPRIMÉ

ob_start();
include __DIR__ . '/sync_students.php';
$output = ob_get_clean();
echo $output;

$students_duration = round(microtime(true) - $students_start, 2);
logMessage("⏱️ Durée étape 1: {$students_duration}s");
logMessage("");

// ============================================================================
// ÉTAPE 2 : SYNCHRONISATION TEMPS TOTAL PAR NIVEAU
// ============================================================================

logMessage("┌─────────────────────────────────────────────────────────┐");
logMessage("│ ÉTAPE 2/4 : Temps total par niveau                      │");
logMessage("└─────────────────────────────────────────────────────────┘");

// ✅ NE PAS réinitialiser ici (permet de reprendre)
// clearBatchProgress('temps_niveau_index'); ← SUPPRIMÉ

$temps_niveau_start = microtime(true);

$continue = true;
$iteration = 0;
$maxIterations = 100;

while ($continue && $iteration < $maxIterations) {
    $iteration++;

    ob_start();
    include __DIR__ . '/sync_temps_niveau.php';
    $output = ob_get_clean();
    echo $output;

    $progress = getBatchProgress('temps_niveau_index');
    if ($progress == 0) {
        $continue = false;
    } else {
        logMessage("🔄 Batch suivant...");
        sleep(2);
    }
}

$temps_niveau_duration = round(microtime(true) - $temps_niveau_start, 2);
logMessage("⏱️ Durée étape 2: {$temps_niveau_duration}s");
logMessage("");

// ============================================================================
// ÉTAPE 3 : SYNCHRONISATION PROGRESSION PAR NIVEAU
// ============================================================================

logMessage("┌─────────────────────────────────────────────────────────┐");
logMessage("│ ÉTAPE 3/4 : Progression par niveau (%)                  │");
logMessage("└─────────────────────────────────────────────────────────┘");

// ✅ NE PAS réinitialiser ici (permet de reprendre)
// clearBatchProgress('progression_index'); ← SUPPRIMÉ

$progression_start = microtime(true);

$continue = true;
$iteration = 0;

while ($continue && $iteration < $maxIterations) {
    $iteration++;

    ob_start();
    include __DIR__ . '/sync_progression.php';
    $output = ob_get_clean();
    echo $output;

    $progress = getBatchProgress('progression_index');
    if ($progress == 0) {
        $continue = false;
    } else {
        logMessage("🔄 Batch suivant...");
        sleep(2);
    }
}

$progression_duration = round(microtime(true) - $progression_start, 2);
logMessage("⏱️ Durée étape 3: {$progression_duration}s");
logMessage("");

// ============================================================================
// ÉTAPE 4 : SYNCHRONISATION TEMPS HEBDOMADAIRE
// ============================================================================

logMessage("┌─────────────────────────────────────────────────────────┐");
logMessage("│ ÉTAPE 4/4 : Temps hebdomadaire                          │");
logMessage("└─────────────────────────────────────────────────────────┘");

// ✅ NE PAS réinitialiser ici (permet de reprendre)
// clearBatchProgress('temps_week_index'); ← SUPPRIMÉ

$temps_week_start = microtime(true);

$continue = true;
$iteration = 0;

while ($continue && $iteration < $maxIterations) {
    $iteration++;

    ob_start();
    include __DIR__ . '/sync_temps_week.php';
    $output = ob_get_clean();
    echo $output;

    $progress = getBatchProgress('temps_week_index');
    if ($progress == 0) {
        $continue = false;
    } else {
        logMessage("🔄 Batch suivant...");
        sleep(2);
    }
}

$temps_week_duration = round(microtime(true) - $temps_week_start, 2);
logMessage("⏱️ Durée étape 4: {$temps_week_duration}s");
logMessage("");

// ============================================================================
// RAPPORT FINAL
// ============================================================================

$totalDuration = round(microtime(true) - $startTime, 2);

logMessage("╔═══════════════════════════════════════════════════════════╗");
logMessage("║              SYNCHRONISATION TERMINÉE                      ║");
logMessage("╚═══════════════════════════════════════════════════════════╝");
logMessage("");
logMessage("📊 Résumé des durées :");
logMessage("   • Utilisateurs    : {$students_duration}s");
logMessage("   • Temps niveau    : {$temps_niveau_duration}s");
logMessage("   • Progression     : {$progression_duration}s");
logMessage("   • Temps hebdo     : {$temps_week_duration}s");
logMessage("   ─────────────────────────────");
logMessage("   • TOTAL           : {$totalDuration}s");
logMessage("");

logMessage("✅ Synchronisation complète terminée avec succès !");
logMessage("");
