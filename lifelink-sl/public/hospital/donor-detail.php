<?php

require_once dirname(dirname(__DIR__)) . '/src/bootstrap.php';

$user = Auth::requireRole('hospital', 'admin');
$inviteId = (int) ($_GET['invite_id'] ?? 0);

$sql = "SELECT ei.id invite_id, ei.emergency_id, ei.status invite_status,
        e.hospital_id, u.id donor_id, u.full_name, u.email, u.phone,
        dp.blood_group, dp.district, dp.availability, dp.eligibility_status,
        dp.consent_status, dp.last_donation_date
        FROM emergency_invites ei
        JOIN emergency_requests e ON e.id = ei.emergency_id
        JOIN users u ON u.id = ei.donor_id
        JOIN donor_profiles dp ON dp.user_id = u.id
        WHERE ei.id = ?";

$q = db()->prepare($sql);
$q->execute([$inviteId]);
$d = $q->fetch();

if (!$d) {
    http_response_code(404);
    exit('Record not found.');
}

if ($user['role'] === 'hospital') {
    $h = current_hospital($user);

    if (!$h || (int) $h['id'] !== (int) $d['hospital_id']) {
        http_response_code(403);
        exit('Forbidden');
    }
}

if ($d['invite_status'] !== 'accepted' || $d['consent_status'] !== 'granted') {
    security_event(
        'donor_detail_denied',
        'warning',
        'Protected donor-detail access denied.',
        (int) $user['id']
    );

    http_response_code(403);
    exit('Donor details are not available for this match.');
}

audit('donor_detail_viewed', 'user', (int) $d['donor_id'], [
    'emergency_id' => (int) $d['emergency_id'],
    'invite_id' => $inviteId
]);

$pageTitle = 'Accepted Donor Details';
require dirname(dirname(__DIR__)) . '/src/templates/header.php';

?>

<section class="dashboard">
    <div class="container">

        <?= $user['role'] === 'hospital' ? hospital_nav($user) : admin_nav() ?>

        <div class="response-panel" style="margin:20px auto">
            <div class="eyebrow">Controlled detail disclosure</div>

            <h1><?= e($d['full_name']) ?></h1>

            <p>
                <strong><?= e($d['blood_group']) ?></strong>
                · <?= e($d['district']) ?>
            </p>

            <div class="card">
                <p>
                    <strong>Phone:</strong> <?= e($d['phone']) ?><br>
                    <strong>Email:</strong> <?= e($d['email']) ?><br>
                    <strong>Last donation:</strong>
                    <?= e($d['last_donation_date'] ?? 'Not recorded') ?>
                </p>
            </div>

            <div class="danger-note">
                These details are shown only because this donor accepted the request
                and current donor consent remains granted. Hospital staff must complete
                normal clinical screening before donation.
            </div>

            <a class="btn btn-outline"
               href="<?= e(url('hospital/emergency-view.php?id=' . (int) $d['emergency_id'])) ?>">
                Back to request
            </a>
        </div>

    </div>
</section>

<?php require dirname(dirname(__DIR__)) . '/src/templates/footer.php'; ?>
