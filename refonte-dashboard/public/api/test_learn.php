<?php
require_once __DIR__ . "/../../includes/learnworlds.class.php";
require_once __DIR__ . "/../../includes/functions.php";


$lw = new LearnWorlds();

echo "=== TEST API LEARNWORLDS ===\n";

// Test : récupérer les users
try {
    $users = $lw->getUsers(1, 1); // 1 seul user pour éviter le bruit
    print_r($users);
} catch (Exception $e) {
    echo "Erreur : " . $e->getMessage() . "\n";
}

$userId = "6742e1aec36095bcb50b4fa8"; // un user réel
try {
    $courses = $lw->getUsersCourses($userId);
    print_r($courses);
} catch (Exception $e) {
    echo "Erreur : " . $e->getMessage() . "\n";
}

$result = $lw->debugRequest("/users/{$userId}/courses");