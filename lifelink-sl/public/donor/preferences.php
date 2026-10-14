<?php

require_once dirname(dirname(__DIR__)) . '/src/bootstrap.php';

$user = Auth::requireRole('donor');

$stmt = db()->prepare(
    'SELECT * FROM donor_notification_preferences WHERE donor_id=?'
);

$stmt->execute([(int) $user['id']]);

$p = $stmt->fetch();

if (!$p) {

    db()->prepare(
        'INSERT INTO donor_notification_preferences(donor_id) VALUES(?)'
    )->execute([
        (int) $user['id']
    ]);

    $stmt->execute([
        (int) $user['id']
    ]);

    $p = $stmt->fetch();
}

if (request_method('POST')) {

    Csrf::verify();

    $vals = [];

    foreach (
        [
            'emergency_alerts',
            'same_district_only',
            'sms_alerts',
            'email_alerts',
            'campaign_alerts'
        ] as $k
    ) {
        $vals[$k] = isset($_POST[$k]) ? 1 : 0;
    }

    db()->prepare(
        'UPDATE donor_notification_preferences
         SET emergency_alerts=?,
             same_district_only=?,
             sms_alerts=?,
             email_alerts=?,
             campaign_alerts=?
         WHERE donor_id=?'
    )->execute([
        $vals['emergency_alerts'],
        $vals['same_district_only'],
        $vals['sms_alerts'],
        $vals['email_alerts'],
        $vals['campaign_alerts'],
        (int) $user['id']
    ]);

    audit(
        'donor_preferences_updated',
        'user',
        (int) $user['id'],
        $vals
    );

    flash(
        'success',
        'Notification preferences updated.'
    );

    redirect('donor/preferences.php');
}

$pageTitle = 'Notification Preferences';

require dirname(dirname(__DIR__)) . '/src/templates/header.php';

?>

<section class="dashboard">
    <div class="container">

        <div class="dash-head">
            <div>
                <div class="eyebrow">Donor workspace</div>

                <h1>Notification preferences</h1>

                <p class="page-note">
                    Choose how the academic prototype may contact you.
                    Emergency alerts can be paused at any time.
                </p>
            </div>
        </div>

        <?= donor_nav() ?>

        <div class="form-card" style="margin:0">

            <form method="post">

                <?= Csrf::field() ?>

                <label class="check-row">
                    <input
                        type="checkbox"
                        name="emergency_alerts"
                        <?= $p['emergency_alerts'] ? 'checked' : '' ?>
                    >

                    <span>
                        <strong>Emergency alerts</strong><br>

                        <span class="small">
                            Allow matching emergency invitations.
                        </span>
                    </span>
                </label>

                <label class="check-row">
                    <input
                        type="checkbox"
                        name="same_district_only"
                        <?= $p['same_district_only'] ? 'checked' : '' ?>
                    >

                    <span>
                        <strong>Same district only</strong><br>

                        <span class="small">
                            Do not include my record in Stage 2
                            wider-district outreach.
                        </span>
                    </span>
                </label>

                <label class="check-row">
                    <input
                        type="checkbox"
                        name="sms_alerts"
                        <?= $p['sms_alerts'] ? 'checked' : '' ?>
                    >

                    <span>
                        <strong>SMS alerts</strong>
                    </span>
                </label>

                <label class="check-row">
                    <input
                        type="checkbox"
                        name="email_alerts"
                        <?= $p['email_alerts'] ? 'checked' : '' ?>
                    >

                    <span>
                        <strong>Email alerts</strong>
                    </span>
                </label>

                <label class="check-row">
                    <input
                        type="checkbox"
                        name="campaign_alerts"
                        <?= $p['campaign_alerts'] ? 'checked' : '' ?>
                    >

                    <span>
                        <strong>Campaign information</strong>
                    </span>
                </label>

                <button class="btn mt-2">
                    Save Preferences
                </button>

            </form>

        </div>
    </div>
</section>

<?php

require dirname(dirname(__DIR__)) . '/src/templates/footer.php';

?>