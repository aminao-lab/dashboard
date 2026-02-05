<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/learnworlds.class.php';

echo "=== TEST FILTRAGE ENROLLED ===\n\n";

$lw = new LearnWorlds();

// Récupérer quelques utilisateurs
$response = $lw->getUsers(1, 10);

if ($response && isset($response['data'])) {
    echo "Vérification de 10 utilisateurs :\n\n";

    $enrolledCount = 0;
    $notEnrolledCount = 0;

    foreach ($response['data'] as $user) {
        $userId = $user['id'];
        $email = $user['email'] ?? 'N/A';

        $isEnrolled = $lw->isUserEnrolled($userId);
        $courses = $lw->getUserCourses($userId);

        echo "• {$email}\n";
        echo "  - Enrolled : " . ($isEnrolled ? "✅ OUI" : "❌ NON") . "\n";
        echo "  - Nombre de cours : " . count($courses) . "\n";

        if (count($courses) > 0) {
            echo "  - Cours : ";
            $courseNames = array_map(function ($c) {
                return $c['title'] ?? $c['id'];
            }, array_slice($courses, 0, 3));
            echo implode(', ', $courseNames);
            if (count($courses) > 3) {
                echo " (+" . (count($courses) - 3) . " autres)";
            }
            echo "\n";
        }
        echo "\n";

        if ($isEnrolled) {
            $enrolledCount++;
        } else {
            $notEnrolledCount++;
        }
    }

    echo "─────────────────────────────────────\n";
    echo "RÉSUMÉ :\n";
    echo "  • Enrolled : {$enrolledCount}\n";
    echo "  • Non-enrolled : {$notEnrolledCount}\n";
} else {
    echo "❌ Erreur récupération utilisateurs\n";
}

echo "\n=== FIN TEST ===\n";
