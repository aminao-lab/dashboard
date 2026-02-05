<?php
require_once __DIR__ . '/config/supabase.php';
require_once __DIR__ . '/includes/learnworlds.class.php';

echo "=== TEST CONNEXIONS ===\n\n";

// Test 1 : Connexion Supabase
echo "1. Test Supabase...\n";
try {
    $supabase = new SupabaseClient();
    $result = $supabase->select('students', 'user_id', [], ['limit' => 1]);

    if ($result !== false) {
        echo "✅ Connexion Supabase OK\n";
        echo "   Nombre d'élèves: " . (is_array($result) ? count($result) : 0) . "\n";
    } else {
        echo "❌ Erreur connexion Supabase\n";
    }
} catch (Exception $e) {
    echo "❌ Exception Supabase: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 2 : Connexion LearnWorlds
echo "2. Test LearnWorlds API...\n";
try {
    $lw = new LearnWorlds();
    $users = $lw->getUsers(1, 5);

    if ($users && isset($users['data'])) {
        echo "✅ Connexion LearnWorlds OK\n";
        echo "   Utilisateurs récupérés: " . count($users['data']) . "\n";
        echo "   Premier utilisateur: " . ($users['data'][0]['email'] ?? 'N/A') . "\n";
    } else {
        echo "❌ Erreur API LearnWorlds\n";
    }
} catch (Exception $e) {
    echo "❌ Exception LearnWorlds: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 3 : Test temps niveau pour un utilisateur
echo "3. Test récupération temps niveau...\n";
try {
    $lw = new LearnWorlds();
    $testUserId = '626ac4ce5145e8d3660276d4'; // Remplacer par un vrai ID

    $timeData = $lw->getUserTimeByLevel($testUserId);

    echo "✅ Temps récupéré pour user {$testUserId}:\n";
    foreach ($timeData as $niveau => $temps) {
        if ($temps > 0) {
            $heures = floor($temps / 3600);
            $minutes = floor(($temps % 3600) / 60);
            echo "   • {$niveau}: {$heures}h{$minutes}m ({$temps}s)\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
}

echo "\n=== FIN TESTS ===\n";
