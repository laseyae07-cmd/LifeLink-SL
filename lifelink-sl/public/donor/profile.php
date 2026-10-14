<?php

require_once dirname(dirname(__DIR__)) . '/src/bootstrap.php';

$user = Auth::requireRole('donor');

$stmt = db()->prepare(
    'SELECT * FROM donor_profiles WHERE user_id=?'
);

$stmt->execute([(int) $user['id']]);

$p = $stmt->fetch();
$errors = [];

if (request_method('POST')) {

    Csrf::verify();

    $availability = $_POST['availability'] ?? '';
    $district = $_POST['district'] ?? '';
    $last = trim($_POST['last_donation_date'] ?? '');
    $consent = $_POST['consent_status'] ?? '';

    if (!in_array($availability, ['available', 'unavailable'], true)) {
        $errors[] = 'Invalid availability.';
    }

    if (!validate_district($district)) {
        $errors[] = 'Invalid district.';
    }

    if ($last !== '' && strtotime($last) === false) {
        $errors[] = 'Invalid last donation date.';
    }

    if (!in_array($consent, ['granted', 'withdrawn'], true)) {
        $errors[] = 'Invalid consent selection.';
    }

    if (!$errors) {

        db()->prepare(
            'UPDATE donor_profiles
             SET availability=?,
                 district=?,
                 last_donation_date=?,
                 consent_status=?,
                 consent_updated_at=NOW()
             WHERE user_id=?'
        )->execute([
            $availability,
            $district,
            $last ?: null,
            $consent,
            (int) $user['id']
        ]);

        audit(
            'donor_profile_updated',
            'user',
            (int) $user['id'],
            [
                'availability' => $availability,
                'consent' => $consent
            ]
        );

        flash(
            'success',
            'Profile and donor consent settings updated.'
        );

        redirect('donor/profile.php');
    }
}

$pageTitle = 'Donor Profile';

require dirname(dirname(__DIR__)) . '/src/templates/header.php';

?>

<section class="dashboard">
    <div class="container">

        <div class="dash-head">
            <div>
                <div class="eyebrow">Donor workspace</div>
                <h1>Profile, availability & consent</h1>
            </div>
        </div>

        <?= donor_nav() ?>

        <div class="form-card" style="margin:0">

            <?php if ($errors): ?>
                <div class="error-list">
                    <?= e(implode(' ', $errors)) ?>
                </div>
            <?php endif; ?>

            <form method="post">

                <?= Csrf::field() ?>

                <div class="form-grid">

                    <div class="field">
                        <label>Blood group</label>

                        <input
                            disabled
                            value="<?= e($p['blood_group']) ?>"
                        >

                        <span class="help">
                            Blood group changes require administrator verification.
                        </span>
                    </div>

                    <div class="field">
                        <label>Eligibility</label>

                        <input
                            disabled
                            value="<?= e(ucfirst($p['eligibility_status'])) ?>"
                        >
                    </div>

                    <div class="field">
                        <label>Current district</label>

                        <select name="district">
                            <?php foreach (districts() as $d): ?>

                                <option
                                    <?= $p['district'] === $d ? 'selected' : '' ?>
                                >
                                    <?= e($d) ?>
                                </option>

                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Availability</label>

                        <select name="availability">

                            <option
                                value="available"
                                <?= $p['availability'] === 'available' ? 'selected' : '' ?>
                            >
                                Available
                            </option>

                            <option
                                value="unavailable"
                                <?= $p['availability'] === 'unavailable' ? 'selected' : '' ?>
                            >
                                Unavailable
                            </option>

                        </select>
                    </div>

                    <div class="field">
                        <label>Last donation date</label>

                        <input
                            type="date"
                            name="last_donation_date"
                            value="<?= e($p['last_donation_date']) ?>"
                        >
                    </div>

                    <div class="field">
                        <label>Emergency matching consent</label>

                        <select name="consent_status">

                            <option
                                value="granted"
                                <?= $p['consent_status'] === 'granted' ? 'selected' : '' ?>
                            >
                                Granted
                            </option>

                            <option
                                value="withdrawn"
                                <?= $p['consent_status'] === 'withdrawn' ? 'selected' : '' ?>
                            >
                                Withdrawn
                            </option>

                        </select>

                        <span class="help">
                            Withdrawing consent immediately excludes your record
                            from new matching and protected contact-detail access.
                        </span>
                    </div>

                </div>

                <button class="btn">Save Changes</button>

            </form>

        </div>
    </div>
</section>

<?php

require dirname(dirname(__DIR__)) . '/src/templates/footer.php';

?>