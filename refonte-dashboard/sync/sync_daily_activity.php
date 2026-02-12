<?php

date_default_timezone_set('Europe/Paris');

$SUPABASE_URL         = getenv('SUPABASE_URL');
$SUPABASE_SERVICE_KEY = getenv('SUPABASE_SERVICE_KEY');

if (!$SUPABASE_URL || !$SUPABASE_SERVICE_KEY) {
  fwrite(STDERR, "Missing Supabase env vars (SUPABASE_URL / SUPABASE_SERVICE_KEY)\n");
  exit(1);
}

define('ACTIVE_THRESHOLD_SECONDS', 600);

// ✅ Date cible = J-1 (stable)
$targetDate = (new DateTime('yesterday', new DateTimeZone('Europe/Paris')))->format('Y-m-d');

function sb_headers(array $extra = []): array {
  global $SUPABASE_SERVICE_KEY;
  return array_merge([
    "apikey: $SUPABASE_SERVICE_KEY",
    "Authorization: Bearer $SUPABASE_SERVICE_KEY",
    "Accept: application/json",
    "Content-Type: application/json",
  ], $extra);
}

function sb_get(string $path): array {
  global $SUPABASE_URL;
  $ch = curl_init(rtrim($SUPABASE_URL, '/') . $path);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => sb_headers(),
    CURLOPT_TIMEOUT => 60,
  ]);
  $res = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($res === false) throw new Exception("GET curl error: $err");
  if ($http >= 400) throw new Exception("GET error $http: $res");

  $json = json_decode($res, true);
  return is_array($json) ? $json : [];
}

// A garder temporairement pour debug (vérifier que les données sont correctes avant upsert)
file_put_contents('debug_rows.json', json_encode($rows, JSON_PRETTY_PRINT));
echo "[DEBUG] Check debug_rows.json\n"; exit;

function sb_upsert(string $table, array $rows): void {
  global $SUPABASE_URL;

  // upsert bulk
  $ch = curl_init(rtrim($SUPABASE_URL, '/') . "/rest/v1/$table");
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => json_encode($rows),
    CURLOPT_HTTPHEADER => sb_headers(["Prefer: resolution=merge-duplicates", "Prefer: skip-generated-columns=true"]),
    CURLOPT_TIMEOUT => 60,
  ]);
  $res = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($res === false) throw new Exception("UPSERT curl error: $err");
  if ($http >= 400) throw new Exception("UPSERT error $http: $res");
}

function i($v): int { return is_numeric($v) ? (int)$v : 0; }

echo "=== START sync_daily_activity (targetDate=$targetDate) ===\n";

// 1) lire temps_niveau
$temps = sb_get('/rest/v1/temps_niveau?select=user_id,"6eme","5eme","4eme","3eme","2nde","1ere",term,"term-pc"');

echo "[INFO] temps_niveau rows=" . count($temps) . "\n";
if (count($temps) === 0) {
  echo "[WARN] temps_niveau empty -> nothing to do\n";
  exit(0);
}

$snapshotRows = [];
$activityRows = [];

// 2) pour chaque user : total cumul + snapshot + delta
foreach ($temps as $r) {
  $userId = $r['user_id'] ?? null;
  if (!$userId) continue;

  // total cumul tous niveaux
  $total = i($r['6eme'] ?? 0)
        + i($r['5eme'] ?? 0)
        + i($r['4eme'] ?? 0)
        + i($r['3eme'] ?? 0)
        + i($r['2nde'] ?? 0)
        + i($r['1ere'] ?? 0)
        + i($r['term'] ?? 0)
        + i($r['term-pc'] ?? 0);

  // 1) Snapshot du jour cible (J-1)
  $snapshotRows[] = [
    'user_id' => $userId,
    'snapshot_date' => $targetDate,
    'total_cumul_seconds' => $total,
  ];

  // 2) Snapshot précédent (< targetDate)
  $prev = sb_get(
    '/rest/v1/daily_cumul_snapshot'
    . '?select=total_cumul_seconds,snapshot_date'
    . '&user_id=eq.' . rawurlencode($userId)
    . '&snapshot_date=lt.' . rawurlencode($targetDate)
    . '&order=snapshot_date.desc'
    . '&limit=1'
  );

  $prevTotal = null;
  if (is_array($prev) && count($prev) > 0) {
    $prevTotal = (int)($prev[0]['total_cumul_seconds'] ?? 0);
  }

  // 3) Delta + init flag
  $isInit = ($prevTotal === null);
  $delta  = $isInit ? 0 : max(0, $total - $prevTotal);

  // 4) Active si delta >= seuil ET pas init
  $isActive = (!$isInit) && ($delta >= ACTIVE_THRESHOLD_SECONDS);

  // 5) daily_activity (1 ligne par user et par date)
  $activityRows[] = [
    'user_id' => $userId,
    'activity_date' => $targetDate,
    'seconds_spent' => $delta,
    ];

    // Debug : log des premiers rows pour vérifier que les données sont correctes avant upsert
  if (count($activityRows) == 1 ) {
    file_put_contents('/tmp/debug_payload.json', json_encode($activityRows[0], JSON_PRETTY_PRINT)); 
    error_log("[DEBUG] Payload saved to /tmp/debug_payload.json");
  }
}

// 3) upsert bulk (par paquets pour éviter trop gros payload)
$chunkSize = 250;

echo "[INFO] upsert daily_cumul_snapshot rows=" . count($snapshotRows) . "\n";
for ($i = 0; $i < count($snapshotRows); $i += $chunkSize) {
  sb_upsert('daily_cumul_snapshot', array_slice($snapshotRows, $i, $chunkSize));
}

echo "[INFO] upsert daily_activity rows=" . count($activityRows) . "\n";
for ($i = 0; $i < count($activityRows); $i += $chunkSize) {
  sb_upsert('daily_activity', array_slice($activityRows, $i, $chunkSize));
}

echo "=== END sync_daily_activity ===\n";
