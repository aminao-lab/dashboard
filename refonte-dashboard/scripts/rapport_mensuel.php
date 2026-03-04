<?php
// scripts/rapport_mensuel.php - TEST LOCAL
echo "🚀 TEST RAPPORT MENSUEL\n";

$dsn = "host={$_ENV['SUPABASE_URL']} port=5432 dbname=postgres user=postgres password={$_ENV['SUPABASE_KEY']}";
$conn = pg_connect($dsn) or die("❌ Erreur connexion Supabase");

$sql = "SELECT 
  s.user_id AS enfant_id,
  s.email AS parent_email,
  s.username AS enfant_nom,
  COUNT(da.activity_date) FILTER (WHERE da.is_active = true) AS jours_actifs,
  ROUND(COUNT(da.activity_date) FILTER (WHERE da.is_active = true)::numeric / 22 * 100, 1) AS regularite_pct,
  COALESCE(SUM(da.seconds_spent), 0) AS temps_mois_sec,
  COALESCE((SELECT dcs.total_cumul_seconds FROM daily_cumul_snapshot dcs WHERE dcs.user_id = s.user_id ORDER BY snapshot_date DESC LIMIT 1), 0) AS cumul_global_sec
FROM students s
LEFT JOIN daily_activity da ON s.user_id = da.user_id AND da.activity_date >= NOW() - INTERVAL '30 days'
WHERE s.is_enrolled = true
GROUP BY s.user_id, s.email, s.username
HAVING COUNT(da.activity_date) FILTER (WHERE da.is_active = true) > 0
ORDER BY regularite_pct DESC
LIMIT 5";  // LIMIT 5 pour test

$result = pg_query($conn, $sql);
$members = [];
while($row = pg_fetch_assoc($result)) {
    echo "👤 {$row['enfant_nom']} <{$row['parent_email']}>\n";
    echo "   📈 {$row['regularite_pct']}% ({$row['jours_actifs']}/22j)\n";
    echo "   ⏱️  ".gmdate('H\\hi', $row['temps_mois_sec'])."\n\n";
    $members[] = $row;
}
pg_close($conn);

echo "✅ ".count($members)." élèves OK\n";
file_put_contents('test_rapport.json', json_encode($members, JSON_PRETTY_PRINT));
?>
