<?php

require_once dirname(__DIR__) . '/src/bootstrap.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

$hash = token_hash($token);

$stmt = db()->prepare(
    'SELECT
        ei.*,
        e.blood_group,
        e.district,
        e.status AS emergency_status,
        h.name AS hospital_name
     FROM emergency_invites ei
     JOIN emergency_requests e
        ON e.id = ei.emergency_id
     JOIN hospitals h
        ON h.id = e.hospital_id
     WHERE ei.response_token_hash = ?
     LIMIT 1'
);

$stmt->execute([
    $hash
]);

$invite = $stmt->fetch();

$message = null;
$error = null;

if (request_method('POST')) {

    Csrf::verify();

    try {

        (new EmergencyService())->respond(
            $token,
            $_POST['response'] ?? ''
        );

        $message = ($_POST['response'] ?? '') === 'accepted'
            ? 'Thank you. Your acceptance has been recorded. Please follow hospital instructions and medical screening requirements.'
            : 'Your decline has been recorded. Thank you for responding.';

    } catch (Throwable $e) {

        $error = $e->getMessage();
    }
}

$pageTitle = 'Emergency Response';

require dirname(__DIR__) . '/src/templates/header.php';

?>

<div class="response-panel">

    <?php if ($message): ?>

        <h1>Response recorded</h1>

        <p>
            <?= e($message) ?>
        </p>

    <?php elseif (!$invite): ?>

        <h1>Invalid link</h1>

        <p>
            This emergency response link is not valid.
        </p>

    <?php else: ?>

        <div class="eyebrow">
            Emergency donor request
        </div>

        <h1>
            <?= e($invite['blood_group']) ?> blood needed
        </h1>

        <p>
            <strong>
                <?= e($invite['hospital_name']) ?>
            </strong>
            ·
            <?= e($invite['district']) ?>
        </p>

        <p class="small">
            Accepting indicates that you are willing to be contacted/attend.
            Final donation eligibility is determined by medical staff.
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
                    name="token"
                    value="<?= e($token) ?>"
                >

                <button
                    class="btn btn-success"
                    name="response"
                    value="accepted"
                >
                    Accept Request
                </button>

                <button
                    class="btn btn-muted"
                    name="response"
                    value="declined"
                >
                    Decline
                </button>

            </form>

        <?php else: ?>

            <p>
                <span class="badge">
                    <?= e(strtoupper($invite['status'])) ?>
                </span>
            </p>

        <?php endif; ?>

    <?php endif; ?>

</div>

<?php

require dirname(__DIR__) . '/src/templates/footer.php';

?>
