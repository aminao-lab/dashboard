<?php
require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../includes/learnworlds.class.php';
require_once __DIR__ . '/../includes/functions.php';

// 🎯 Récupérer les paramètres du job parallèle
$jobIndex = (int)($_ENV['JOB_INDEX'] ?? 0);
$totalJobs = (int)($_ENV['TOTAL_JOBS'] ?? 1);

logMessage("=== DÉBUT SYNC STUDENTS JOB {$jobIndex}/{$totalJobs} ===");

$supabase = new SupabaseClient();
$lw = new LearnWorlds();

// 📊 ÉTAPE 1 : Récupérer TOUS les utilisateurs enrolled via l'API enrollments
logMessage("🔍 Récupération des utilisateurs enrolled...");
$enrolledUserIds = getEnrolledUserIds($lw);
logMessage("✅ " . count($enrolledUserIds) . " utilisateurs enrolled trouvés");

// 📄 ÉTAPE 2 : Pagination distribuée entre les jobs
$currentPage = 1;
$totalProcessed = 0;
$batchBuffer = [];
$BATCH_SIZE = 100;
$enrolledCount = 0;
$skippedCount = 0;

while (true) {
    try {
        // 🎲 Distribuer les pages entre les jobs (modulo)
        if (($currentPage - 1) % $totalJobs !== $jobIndex) {
            $currentPage++;
            continue;
        }

        logMessage("📄 Job {$jobIndex} traite page {$currentPage}...");

        $response = $lw->getUsers($currentPage, 100);

        if (!$response || !isset($response['data'])) {
            logMessage("❌ Erreur récupération page {$currentPage}", 'ERROR');
            break;
        }

        $users = $response['data'];
        $totalPages = $response['meta']['totalPages'] ?? 1;

        if (empty($users)) {
            break;
        }

        // ✅ ÉTAPE 3 : Filtrer localement (pas d'appel API !)
        foreach ($users as $user) {
            $userId = $user['id'];
            
            // ⚡ Vérification instantanée via le Set PHP
            if (!in_array($userId, $enrolledUserIds)) {
                $skippedCount++;
                continue;
            }

            // 📦 Préparer le batch
            $batchBuffer[] = [
                'user_id' => $userId,
                'email' => $user['email'] ?? null,
                'username' => $user['username'] ?? null,
                'tags' => isset($user['tags']) ? implode(', ', $user['tags']) : null,
                'created_at' => formatTimestamp($user['created'] ?? null),
                'last_login_at' => formatTimestamp($user['last_login'] ?? null),
                'is_enrolled' => true
            ];

            // 🚀 Insérer par batch de 100
            if (count($batchBuffer) >= $BATCH_SIZE) {
                $result = $supabase->batchUpsert('students', $batchBuffer, 'user_id');
                if ($result !== false) {
                    $enrolledCount += count($batchBuffer);
                    logMessage("✅ Batch de " . count($batchBuffer) . " insérés");
                }
                $batchBuffer = [];
                usleep(100000); // 0.1s entre batch au lieu de 0.2s par user
            }
        }

        logMessage("📊 Page {$currentPage}/{$totalPages} : traité");

        $currentPage++;
        $totalProcessed++;

        if ($currentPage > $totalPages) {
            logMessage("🎉 Toutes les pages traitées par job {$jobIndex} !");
            break;
        }

        // ⏱️ Délai réduit
        usleep(500000); // 0.5s au lieu de sleep(1)

    } catch (Exception $e) {
        logMessage("❌ Erreur page {$currentPage}: " . $e->getMessage(), 'ERROR');
        break;
    }
}

// 🔥 Insérer le dernier batch
if (!empty($batchBuffer)) {
    $result = $supabase->batchUpsert('students', $batchBuffer, 'user_id');
    if ($result !== false) {
        $enrolledCount += count($batchBuffer);
        logMessage("✅ Dernier batch de " . count($batchBuffer) . " insérés");
    }
}

logMessage("📈 STATISTIQUES JOB {$jobIndex}:");
logMessage("   • Utilisateurs enrolled ajoutés : {$enrolledCount}");
logMessage("   • Utilisateurs sans cours ignorés : {$skippedCount}");
logMessage("=== FIN SYNC STUDENTS JOB {$jobIndex} ===\n");

// ========================================
// 🛠️ FONCTION HELPER : Récupérer tous les enrolled users
// ========================================
function getEnrolledUserIds($lw) {
    $enrolledIds = [];
    $page = 1;
    
    // 📚 L'API LearnWorlds a probablement un endpoint /enrollments
    // Adapter selon ta méthode réelle
    while (true) {
        try {
            // Option 1 : Si tu as un endpoint enrollments
            $enrollments = $lw->getEnrollments($page, 100);
            
            if (empty($enrollments['data'])) {
                break;
            }
            
            foreach ($enrollments['data'] as $enrollment) {
                $enrolledIds[] = $enrollment['user_id'];
            }
            
            $page++;
            
            if ($page > ($enrollments['meta']['totalPages'] ?? 1)) {
                break;
            }
            
            usleep(200000);
            
        } catch (Exception $e) {
            logMessage("⚠️ Erreur récupération enrollments page {$page}: " . $e->getMessage(), 'WARNING');
            break;
        }
    }
    
    // 🎯 Retourner un array unique
    return array_unique($enrolledIds);
}