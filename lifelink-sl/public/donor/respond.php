<?php

require_once dirname(dirname(__DIR__)) . '/src/bootstrap.php';

$user = Auth::requireRole('donor');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$q = db()->prepare(
    'SELECT
        ei.*,
        e.blood_group,
        e.district,
        e.notes,
        e.status emergency_status,
        h.name hospital_name
     FROM emergency_invites ei
     JOIN emergency_requests e
        ON e.id = ei.emergency_id
     JOIN hospitals h
        ON h.id = e.hospital_id
     WHERE ei.id = ?
       AND ei.donor_id = ?'
);

$q->execute([
    $id,
    (int) $user['id']
]);

$invite = $q->fetch();

if (!$invite) {
    http_response_code(404);
    exit('Invitation not found.');
}

$error = null;

if (request_method('POST')) {

    Csrf::verify();

    try {

        (new EmergencyService())->respondForDonor(
            $id,
            (int) $user['id'],
            $_POST['response'] ?? ''
        );

        flash(
            'success',
            'Your response has been recorded.'
        );

        redirect('donor/dashboard.php');

    } catch (Throwable $e) {

        $error = $e->getMessage();
    }
}

$pageTitle = 'Respond to Request';

require dirname(dirname(__DIR__)) . '/src/templates/header.php';

?>

<section class="dashboard">
    <div class="container">

        <?= donor_nav() ?>

        <div
            class="response-panel"
            style="margin:20px auto"
        >

            <div class="eyebrow">
                Emergency invitation
            </div>

            <h1>
                <?= e($invite['blood_group']) ?> blood request
            </h1>

            <p>
                <strong>
                    <?= e($invite['hospital_name']) ?>
                </strong>
                ·
                <?= e($invite['district']) ?>
            </p>

            <p>
                <?= nl2br(e($invite['notes'] ?? '')) ?>
            </p>

            <p class="small">
                Accepting means you are willing to attend/contact
                the hospital. Medical staff still complete normal
                eligibility checks before donation.
            </p>

            <?php if ($error): ?>

                <div class="error-list">
                    <?= e($error) ?>
                </div>

            <?php endif; ?>

            <?php if (
                $invite['status'] === 'pending'
                && $invite['emergency_status'] === 'open'
            ): ?>

                <form
                    method="post"
                    class="response-actions"
                >

                    <?= Csrf::field() ?>

                    <input
                        type="hidden"
                        name="id"
                        value="<?= e((string) $id) ?>"
                    >

                    <button
                        class="btn btn-success"
                        name="response"
                        value="accepted"
                    >
                        I am available
                    </button>

                    <button
                        class="btn btn-muted"
                        name="response"
                        value="declined"
                    >
                        Not available
                    </button>

                </form>

            <?php else: ?>

                <span class="badge">
                    <?= e($invite['status']) ?>
                </span>

            <?php endif; ?>

        </div>
    </div>
</section>

<?php

require dirname(dirname(__DIR__)) . '/src/templates/footer.php';

?>
