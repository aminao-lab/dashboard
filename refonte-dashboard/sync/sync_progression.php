<?php
require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../includes/learnworlds.class.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/config.php';

$jobIndex = (int)($_ENV['JOB_INDEX'] ?? 0);
$totalCount = (int)($_ENV['JOB_COUNT'] ?? 1);

logMessage("=== DÉBUT SYNC PROGRESSION Job {$jobIndex}/{$totalCount} ===");

$supabase = new SupabaseClient();
$lw = new LearnWorlds();

$students = $supabase->selectAll('students', 'user_id,email', [], 'user_id.asc');

if (!$students || count($students) === 0) {
    logMessage("❌ Aucun élève trouvé", 'ERROR');
    exit(1);
}

$totalStudents = count($students);
// $startIndex = getBatchProgress('progression_index') ?: 0;
$startIndex = 0; // Toujours à 0 pour ce job de progression

logMessage("📊 Job {$jobIndex}/{$totalCount} - Total élèves : {$totalStudents}");

// $endIndex = min($startIndex + BATCH_SIZE, $totalStudents);
$endIndex = $totalStudents; // Traiter tous les élèves à chaque run

$processedCount = 0;

for ($i = $startIndex; $i < $totalStudents; $i++) {
    $student = $students[$i];
    if ($i % $totalCount !== $jobIndex) {
        continue; // Ce n'est pas notre tour, on skip
    }

    $userId = $student['user_id'];  // ✅ user_id directement
    $email = $student['email'] ?? 'N/A';

    $currentNum = $i + 1;

    try {
        logMessage("⏳ [{$currentNum}/{$totalStudents}] {$email}...");

        $progressData = $lw->getUserProgressionByLevel($userId);

        // ✅ user_id = clé primaire
        $data = [
            'user_id' => $userId,  // ✅ PK
            '6eme' => $progressData['6eme'] ?? 0,
            '5eme' => $progressData['5eme'] ?? 0,
            '4eme' => $progressData['4eme'] ?? 0,
            '3eme' => $progressData['3eme'] ?? 0,
            '2nde' => $progressData['2nde'] ?? 0,
            '1ere' => $progressData['1ere'] ?? 0,
            'term' => $progressData['term'] ?? 0,
            'term-pc' => $progressData['term-pc'] ?? 0
        ];

        $existing = $supabase->select('progression', 'user_id', [
            'user_id' => "eq.{$userId}"
        ]);

        if ($existing && count($existing) > 0) {
            $result = $supabase->update('progression', $data, [
                'user_id' => "eq.{$userId}"
            ]);
        } else {
            $result = $supabase->upsert('progression', $data);
        }

        if ($result === false) {
            logMessage("⚠️ Erreur {$userId}", 'WARNING');
        } else {
            $summary = [];
            foreach (NIVEAUX as $niveau) {
                if ($progressData[$niveau] > 0) {
                    $summary[] = "{$niveau}:{$progressData[$niveau]}%";
                }
            }
            if (!empty($summary)) {
                logMessage("✅ {$email}: " . implode(', ', $summary));
            }
        }

        if ($i % 5 === 0) {
            sleep(API_DELAY);
            $processedCount ++;
        }
    } catch (Exception $e) {
        logMessage("❌ Erreur {$userId}: " . $e->getMessage(), 'ERROR');
    }
}

/*if ($endIndex < $totalStudents) {
    setBatchProgress('progression_index', $endIndex);
    logMessage("⏸️ Progression : {$endIndex}/{$totalStudents}");
} else {
    clearBatchProgress('progression_index');
    logMessage("🎉 Terminé ({$totalStudents}/{$totalStudents}) !");
}*/
echo "[INFO] Job {$jobIndex}/{$totalCount} processed {$processedCount} users\n";

logMessage("🎉 Terminé ({$totalStudents}/{$totalStudents}) !");
logMessage("=== FIN SYNC PROGRESSION Job {$jobIndex}/{$totalCount} ===\n");
