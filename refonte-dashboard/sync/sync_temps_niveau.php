<?php
require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../includes/learnworlds.class.php';
require_once __DIR__ . '/../includes/functions.php';

$envFile = __DIR__ . '/.env'; 
if (file_exists($envFile)) { 
  $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); 
  foreach ($lines as $line) {
    if (strpos(trim($line), '#') === 0) continue; // Ignorer les "#"
    [$name, $value] = explode('=', $line, 2);
    putenv("$name=$value");
  }
}

if (!getenv('SUPABASE_URL') || !getenv('SUPABASE_SERVICE_KEY')) {
  die("!! .env manquant ou SUPABASE_URL / SUPABASE_SERVICE_KEY non définis\n"); 
}

logMessage("=== DÉBUT SYNC TEMPS_NIVEAU ===");

$supabase = new SupabaseClient();
$lw = new LearnWorlds();

$students = $supabase->selectAll('students', 'user_id,email', [], 'user_id.asc');

if (!$students || count($students) === 0) {
    logMessage("❌ Aucun élève trouvé", 'ERROR');
    exit(1);
}

$totalStudents = count($students);
$startIndex = getBatchProgress('temps_niveau_index') ?: 0;

logMessage("📊 Total élèves : {$totalStudents}, Index : {$startIndex}");

$endIndex = min($startIndex + BATCH_SIZE, $totalStudents);

for ($i = $startIndex; $i < $endIndex; $i++) {
    $student = $students[$i];
    $userId = $student['user_id'];
    $email = $student['email'] ?? 'N/A';
    $currentNum = $i + 1;

    try {
        logMessage("⏳ [{$currentNum}/{$totalStudents}] {$email}...");

        $timeData = $lw->getUserTimeByLevel($userId);

        // On écrit quand même (même si tout est à 0) => utile pour la régularité ensuite
        $row = [
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

        // UPSERT direct (pas de select/update)
        // Selon ton wrapper, il peut falloir passer un tableau de lignes
        $result = $supabase->upsert('temps_niveau', $row);

        if ($result === false) {
            logMessage("⚠️ Upsert KO pour {$userId}", 'WARNING');
        } else {
            logMessage("✅ Upsert OK pour {$email}");
        }

        if ($i % 5 === 0) {
            sleep(API_DELAY);
        }

    } catch (Exception $e) {
        logMessage("❌ Erreur {$userId}: " . $e->getMessage(), 'ERROR');
    }
}

if ($endIndex < $totalStudents) {
    setBatchProgress('temps_niveau_index', $endIndex);
    logMessage("⏸️ Progression : {$endIndex}/{$totalStudents}");
} else {
    clearBatchProgress('temps_niveau_index');
    logMessage("🎉 Terminé ({$totalStudents}/{$totalStudents}) !");
}

logMessage("=== FIN SYNC TEMPS_NIVEAU ===");
