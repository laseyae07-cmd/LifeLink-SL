<?php

require_once dirname(__DIR__) . '/src/bootstrap.php';

$service = new EmergencyService();

$stage2 = max(1, (int) setting('stage2_minutes', '10'));
$stage3 = max($stage2 + 1, (int) setting('stage3_minutes', '20'));

db()->exec(
    "UPDATE emergency_invites
     SET status='expired'
     WHERE status='pending' AND expires_at < NOW()"
);

db()->exec(
    "UPDATE emergency_requests
     SET status='expired', closed_at=NOW()
     WHERE status='open' AND expires_at < NOW()"
);

$sql = "SELECT e.id, e.required_donors, e.created_at,
        COALESCE(MAX(es.level), 0) current_level,
        (SELECT COUNT(*)
         FROM emergency_invites i
         WHERE i.emergency_id = e.id
         AND i.status = 'accepted') accepted
        FROM emergency_requests e
        LEFT JOIN emergency_escalations es ON es.emergency_id = e.id
        WHERE e.status = 'open'
        GROUP BY e.id, e.required_donors, e.created_at
        HAVING accepted < e.required_donors";

$rows = db()->query($sql)->fetchAll();

foreach ($rows as $r) {
    $elapsed = (int) floor((time() - strtotime($r['created_at'])) / 60);
    $level = (int) $r['current_level'];
    $next = null;

    if ($level < 2 && $elapsed >= $stage2) {
        $next = 2;
    }

    if ($level < 3 && $elapsed >= $stage3) {
        $next = 3;
    }

    if ($next) {
        $result = $service->dispatchLevel((int) $r['id'], $next);

        echo 'Emergency #' . $r['id'] .
             ' level ' . $next . ': ' .
             $result['sent'] . " actions created\n";
    }
}
