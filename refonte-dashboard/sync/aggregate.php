<?php
/**
 * aggregate.php
 * 
 * Script exécuté chaque nuit par GitHub Actions.
 * Objectif :
 *  - Lire les sessions fermées dans sessions_raw
 *  - Agréger par user + date
 *  - Alimenter progress_daily
 *  - Mettre à jour progress_cumul
 *  - Calculer streak 7 jours
 *  - Calculer % régularité mensuelle
 */

date_default_timezone_set('Europe/Paris');

$SUPABASE_URL         = getenv('SUPABASE_URL');
$SUPABASE_SERVICE_KEY = getenv('SUPABASE_SERVICE_KEY');

if (!$SUPABASE_URL || !$SUPABASE_SERVICE_KEY) {
    echo "Missing Supabase environment variables\n";
    exit(1);
}

function supabase_get($path) {
    global $SUPABASE_URL, $SUPABASE_SERVICE_KEY;

    $ch = curl_init($SUPABASE_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "apikey: $SUPABASE_SERVICE_KEY",
            "Authorization: Bearer $SUPABASE_SERVICE_KEY",
            "Accept: application/json"
        ]
    ]);

    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http >= 400) {
        echo "GET ERROR $path\n$res\n";
        return null;
    }

    return json_decode($res, true);
}

function supabase_upsert($table, $payload) {
    global $SUPABASE_URL, $SUPABASE_SERVICE_KEY;

    $ch = curl_init("$SUPABASE_URL/rest/v1/$table");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "apikey: $SUPABASE_SERVICE_KEY",
            "Authorization: Bearer $SUPABASE_SERVICE_KEY",
            "Content-Type: application/json",
            "Prefer: resolution=merge-duplicates"
        ]
    ]);

    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http >= 400) {
        echo "UPSERT ERROR ($table)\n$res\n";
        return false;
    }

    return true;
}

echo "=== AGGREGATION START ===\n";

// On agrège la veille (J-1)
$targetDate = (new DateTime('yesterday'))->format('Y-m-d');
echo "Target date = $targetDate\n";

/* ---------------------------------------------------------
   1) RÉCUPÉRER TOUTES LES SESSIONS FERMÉES DE LA VEILLE
--------------------------------------------------------- */

$sessions = supabase_get(
    "/rest/v1/sessions_raw"
    . "?select=*"
    . "&end_ts=not.is.null"
    . "&start_ts=gte." . strtotime("$targetDate 00:00:00")
    . "&start_ts=lte." . strtotime("$targetDate 23:59:59")
);

if (!$sessions) {
    echo "No sessions found for $targetDate\n";
    exit(0);
}

echo "Sessions found: " . count($sessions) . "\n";

/* ---------------------------------------------------------
   2) AGRÉGER PAR USER + DATE
--------------------------------------------------------- */

$agg = []; // [user_id => total_seconds]

foreach ($sessions as $s) {
    $uid = $s['user_id'];
    $sec = (int)$s['duration_sec'];

    if (!isset($agg[$uid])) {
        $agg[$uid] = 0;
    }
    $agg[$uid] += $sec;
}

/* ---------------------------------------------------------
   3) INSÉRER DANS progress_daily
--------------------------------------------------------- */

foreach ($agg as $uid => $sec) {
    $active = $sec >= 1800 ? true : false;

    $payload = [
        "user_id"        => $uid,
        "activity_date"  => $targetDate,
        "seconds_spent"  => $sec,
        "is_active"      => $active,
        "date_maj"       => date('c')
    ];

    supabase_upsert("progress_daily", $payload);
}

echo "Inserted into progress_daily\n";

/* ---------------------------------------------------------
   4) METTRE À JOUR progress_cumul
--------------------------------------------------------- */

foreach ($agg as $uid => $sec) {

    // Récupérer cumul total
    $history = supabase_get(
        "/rest/v1/progress_daily"
        . "?select=seconds_spent"
        . "&user_id=eq.$uid"
    );

    $cumul = 0;
    if ($history) {
        foreach ($history as $h) {
            $cumul += (int)$h['seconds_spent'];
        }
    }

    $payload = [
        "user_id"             => $uid,
        "snapshot_date"       => $targetDate,
        "total_cumul_seconds" => $cumul,
        "date_maj"            => date('c')
    ];

    supabase_upsert("progress_cumul", $payload);
}

echo "Updated progress_cumul\n";

/* ---------------------------------------------------------
   5) CALCUL STREAK + % RÉGULARITÉ
--------------------------------------------------------- */

foreach ($agg as $uid => $sec) {

    /* ---- STREAK 7 JOURS ---- */
    $streak = 0;
    $cursor = new DateTime($targetDate);

    for ($i = 0; $i < 7; $i++) {
        $d = $cursor->format('Y-m-d');

        $day = supabase_get(
            "/rest/v1/progress_daily"
            . "?select=is_active"
            . "&user_id=eq.$uid"
            . "&activity_date=eq.$d"
        );

        if (!$day || !$day[0]['is_active']) break;

        $streak++;
        $cursor->modify('-1 day');
    }

    /* ---- % RÉGULARITÉ MENSUELLE ---- */
    $monthStart = (new DateTime($targetDate))->modify('first day of this month')->format('Y-m-d');
    $monthEnd   = $targetDate;

    $month = supabase_get(
        "/rest/v1/progress_daily"
        . "?select=is_active"
        . "&user_id=eq.$uid"
        . "&activity_date=gte.$monthStart"
        . "&activity_date=lte.$monthEnd"
    );

    $daysActive = 0;
    if ($month) {
        foreach ($month as $m) {
            if ($m['is_active']) $daysActive++;
        }
    }

    $daysElapsed = (new DateTime($monthStart))->diff(new DateTime($monthEnd))->days + 1;
    $regularityPct = $daysElapsed > 0 ? round($daysActive * 100 / $daysElapsed) : 0;

    echo "User $uid → streak=$streak, reg=$regularityPct%\n";

    // On met à jour progress_cumul
    supabase_upsert("progress_cumul", [
        "user_id"             => $uid,
        "snapshot_date"       => $targetDate,
        "total_cumul_seconds" => $cumul,
        "streak_jours"        => $streak,
        "streak_mois_pct"     => $regularityPct,
        "date_maj"            => date('c')
    ]);
}

echo "=== AGGREGATION DONE ===\n";
