<?php
require_once __DIR__ . '/../../config/supabase.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/session_check.php'; // vérifie la session et fournit $currentUserId

header('Content-Type: application/json');

try {
    if (empty($currentUserId)) {
        throw new Exception('Not authenticated');
    }

    // Date du mois courant (Europe/Paris)
    $tz = new DateTimeZone('Europe/Paris');
    $now = new DateTime('now', $tz);
    $monthStart = $now->format('Y-m-01');
    $monthEnd = $now->format('Y-m-t');


    $supabase = new SupabaseClient();

    // Compter les jours actifs du mois
    $rows = $supabase->select(
        'daily_activity',
        'activity_date',
        [
            'user_id' => "eq.$currentUserId",
            'activity_date' => "gte.$monthStart",
            'activity_date' => "lte.$monthEnd",
            'is_active' => "eq.true"
        ]
    );

    $activeDays = $rows ? count($rows) : 0;

    // Définition des paliers
    $tiers = [
        'bronze'   => 3,
        'silver'   => 6,
        'gold'     => 12,
        'platinum' => 19,
        'diamond'  => 25,
    ];

    $currentTier = null;
    $nextTier = null;

    foreach ($tiers as $tier => $minDays) {
        if ($activeDays >= $minDays) {
            $currentTier = $tier;
        } elseif ($currentTier !== null && $nextTier === null) {
            $nextTier = $tier;
            break;
        } elseif ($currentTier === null) {
            $nextTier = $tier;
            break;
        }
    }

    if ($currentTier === 'diamond') {
        $nextTier = null;
    }

    $daysToNext = null;
    if ($nextTier !== null) {
        $daysToNext = max(0, $tiers[$nextTier] - $activeDays);
    }

    echo json_encode([
        'ok' => true,
        'month' => $now->format('Y-m'),
        'active_days' => $activeDays,
        'tier' => $currentTier,
        'next_tier' => $nextTier,
        'days_to_next' => $daysToNext
    ]);

} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
