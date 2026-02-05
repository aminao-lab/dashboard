<?php
require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../includes/learnworlds.class.php';
require_once __DIR__ . '/../includes/functions.php';

logMessage("=== DÉBUT SYNC STUDENTS (ENROLLED ONLY) ===");

$supabase = new SupabaseClient();
$lw = new LearnWorlds();

$currentPage = getBatchProgress('students_page') ?: 1;
$totalProcessed = 0;
$maxPagesPerRun = 100;
$enrolledCount = 0;
$skippedCount = 0;

while ($totalProcessed < $maxPagesPerRun) {
    try {
        logMessage("📄 Traitement page {$currentPage}...");

        $response = $lw->getUsers($currentPage, 100);

        if (!$response || !isset($response['data'])) {
            logMessage("❌ Erreur récupération utilisateurs page {$currentPage}", 'ERROR');
            break;
        }

        $users = $response['data'];
        $totalPages = $response['meta']['totalPages'] ?? 1;

        if (empty($users)) {
            logMessage("ℹ️ Aucun utilisateur page {$currentPage}");
            break;
        }

        logMessage("🔍 Vérification des inscriptions pour " . count($users) . " utilisateurs...");

        foreach ($users as $user) {
            $userId = $user['id'];
            $email = $user['email'] ?? 'N/A';

            $isEnrolled = $lw->isUserEnrolled($userId);

            if (!$isEnrolled) {
                $skippedCount++;
                logMessage("⏭️ Skip user {$email} (pas de cours)", 'INFO');
                continue;
            }

            // ✅ user_id = clé primaire directement
            $data = [
                'user_id' => $userId,  // ✅ PK
                'email' => $user['email'] ?? null,
                'username' => $user['username'] ?? null,
                'tags' => isset($user['tags']) ? implode(', ', $user['tags']) : null,
                'created_at' => formatTimestamp($user['created'] ?? null),
                'last_login_at' => formatTimestamp($user['last_login'] ?? null),
                'is_enrolled' => true
            ];

            $result = $supabase->upsert('students', $data, 'user_id');

            if ($result === false) {
                logMessage("⚠️ Erreur insertion user {$userId}", 'WARNING');
            } else {
                $enrolledCount++;
                logMessage("✅ User enrolled: {$email}", 'INFO');
            }

            usleep(200000);
        }

        logMessage("📊 Page {$currentPage}/{$totalPages} : {$enrolledCount} enrolled, {$skippedCount} skipped");

        $currentPage++;
        $totalProcessed++;

        setBatchProgress('students_page', $currentPage);
        sleep(API_DELAY);

        if ($currentPage > $totalPages) {
            logMessage("🎉 Toutes les pages traitées !");
            clearBatchProgress('students_page');
            break;
        }
    } catch (Exception $e) {
        logMessage("❌ Erreur page {$currentPage}: " . $e->getMessage(), 'ERROR');
        break;
    }
}

logMessage("📈 STATISTIQUES FINALES:");
logMessage("   • Utilisateurs enrolled ajoutés : {$enrolledCount}");
logMessage("   • Utilisateurs sans cours ignorés : {$skippedCount}");
logMessage("=== FIN SYNC STUDENTS ===\n");
