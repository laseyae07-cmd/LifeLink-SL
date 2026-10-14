<?php

require_once dirname(dirname(__DIR__)) . '/src/bootstrap.php';

$user = Auth::requireRole('hospital', 'admin');

$id = (int) ($_GET['id'] ?? 0);

$service = new EmergencyService();

$e = $service->get($id);

if (!$e) {
    http_response_code(404);
    exit('Emergency not found');
}

if ($user['role'] === 'hospital') {

    $h = current_hospital($user);

    if (
        !$h ||
        (int) $e['hospital_id'] !== (int) $h['id']
    ) {
        http_response_code(403);
        exit('Forbidden');
    }
}

if (request_method('POST')) {

    Csrf::verify();

    $action = $_POST['action'] ?? '';

    if (
        $action === 'escalate' &&
        $e['status'] === 'open'
    ) {

        $m = db()->prepare(
            'SELECT COALESCE(MAX(level), 0)
             FROM emergency_escalations
             WHERE emergency_id = ?'
        );

        $m->execute([$id]);

        $next = min(
            3,
            (int) $m->fetchColumn() + 1
        );

        if ($next <= 3) {

            $r = $service->dispatchLevel(
                $id,
                $next
            );

            flash(
                'success',
                'Escalation Stage ' .
                $next .
                ' processed: ' .
                $r['sent'] .
                ' coordination actions created.'
            );
        }

        redirect(
            'hospital/emergency-view.php?id=' . $id
        );
    }

    if (
        in_array(
            $action,
            ['close', 'cancel'],
            true
        ) &&
        $e['status'] === 'open'
    ) {

        $status = $action === 'close'
            ? 'closed'
            : 'cancelled';

        db()->prepare(
            "UPDATE emergency_requests
             SET status = ?,
                 closed_at = NOW()
             WHERE id = ?
               AND status = 'open'"
        )->execute([
            $status,
            $id
        ]);

        audit(
            'emergency_' . $status,
            'emergency',
            $id
        );

        flash(
            'success',
            'Emergency request updated.'
        );

        redirect(
            'hospital/emergency-view.php?id=' . $id
        );
    }
}

$stats = $service->stats($id);

$inv = db()->prepare(
    'SELECT
        ei.*,
        u.full_name,
        dp.district,
        dp.consent_status
     FROM emergency_invites ei
     JOIN users u
        ON u.id = ei.donor_id
     JOIN donor_profiles dp
        ON dp.user_id = u.id
     WHERE ei.emergency_id = ?
     ORDER BY ei.sent_at DESC'
);

$inv->execute([$id]);

$invites = $inv->fetchAll();

$ref = db()->prepare(
    'SELECT
        r.*,
        h.name target_name,
        h.district target_district
     FROM hospital_referrals r
     JOIN hospitals h
        ON h.id = r.target_hospital_id
     WHERE r.emergency_id = ?
     ORDER BY r.created_at DESC'
);

$ref->execute([$id]);

$refs = $ref->fetchAll();

$pageTitle = 'Emergency #' . $id;

require dirname(dirname(__DIR__)) . '/src/templates/header.php';

?>

<section class="dashboard">
    <div
        class="container"
        data-emergency-live
        data-endpoint="<?= e(
            url('api/emergency-stats.php?id=' . $id)
        ) ?>"
    >

        <div class="dash-head">

            <div>

                <div class="eyebrow">
                    <span class="live-dot"></span>
                    Live emergency dashboard
                </div>

                <h1>
                    Emergency #<?= e((string) $id) ?>
                    ·
                    <?= e($e['blood_group']) ?>
                </h1>

                <p>
                    <?= e($e['hospital_name']) ?>
                    ·
                    <?= e($e['district']) ?>
                    ·
                    <strong data-emergency-status>
                        <?= e(strtoupper($e['status'])) ?>
                    </strong>
                </p>

                <p class="small">
                    Expires:
                    <?= e($e['expires_at']) ?>
                </p>

            </div>

            <?php if ($e['status'] === 'open'): ?>

                <form
                    method="post"
                    class="mini-actions"
                >

                    <?= Csrf::field() ?>

                    <button
                        class="btn"
                        name="action"
                        value="escalate"
                    >
                        Escalate Now
                    </button>

                    <button
                        class="btn btn-outline"
                        name="action"
                        value="close"
                    >
                        Close
                    </button>

                    <button
                        class="btn btn-muted"
                        name="action"
                        value="cancel"
                    >
                        Cancel
                    </button>

                </form>

            <?php endif; ?>

        </div>

        <?php
        if ($user['role'] === 'hospital') {
            echo hospital_nav($user);
        } else {
            echo admin_nav();
        }
        ?>

        <div class="metrics">

            <div class="metric">
                <span>Invited donors</span>

                <strong data-stat="invited">
                    <?= e((string) $stats['invited']) ?>
                </strong>
            </div>

            <div class="metric">
                <span>Accepted</span>

                <strong data-stat="accepted">
                    <?= e((string) $stats['accepted']) ?>
                </strong>
            </div>

            <div class="metric">
                <span>Pending</span>

                <strong data-stat="pending">
                    <?= e((string) $stats['pending']) ?>
                </strong>
            </div>

            <div class="metric">
                <span>Hospital referrals</span>

                <strong>
                    <?= e((string) $stats['referrals']) ?>
                </strong>
            </div>

        </div>

        <div class="card">

            <h3>
                Request summary
            </h3>

            <p>
                <strong>Required donors:</strong>
                <?= e((string) $e['required_donors']) ?>

                &nbsp;

                <strong>Units:</strong>
                <?= e((string) $e['units_needed']) ?>

                &nbsp;

                <strong>Urgency:</strong>
                <?= e(ucfirst($e['urgency'])) ?>
            </p>

            <p>
                <?= nl2br(e($e['notes'] ?? '')) ?>
            </p>

        </div>

        <h2>
            Donor responses
        </h2>

        <div class="table-wrap">

            <table class="table">

                <thead>
                    <tr>
                        <th>Donor</th>
                        <th>District</th>
                        <th>Stage</th>
                        <th>Status</th>
                        <th>Responded</th>
                        <th>Details</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (!$invites): ?>

                        <tr>
                            <td
                                colspan="6"
                                class="empty"
                            >
                                No donor invitations have been created yet.
                            </td>
                        </tr>

                    <?php endif; ?>

                    <?php foreach ($invites as $i): ?>

                        <tr>

                            <td>
                                <?= e(
                                    $i['status'] === 'accepted'
                                        ? 'Donor #' . (string) $i['donor_id']
                                        : 'Donor #' . (string) $i['donor_id']
                                ) ?>
                            </td>

                            <td>
                                <?= e($i['district']) ?>
                            </td>

                            <td>
                                <?= e(
                                    (string) $i['escalation_level']
                                ) ?>
                            </td>

                            <td>

                                <span
                                    class="badge <?= $i['status'] === 'accepted'
                                        ? 'green'
                                        : (
                                            $i['status'] === 'declined'
                                                ? 'red'
                                                : 'amber'
                                        ) ?>"
                                >
                                    <?= e($i['status']) ?>
                                </span>

                            </td>

                            <td>
                                <?= e(
                                    $i['responded_at'] ?? '—'
                                ) ?>
                            </td>

                            <td>

                                <?php if (
                                    $i['status'] === 'accepted' &&
                                    $i['consent_status'] === 'granted'
                                ): ?>

                                    <a
                                        href="<?= e(
                                            url(
                                                'hospital/donor-detail.php?invite_id=' .
                                                (int) $i['id']
                                            )
                                        ) ?>"
                                    >
                                        View permitted details
                                    </a>
                                <?php else: ?>
                                    Restricted

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

        <?php if ($refs): ?>

            <h2>
                Stage 3 hospital referrals
            </h2>

            <div class="table-wrap">

                <table class="table">

                    <thead>
                        <tr>
                            <th>Hospital</th>
                            <th>District</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($refs as $r): ?>

                            <tr>

                                <td>
                                    <?= e($r['target_name']) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $r['target_district']
                                    ) ?>
                                </td>

                                <td>

                                    <span
                                        class="badge <?= $r['status'] === 'accepted'
                                            ? 'green'
                                            : (
                                                $r['status'] === 'declined'
                                                    ? 'red'
                                                    : 'amber'
                                            ) ?>"
                                    >
                                        <?= e($r['status']) ?>
                                    </span>

                                </td>

                                <td>
                                    <?= e($r['created_at']) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>
</section>

<?php

require dirname(dirname(__DIR__)) . '/src/templates/footer.php';

?>
