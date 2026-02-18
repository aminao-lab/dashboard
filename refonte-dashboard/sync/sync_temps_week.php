<?php
require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../includes/learnworlds.class.php';
require_once __DIR__ . '/../includes/functions.php';

logMessage("=== DÉBUT SYNC TEMPS_WEEK ===");

$supabase = new SupabaseClient();
$lw = new LearnWorlds();

$students = $supabase->selectAll('students', 'user_id,email', [], 'user_id.asc');

if (!$students || count($students) === 0) {
    logMessage("❌ Aucun élève trouvé", 'ERROR');
    exit(1);
}

$now = new DateTime();
$now->modify('last monday');
$now->modify('-7 days');
$isoWeek = getISOWeek($now);
$weekRange = getWeekRange($isoWeek);

logMessage("📅 Semaine : {$isoWeek}");
logMessage("📅 Période : {$weekRange['monday']} → {$weekRange['sunday']}");

$totalStudents = count($students);
$startIndex = 0; // getBatchProgress('temps_week_index') ?: 0; --> reprend la ou le script s'est arreté 

logMessage("📊 Total élèves : {$totalStudents}, Index : {$startIndex}");

$endIndex = $totalStudents; // min($startIndex + BATCH_SIZE, $totalStudents);

for ($i = $startIndex; $i < $endIndex; $i++) {
    $student = $students[$i];
    $userId = $student['user_id'];  // ✅ user_id directement
    $email = $student['email'] ?? 'N/A';

    $currentNum = $i + 1;

    try {
        logMessage("⏳ [{$currentNum}/{$totalStudents}] {$email}...");

        // ✅ Clé composite user_id + semaine
        $existing = $supabase->select('temps_week', 'user_id', [
            'user_id' => "eq.{$userId}",
            'semaine' => "eq.{$isoWeek}"
        ]);

        if ($existing && count($existing) > 0) {
            logMessage("ℹ️ Semaine {$isoWeek} déjà présente, skip");
            continue;
        }

        $currentTimeData = $lw->getUserTimeByLevel($userId);

        $previousWeeks = $supabase->select('temps_week', '*', [
            'user_id' => "eq.{$userId}"
        ], [
            'order' => 'semaine.desc',
            'limit' => 1
        ]);

        $weeklyTime = [];
        $cumulTime = [];

        foreach (NIVEAUX as $niveau) {
            $currentTotal = $currentTimeData[$niveau] ?? 0;
            $cumulTime[$niveau] = $currentTotal;

            if (!$previousWeeks || count($previousWeeks) === 0) {
                $weeklyTime[$niveau] = $currentTotal;
            } else {
                $lastWeek = $previousWeeks[0];
                $cumulColumnName = 'cumul_' . $niveau;
                $previousTotal = $lastWeek[$cumulColumnName] ?? 0;
                $weeklyTime[$niveau] = max($currentTotal - $previousTotal, 0);
            }
        }

        // ✅ user_id dans la clé composite
        $data = [
            'user_id' => $userId,  // ✅ Part de la PK
            'semaine' => $isoWeek,  // ✅ Part de la PK
            '6eme' => $weeklyTime['6eme'],
            '5eme' => $weeklyTime['5eme'],
            '4eme' => $weeklyTime['4eme'],
            '3eme' => $weeklyTime['3eme'],
            '2nde' => $weeklyTime['2nde'],
            '1ere' => $weeklyTime['1ere'],
            'term' => $weeklyTime['term'],
            'term-pc' => $weeklyTime['term-pc'],
            'cumul_6eme' => $cumulTime['6eme'],
            'cumul_5eme' => $cumulTime['5eme'],
            'cumul_4eme' => $cumulTime['4eme'],
            'cumul_3eme' => $cumulTime['3eme'],
            'cumul_2nde' => $cumulTime['2nde'],
            'cumul_1ere' => $cumulTime['1ere'],
            'cumul_term' => $cumulTime['term'],
            'cumul_term-pc' => $cumulTime['term-pc'],
            'debute_le' => $weekRange['monday'],
            'finit_le' => $weekRange['sunday']
        ];

        $result = $supabase->upsert('temps_week', $data);

        if ($result === false) {
            logMessage("⚠️ Erreur {$userId}", 'WARNING');
        } else {
            $summary = [];
            foreach (NIVEAUX as $niveau) {
                if ($weeklyTime[$niveau] > 0) {
                    $summary[] = "{$niveau}:" . formatSeconds($weeklyTime[$niveau]);
                }
            }
            if (!empty($summary)) {
                logMessage("✅ {$email}: " . implode(', ', $summary));
            }
        }

        if ($i % 5 === 0) {
            sleep(API_DELAY);
        }
    } catch (Exception $e) {
        logMessage("❌ Erreur {$userId}: " . $e->getMessage(), 'ERROR');
    }
}

/*  if ($endIndex < $totalStudents) {
    setBatchProgress('temps_week_index', $endIndex);
    logMessage("⏸️ Progression : {$endIndex}/{$totalStudents}");
} else {
    clearBatchProgress('temps_week_index');
    logMessage("🎉 Terminé ({$totalStudents}/{$totalStudents}) !");
}
*/
logMessage("{$totalStudents}/{$totalStudents} élèves traités.");
logMessage("=== FIN SYNC TEMPS_WEEK ===\n");
