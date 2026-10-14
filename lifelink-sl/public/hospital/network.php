<?php

require_once dirname(dirname(__DIR__)) . '/src/bootstrap.php';

$user = Auth::requireRole('hospital');
$hospital = current_hospital($user);

if (!$hospital) {
    http_response_code(403);
    exit('No active hospital assigned.');
}

$in = db()->prepare(
    "SELECT r.*, e.blood_group, e.units_needed, e.urgency, e.district,
            e.status emergency_status, h.name sender_name
     FROM hospital_referrals r
     JOIN emergency_requests e ON e.id = r.emergency_id
     JOIN hospitals h ON h.id = r.sender_hospital_id
     WHERE r.target_hospital_id = ?
     ORDER BY r.created_at DESC"
);

$in->execute([(int) $hospital['id']]);
$incoming = $in->fetchAll();

$out = db()->prepare(
    "SELECT r.*, e.blood_group, e.status emergency_status,
            h.name target_name, h.district target_district
     FROM hospital_referrals r
     JOIN emergency_requests e ON e.id = r.emergency_id
     JOIN hospitals h ON h.id = r.target_hospital_id
     WHERE r.sender_hospital_id = ?
     ORDER BY r.created_at DESC"
);

$out->execute([(int) $hospital['id']]);
$outgoing = $out->fetchAll();

$pageTitle = 'Hospital Network';

require dirname(dirname(__DIR__)) . '/src/templates/header.php';

?>

<section class="dashboard">
    <div class="container">

        <div class="dash-head">
            <div>
                <div class="eyebrow">Stage 3 coordination</div>
                <h1>Hospital referral network</h1>

                <p class="page-note">
                    When donor outreach is insufficient, Stage 3 sends a
                    coordination referral to other approved hospitals.
                </p>
            </div>
        </div>

        <?= hospital_nav($user) ?>

        <h2>Incoming referrals</h2>

        <div class="table-wrap">
            <table class="table">

                <thead>
                    <tr>
                        <th>From</th>
                        <th>Request</th>
                        <th>Urgency</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (!$incoming): ?>
                        <tr>
                            <td colspan="5" class="empty">
                                No incoming referrals.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($incoming as $r): ?>
                        <tr>

                            <td><?= e($r['sender_name']) ?></td>

                            <td>
                                <?= e($r['blood_group']) ?> ·
                                <?= e($r['district']) ?> ·
                                <?= e((string) $r['units_needed']) ?> units
                            </td>

                            <td><?= e($r['urgency']) ?></td>

                            <td>
                                <span class="badge <?= $r['status'] === 'accepted'
                                    ? 'green'
                                    : ($r['status'] === 'declined' ? 'red' : 'amber') ?>">
                                    <?= e($r['status']) ?>
                                </span>
                            </td>

                            <td>
                                <?php if (
                                    $r['status'] === 'pending' &&
                                    $r['emergency_status'] === 'open'
                                ): ?>

                                    <form method="post"
                                          action="<?= e(url('hospital/referral-action.php')) ?>"
                                          class="mini-actions">

                                        <?= Csrf::field() ?>

                                        <input type="hidden" name="id"
                                               value="<?= e((string) $r['id']) ?>">

                                        <button class="btn btn-success btn-sm"
                                                name="action" value="accepted">
                                            Accept
                                        </button>

                                        <button class="btn btn-outline btn-sm"
                                                name="action" value="declined">
                                            Decline
                                        </button>
                                    </form>

                                <?php else: ?>

                                    <a href="<?= e(url(
                                        'hospital/emergency-view.php?id=' .
                                        (int) $r['emergency_id']
                                    )) ?>">
                                        View request
                                    </a>

                                <?php endif; ?>
                            </td>

                        </tr>
                    <?php endforeach; ?>

                </tbody>
            </table>
        </div>

        <h2 class="mt-2">Outgoing referrals</h2>

        <div class="table-wrap">
            <table class="table">

                <thead>
                    <tr>
                        <th>Hospital</th>
                        <th>District</th>
                        <th>Request</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (!$outgoing): ?>
                        <tr>
                            <td colspan="5" class="empty">
                                No outgoing referrals yet.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($outgoing as $r): ?>
                        <tr>

                            <td><?= e($r['target_name']) ?></td>
                            <td><?= e($r['target_district']) ?></td>

                            <td>
                                <a href="<?= e(url(
                                    'hospital/emergency-view.php?id=' .
                                    (int) $r['emergency_id']
                                )) ?>">
                                    #<?= e((string) $r['emergency_id']) ?>
                                    · <?= e($r['blood_group']) ?>
                                </a>
                            </td>

                            <td>
                                <span class="badge <?= $r['status'] === 'accepted'
                                    ? 'green'
                                    : ($r['status'] === 'declined' ? 'red' : 'amber') ?>">
                                    <?= e($r['status']) ?>
                                </span>
                            </td>

                            <td><?= e($r['created_at']) ?></td>

                        </tr>
                    <?php endforeach; ?>

                </tbody>
            </table>
        </div>

    </div>
</section>

<?php require dirname(dirname(__DIR__)) . '/src/templates/footer.php'; ?>
