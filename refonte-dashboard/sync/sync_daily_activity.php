<?php

require_once __DIR__ . '/../config/config.php';

if (!getenv('SUPABASE_URL') || !getenv('SUPABASE_SERVICE_KEY')) {
  die("!! .env manquant ou SUPABASE_URL / SUPABASE_SERVICE_KEY non définis\n"); 
}

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

function sb_get_all(string $path): array {
  $all    = [];
  $offset = 0;
  $limit  = 1000;

  while (true) {
    $separator = str_contains($path, '?') ? '&' : '?';
    $batch = sb_get($path . $separator . "limit=$limit&offset=$offset");

    if (empty($batch)) break;

    $all = array_merge($all, $batch);

    if (count($batch) < $limit) break;
    $offset += $limit;
  }

  return $all;
}

function sb_upsert(string $table, array $rows): void {
  global $SUPABASE_URL;

  $ch = curl_init(rtrim($SUPABASE_URL, '/') . "/rest/v1/$table");
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => json_encode($rows),
    CURLOPT_HTTPHEADER => sb_headers(["Prefer: resolution=merge-duplicates"]),
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
$temps = sb_get_all('/rest/v1/temps_niveau?select=user_id,"6eme","5eme","4eme","3eme","2nde","1ere",term,"term-pc"');

echo "[INFO] temps_niveau rows=" . count($temps) . "\n";
if (count($temps) === 0) {
  echo "[WARN] temps_niveau empty -> nothing to do\n";
  exit(0);
}

$snapshotRows = [];
$activityRows = [];

// Récupérer tous les derniers snapshots en une seule requête
$allPrevSnapshots = sb_get_all(
    '/rest/v1/daily_cumul_snapshot'
    . '?select=user_id,total_cumul_seconds,snapshot_date'
    . '&snapshot_date=lt.' . rawurlencode($targetDate)
    . '&snapshot_date=gte.' . rawurlencode((new DateTime($targetDate))->modify('-32 days')->format('Y-m-d'))
    . '&order=snapshot_date.desc'
);

// Indexer par user_id en gardant uniquement le plus récent
$prevByUser = [];
foreach ($allPrevSnapshots as $row) {
    $uid = $row['user_id'];
    if (!isset($prevByUser[$uid])) {
        $prevByUser[$uid] = (int)$row['total_cumul_seconds'];
    }
}

$firstDayOfMonth = (new DateTime($targetDate))->modify('first day of this month')->format('Y-m-d');

$allActiveDays = sb_get_all(
    '/rest/v1/daily_activity'
    . '?select=user_id,activity_date'
    . '&activity_date=gte.' . rawurlencode($firstDayOfMonth)
    . '&activity_date=lte.' . rawurlencode($targetDate)
);

$activeDaysByUser = [];
foreach ($allActiveDays as $row) {
    $uid = $row['user_id'];
    $activeDaysByUser[$uid] = ($activeDaysByUser[$uid] ?? 0) + 1;
}

$daysInMonth = (int)(new DateTime($targetDate))->format('t');

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

  $streakJours   = $activeDaysByUser[$userId] ?? 0;
  $streakMoisPct = $daysInMonth > 0 ? (int)round(($streakJours / $daysInMonth) * 100) : 0;

  // 1) Snapshot du jour cible (J-1)
  $snapshotRows[] = [
    'user_id'             => $userId,
    'snapshot_date'       => $targetDate,
    'total_cumul_seconds' => $total,
    'streak_jours'        => $streakJours,
    'streak_mois_pct'     => $streakMoisPct,
  ];

  // 2) Snapshot précédent (< targetDate)
  $prevTotal = $prevByUser[$userId] ?? null;

  // 3) Delta + init flag
  $isInit = ($prevTotal === null);
  $delta  = $isInit ? 0 : max(0, $total - $prevTotal);

  // 5) daily_activity (1 ligne par user et par date)
  if ($delta >= ACTIVE_THRESHOLD_SECONDS) {
      $activityRows[] = [
        'user_id'      => $userId,
        'activity_date' => $targetDate,
        'seconds_spent' => $delta,
    ];
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