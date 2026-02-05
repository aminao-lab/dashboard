<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/supabase.php';

date_default_timezone_set('Europe/Paris');

function sb_url(string $path): string {
  return rtrim(SUPABASE_URL, '/') . $path;
}

function sb_headers(bool $mergeDuplicates = false): array {
  $h = [
    'apikey: ' . SUPABASE_SERVICE_KEY,
    'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
    'Content-Type: application/json',
    'Accept: application/json',
  ];
  if ($mergeDuplicates) $h[] = 'Prefer: resolution=merge-duplicates';
  return $h;
}

function sb_get(string $path, array $query): array {
  $url = sb_url($path) . '?' . http_build_query($query);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'GET',
    CURLOPT_HTTPHEADER     => sb_headers(false),
    CURLOPT_TIMEOUT        => 30,
  ]);
  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($resp === false) throw new Exception("GET curl error: $err");
  if ($http >= 400) throw new Exception("GET Supabase error $http: $resp");

  $json = json_decode($resp, true);
  return is_array($json) ? $json : [];
}

function sb_upsert(string $path, array $rows): void {
  $ch = curl_init(sb_url($path));
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_HTTPHEADER     => sb_headers(true),
    CURLOPT_POSTFIELDS     => json_encode($rows),
    CURLOPT_TIMEOUT        => 30,
  ]);
  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($resp === false) throw new Exception("UPSERT curl error: $err");
  if ($http >= 400) throw new Exception("UPSERT Supabase error $http: $resp");
}

function i($v): int {
  return is_numeric($v) ? (int)$v : 0;
}

// Date du jour Europe/Paris
$tz = new DateTimeZone('Europe/Paris');
$target = new DateTime('now', $tz);
$target->modify('-1 day');
$targetDate = $target->format('Y-m-d');

echo "[INFO] === START sync_daily_activity (today=$targetDate) ===\n";

// 1) Lire temps_niveau (source cumul)
$temps = sb_get('/rest/v1/temps_niveau', [
  'select' => 'user_id,"6eme","5eme","4eme","3eme","2nde","1ere",term,"term-pc"'
]);

echo "[INFO] temps_niveau rows: " . count($temps) . "\n";

foreach ($temps as $r) {
  $userId = $r['user_id'] ?? null;
  if (!$userId) continue;

  // 2) Total cumul
  $total = i($r['6eme'] ?? 0)
        + i($r['5eme'] ?? 0)
        + i($r['4eme'] ?? 0)
        + i($r['3eme'] ?? 0)
        + i($r['2nde'] ?? 0)
        + i($r['1ere'] ?? 0)
        + i($r['term'] ?? 0)
        + i($r['term-pc'] ?? 0);

  // 3) Sauver snapshot du jour (upsert)
  try {
    sb_upsert('/rest/v1/daily_cumul_snapshot', [[
      'user_id' => $userId,
      'snapshot_date' => $targetDate,
      'total_cumul_seconds' => $total
    ]]);
  } catch (Exception $e) {
    echo "[WARN] snapshot upsert failed user=$userId : {$e->getMessage()}\n";
    continue;
  }


    // 4) Récupérer le dernier snapshot < today (pas forcément "hier")
    $prev = sb_get('/rest/v1/daily_cumul_snapshot', [
    'select' => 'total_cumul_seconds,snapshot_date',
    'user_id' => 'eq.' . $userId,
    'snapshot_date' => 'lt.' . $targetDate,
    'order' => 'snapshot_date.desc',
    'limit' => 1
    ]);

    if (count($prev) === 0) {
    // 1ère exécution pour ce user => on initialise, on ne crédite pas l'historique sur aujourd'hui
    $delta = 0;
    } else {
    $prevCumul = i($prev[0]['total_cumul_seconds'] ?? 0);
    $delta = $total - $prevCumul;
    if ($delta < 0) $delta = 0;
    }



  // 6) Upsert daily_activity (idempotent)
  try {
    sb_upsert('/rest/v1/daily_activity', [[
      'user_id' => $userId,
      'activity_date' => $targetDate,
      'seconds_spent' => $delta
    ]]);
  } catch (Exception $e) {
    echo "[WARN] daily_activity upsert failed user=$userId : {$e->getMessage()}\n";
    continue;
  }
}

echo "[INFO] === END sync_daily_activity ===\n";
