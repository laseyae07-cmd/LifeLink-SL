<?php

require_once dirname(dirname(__DIR__)) . '/src/bootstrap.php';

$user = Auth::requireRole('hospital');
$hospital = current_hospital($user);

if (!request_method('POST')) {
    redirect('hospital/network.php');
}

Csrf::verify();

$id = (int) ($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';

if (!in_array($action, ['accepted', 'declined'], true)) {
    redirect('hospital/network.php');
}

$q = db()->prepare(
    "SELECT * FROM hospital_referrals
     WHERE id = ? AND target_hospital_id = ? AND status = 'pending'"
);

$q->execute([
    $id,
    (int) $hospital['id']
]);

$r = $q->fetch();

if (!$r) {
    http_response_code(403);
    exit('Referral action not allowed.');
}

db()->prepare(
    'UPDATE hospital_referrals
     SET status = ?, responded_at = NOW()
     WHERE id = ?'
)->execute([
    $action,
    $id
]);

audit(
    'hospital_referral_' . $action,
    'referral',
    $id,
    ['emergency_id' => (int) $r['emergency_id']]
);

flash('success', 'Referral response recorded.');

redirect('hospital/network.php');