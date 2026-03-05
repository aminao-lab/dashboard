<?php
// scripts/rapport_mensuel.php - 100% SUPABASE PHP
require_once __DIR__ . '/../config/supabase.php';

echo "🚀 RAPPORT MENSUEL - " . date('Y-m-d H:i') . "\n";

// 1. CHARGE .env sync/
$envFile = __DIR__ . '/../sync/.env';
if (!file_exists($envFile)) die("❌ .env manquant: $envFile\n");

$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos($line, '=') !== false && !str_starts_with(trim($line), '#')) {
        [$name, $value] = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
        $_ENV[trim($name)] = trim($value);
    }
}
echo "✅ .env OK\n";

// 2. SUPABASE REST API (syntaxe CORRECTE)
$key = $_ENV['SUPABASE_SERVICE_KEY'] ?? $_ENV['SUPABASE_KEY'];
$url = $_ENV['SUPABASE_URL'] . '/rest/v1/students?select=user_id,email,username,created_at,is_enrolled&is_enrolled=eq.true&created_at=lt.' . date('Y-m-d', strtotime('-15 days'));

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ["apikey: $key", "Authorization: Bearer $key"],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    die("❌ HTTP $httpCode: $response\n");
}

$students = json_decode($response, true);
echo "📊 " . count($students) . " élèves trouvés\n";

// 3. BADGES (simulation)
$members = [];
foreach ($students as $student) {
    $jours = rand(6, 25); // Simulation
    
    if ($jours >= 25) {$badge="DIAMANT 💎"; $msg="Accomplissement rare !"; $conseil="Maintiens Diamant !";}
    elseif ($jours >= 19) {$badge="PLATINE ⭐"; $msg="Top motivation !"; $conseil="Vise Diamant !";}
    elseif ($jours >= 12) {$badge="OR 🥇"; $msg="Efforts solides !"; $conseil="1 session/jour";}
    elseif ($jours >= 6) {$badge="ARGENT 🥈"; $msg="Régularité top !"; $conseil="Vise Or !";}
    else {$badge="BRONZE 🥉"; $msg="Beaux débuts !"; $conseil="3j/semaine";}

    $members[] = [
        'email_address' => $student['email'],
        'merge_fields' => [
            'FNAME' => $student['username'] ?? 'Élève',
            'USERNAME' => $student['username'] ?? 'Élève',
            'BADGE' => $badge,
            'MESSAGE_BADGE' => $msg,
            'BADGE_CONSEIL' => $conseil,
            'CONNEXIONS' => $jours,
            'REGULARITE' => round($jours/22*100,1),
            'TEMPS_MOIS' => '02h15',
            'CUMUL_TOTAL' => '45h30'
        ],
        'status' => 'subscribed'
    ];
    
    echo "👤 " . ($student['username'] ?? 'N/A') . " → $badge ($jours j)\n";
}

echo "✅ " . count($members) . " rapports OK\n";
file_put_contents('test_rapport.json', json_encode($members, JSON_PRETTY_PRINT));
echo "test_rapport.json créé\n";
?>
