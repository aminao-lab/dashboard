<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "🔍 Test des classes...\n";

try {
    require_once __DIR__ . '/config/config.php';
    echo "✅ config.php OK\n";
} catch (Exception $e) {
    echo "❌ config.php ERREUR: " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/config/supabase.php';
    echo "✅ supabase.php OK\n";
    
    $supabase = new SupabaseClient();
    echo "✅ SupabaseClient instancié\n";
    
    // Test batchUpsert existe
    if (method_exists($supabase, 'batchUpsert')) {
        echo "✅ batchUpsert() existe\n";
    } else {
        echo "⚠️ batchUpsert() manquante\n";
    }
    
} catch (Exception $e) {
    echo "❌ supabase.php ERREUR: " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/includes/functions.php';
    echo "✅ functions.php OK\n";
} catch (Exception $e) {
    echo "❌ functions.php ERREUR: " . $e->getMessage() . "\n";
}

echo "\n🎉 Test terminé\n";