<?php
require_once __DIR__ . '/includes/functions.php';

echo "═══════════════════════════════════════\n";
echo "   ÉTAT DES PROGRESSIONS EN COURS\n";
echo "═══════════════════════════════════════\n\n";

$batches = [
    'students_page' => 'Sync Students',
    'temps_niveau_index' => 'Sync Temps Niveau',
    'progression_index' => 'Sync Progression',
    'temps_week_index' => 'Sync Temps Week'
];

foreach ($batches as $key => $label) {
    $progress = getBatchProgress($key);

    if ($progress > 0) {
        echo "⏳ {$label} : en cours à l'index {$progress}\n";
    } else {
        echo "✅ {$label} : terminé ou non démarré\n";
    }
}

echo "\n═══════════════════════════════════════\n";
