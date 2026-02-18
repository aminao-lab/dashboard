<?php
require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../includes/learnworlds.class.php';
require_once __DIR__ . '/../includes/functions.php';

// 🎯 Paramètres de parallélisation
$jobIndex = (int)($_ENV['JOB_INDEX'] ?? 0);
$totalJobs = (int)($_ENV['TOTAL_JOBS'] ?? 1);

logMessage("=== DÉBUT SYNC TEMPS_NIVEAU JOB {$jobIndex}/{$totalJobs} ===");

$supabase = new SupabaseClient();
$lw = new LearnWorlds();

// ÉTAPE 1 : Récupérer TOUS les élèves
$students = $supabase->selectAll('students', 'user_id,email', [], 'user_id.asc');

if (!$students || count($students) === 0) {
    logMessage("❌ Aucun élève trouvé", 'ERROR');
    exit(1);
}

$totalStudents = count($students);
logMessage("📊 Total élèves : {$totalStudents}");

// ÉTAPE 2 : Traitement par batch avec distribution
$batchBuffer = [];
$BATCH_SIZE = 100;
$processedCount = 0;

for ($i = 0; $i < $totalStudents; $i++) {
    // Distribution : ce job traite uniquement les élèves assignés
    if ($i % $totalJobs !== $jobIndex) {
        continue;
    }

    $student = $students[$i];
    $userId = $student['user_id'];
    $email = $student['email'] ?? 'N/A';

    try {
        // Appel API pour récupérer les temps par niveau
        $timeData = $lw->getUserTimeByLevel($userId);

        // Ajouter au buffer
        $batchBuffer[] = [
            'user_id' => $userId,
            '6eme' => $timeData['6eme'] ?? 0,
            '5eme' => $timeData['5eme'] ?? 0,
            '4eme' => $timeData['4eme'] ?? 0,
            '3eme' => $timeData['3eme'] ?? 0,
            '2nde' => $timeData['2nde'] ?? 0,
            '1ere' => $timeData['1ere'] ?? 0,
            'term' => $timeData['term'] ?? 0,
            'term-pc' => $timeData['term-pc'] ?? 0
        ];

        $processedCount++;

        // 🚀 Insertion par batch de 100
        if (count($batchBuffer) >= $BATCH_SIZE) {
            $result = $supabase->batchUpsert('temps_niveau', $batchBuffer, 'user_id');
            if ($result !== false) {
                logMessage("✅ Batch de " . count($batchBuffer) . " insérés (Job {$jobIndex})");
            } else {
                logMessage("⚠️ Erreur batch insertion", 'WARNING');
            }
            $batchBuffer = [];
            usleep(200000); // 0.2s entre batch
        }

        // Sleep réduit : tous les 10 élèves au lieu de 5
        if ($processedCount % 10 === 0) {
            usleep(500000); // 0.5s
            logMessage("⏳ Job {$jobIndex}: {$processedCount} élèves traités...");
        }

    } catch (Exception $e) {
        logMessage("❌ Erreur {$userId}: " . $e->getMessage(), 'ERROR');
    }
}

if (!empty($batchBuffer)) {
    $result = $supabase->batchUpsert('temps_niveau', $batchBuffer, 'user_id');
    if ($result !== false) {
        logMessage("✅ Dernier batch de " . count($batchBuffer) . " insérés");
    }
}

logMessage("📈 STATISTIQUES JOB {$jobIndex}:");
logMessage("   • Élèves traités : {$processedCount}");
logMessage("=== FIN SYNC TEMPS_NIVEAU JOB {$jobIndex} ===\n");