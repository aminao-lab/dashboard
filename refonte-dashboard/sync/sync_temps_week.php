<?php
require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../includes/learnworlds.class.php';
require_once __DIR__ . '/../includes/functions.php';

// 🎯 Paramètres de parallélisation
$jobIndex = (int)($_ENV['JOB_INDEX'] ?? 0);
$totalJobs = (int)($_ENV['TOTAL_JOBS'] ?? 1);

logMessage("=== DÉBUT SYNC TEMPS_WEEK JOB {$jobIndex}/{$totalJobs} ===");

$supabase = new SupabaseClient();
$lw = new LearnWorlds();

// 📅 Calcul de la semaine
$now = new DateTime();
$now->modify('last monday');
$now->modify('-7 days');
$isoWeek = getISOWeek($now);
$weekRange = getWeekRange($isoWeek);

logMessage("📅 Semaine : {$isoWeek}");
logMessage("📅 Période : {$weekRange['monday']} → {$weekRange['sunday']}");

// 📊 ÉTAPE 1 : Récupérer TOUS les élèves
$students = $supabase->selectAll('students', 'user_id,email', [], 'user_id.asc');

if (!$students || count($students) === 0) {
    logMessage("❌ Aucun élève trouvé", 'ERROR');
    exit(1);
}

$totalStudents = count($students);
logMessage("📊 Total élèves : {$totalStudents}");

// 📊 ÉTAPE 2 : Récupérer TOUTES les semaines existantes pour cette semaine (1 seule requête!)
logMessage("🔍 Récupération des données existantes semaine {$isoWeek}...");
$existingWeeks = $supabase->select('temps_week', 'user_id', [
    'semaine' => "eq.{$isoWeek}"
]);

$existingUserIds = [];
if ($existingWeeks && count($existingWeeks) > 0) {
    foreach ($existingWeeks as $week) {
        $existingUserIds[] = $week['user_id'];
    }
}
logMessage("✅ " . count($existingUserIds) . " élèves déjà présents pour cette semaine");

// 📊 ÉTAPE 3 : Récupérer TOUTES les semaines précédentes (1 seule requête!)
logMessage("🔍 Récupération de toutes les dernières semaines...");
$allPreviousWeeks = $supabase->select('temps_week', 'user_id,cumul_6eme,cumul_5eme,cumul_4eme,cumul_3eme,cumul_2nde,cumul_1ere,cumul_term,cumul_term-pc', [
    'semaine' => "lt.{$isoWeek}"
], 'user_id.desc,semaine.desc');

// Indexer par user_id pour accès rapide
$previousWeeksMap = [];
if ($allPreviousWeeks && count($allPreviousWeeks) > 0) {
    foreach ($allPreviousWeeks as $week) {
        $previousWeeksMap[$week['user_id']] = $week;
    }
}
logMessage("✅ " . count($previousWeeksMap) . " semaines précédentes chargées");

// 📦 ÉTAPE 4 : Traitement par batch avec distribution
$batchBuffer = [];
$BATCH_SIZE = 100;
$processedCount = 0;
$skippedCount = 0;

for ($i = 0; $i < $totalStudents; $i++) {
    // 🎲 Distribution : ce job traite uniquement les élèves assignés
    if ($i % $totalJobs !== $jobIndex) {
        continue;
    }

    $student = $students[$i];
    $userId = $student['user_id'];
    $email = $student['email'] ?? 'N/A';

    try {
        // ⚡ Vérification locale (pas de requête SQL!)
        if (in_array($userId, $existingUserIds)) {
            $skippedCount++;
            if ($skippedCount % 100 === 0) {
                logMessage("ℹ️ {$skippedCount} élèves skipped (semaine déjà présente)");
            }
            continue;
        }

        // 📞 Appel API pour récupérer les temps
        $currentTimeData = $lw->getUserTimeByLevel($userId);

        $weeklyTime = [];
        $cumulTime = [];

        // Récupérer la semaine précédente depuis le cache
        $previousWeek = $previousWeeksMap[$userId] ?? null;

        foreach (NIVEAUX as $niveau) {
            $currentTotal = $currentTimeData[$niveau] ?? 0;
            $cumulTime[$niveau] = $currentTotal;

            if (!$previousWeek) {
                $weeklyTime[$niveau] = $currentTotal;
            } else {
                $cumulColumnName = 'cumul_' . $niveau;
                $previousTotal = $previousWeek[$cumulColumnName] ?? 0;
                $weeklyTime[$niveau] = max($currentTotal - $previousTotal, 0);
            }
        }

        // 📦 Ajouter au buffer
        $batchBuffer[] = [
            'user_id' => $userId,
            'semaine' => $isoWeek,
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

        $processedCount++;

        // 🚀 Insertion par batch
        if (count($batchBuffer) >= $BATCH_SIZE) {
            $result = $supabase->batchUpsert('temps_week', $batchBuffer, 'user_id,semaine');
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
            usleep(500000); // 0.5s au lieu de sleep(API_DELAY)
        }

    } catch (Exception $e) {
        logMessage("❌ Erreur {$userId}: " . $e->getMessage(), 'ERROR');
    }
}

// 🔥 Insérer le dernier batch
if (!empty($batchBuffer)) {
    $result = $supabase->batchUpsert('temps_week', $batchBuffer, 'user_id,semaine');
    if ($result !== false) {
        logMessage("✅ Dernier batch de " . count($batchBuffer) . " insérés");
    }
}

logMessage("📈 STATISTIQUES JOB {$jobIndex}:");
logMessage("   • Élèves traités : {$processedCount}");
logMessage("   • Élèves skipped : {$skippedCount}");
logMessage("=== FIN SYNC TEMPS_WEEK JOB {$jobIndex} ===\n");