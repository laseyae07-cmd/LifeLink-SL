<?php

require_once dirname(dirname(__DIR__)) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$group = $_GET['blood_group'] ?? '';
$params = [];
$filter = '';

if ($group !== '' && in_array($group, blood_groups(), true)) {
    $filter = ' AND dp.blood_group = ?';
    $params[] = $group;
}

$sql = "SELECT dp.district, COUNT(*) total
        FROM donor_profiles dp
        JOIN users u ON u.id = dp.user_id
        LEFT JOIN donor_notification_preferences p ON p.donor_id = u.id
        WHERE dp.availability = 'available'
          AND dp.eligibility_status = 'eligible'
          AND dp.consent_status = 'granted'
          AND u.status = 'active'
          AND u.email_verified_at IS NOT NULL
          AND u.phone_verified_at IS NOT NULL
          AND COALESCE(p.emergency_alerts, 1) = 1
          $filter
        GROUP BY dp.district";

$stmt = db()->prepare($sql);
$stmt->execute($params);

$counts = [];

foreach ($stmt as $r) {
    $counts[$r['district']] = (int) $r['total'];
}

$out = [];

foreach (districts() as $d) {
    $n = $counts[$d] ?? 0;

    $out[] = [
        'district' => $d,
        'count' => $n < 3 ? null : $n
    ];
}

echo json_encode($out);