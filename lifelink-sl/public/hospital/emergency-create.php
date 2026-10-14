<?php

require_once dirname(dirname(__DIR__)) . '/src/bootstrap.php';

$user = Auth::requireRole('hospital');
$hospital = current_hospital($user);

if (!$hospital) {
    http_response_code(403);
    exit('No active hospital assigned.');
}

$errors = [];

if (request_method('POST')) {

    Csrf::verify();

    $data = [
        'blood_group' => $_POST['blood_group'] ?? '',
        'units_needed' => (int) ($_POST['units_needed'] ?? 0),
        'required_donors' => (int) ($_POST['required_donors'] ?? 0),
        'urgency' => $_POST['urgency'] ?? '',
        'district' => $_POST['district'] ?? '',
        'location_details' => trim($_POST['location_details'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
        'expiry_hours' => (int) ($_POST['expiry_hours'] ?? 12)
    ];

    if (!in_array($data['blood_group'], blood_groups(), true)) {
        $errors[] = 'Select a blood group.';
    }

    if ($data['units_needed'] < 1 || $data['units_needed'] > 100) {
        $errors[] = 'Units needed must be between 1 and 100.';
    }

    if ($data['required_donors'] < 1 || $data['required_donors'] > 100) {
        $errors[] = 'Required donors must be between 1 and 100.';
    }

    if (!in_array($data['urgency'], ['urgent', 'critical'], true)) {
        $errors[] = 'Select urgency.';
    }

    if (!validate_district($data['district'])) {
        $errors[] = 'Select a valid district.';
    }

    if ($data['expiry_hours'] < 1 || $data['expiry_hours'] > 48) {
        $errors[] = 'Request expiry must be between 1 and 48 hours.';
    }

    if (!$errors) {

        $id = (new EmergencyService())->create(
            (int) $hospital['id'],
            (int) $user['id'],
            $data
        );

        flash(
            'success',
            'Emergency created and Stage 1 local donor outreach started.'
        );

        redirect(
            'hospital/emergency-view.php?id=' . $id
        );
    }
}

$defaultDonors = max(
    1,
    (int) setting('default_required_donors', '2')
);

$defaultExpiry = max(
    1,
    (int) setting('default_request_expiry_hours', '12')
);

$pageTitle = 'Create Emergency';

require dirname(dirname(__DIR__)) . '/src/templates/header.php';

?>

<section class="dashboard">
    <div class="container">

        <div class="dash-head">
            <div>
                <div class="eyebrow">
                    Hospital workspace
                </div>

                <h1>
                    Create emergency blood request
                </h1>
            </div>
        </div>

        <?= hospital_nav($user) ?>

        <div class="danger-note">
            <strong>Clinical responsibility:</strong>
            this form coordinates outreach only.
            Do not enter patient names, diagnoses
            or detailed medical records.
        </div>

        <div class="form-card" style="margin:18px 0 0">

            <?php if ($errors): ?>
                <div class="error-list">
                    <?= e(implode(' ', $errors)) ?>
                </div>
            <?php endif; ?>

            <form method="post">

                <?= Csrf::field() ?>

                <div class="form-grid">

                    <div class="field">
                        <label>Requested blood group</label>

                        <select
                            name="blood_group"
                            required
                        >
                            <option value="">
                                Select
                            </option>

                            <?php foreach (blood_groups() as $g): ?>
                                <option>
                                    <?= e($g) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Urgency</label>

                        <select
                            name="urgency"
                            required
                        >
                            <option value="urgent">
                                Urgent
                            </option>

                            <option value="critical">
                                Critical
                            </option>
                        </select>
                    </div>

                    <div class="field">
                        <label>Units needed</label>

                        <input
                            type="number"
                            name="units_needed"
                            min="1"
                            max="100"
                            required
                        >
                    </div>

                    <div class="field">
                        <label>Target number of donors</label>

                        <input
                            type="number"
                            name="required_donors"
                            min="1"
                            max="100"
                            value="<?= e((string) $defaultDonors) ?>"
                            required
                        >
                    </div>

                    <div class="field">
                        <label>District</label>

                        <select
                            name="district"
                            required
                        >
                            <?php foreach (districts() as $d): ?>
                                <option
                                    <?= $hospital['district'] === $d
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e($d) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Request expires after</label>

                        <select name="expiry_hours">

                            <option value="6">
                                6 hours
                            </option>

                            <option
                                value="12"
                                <?= $defaultExpiry === 12
                                    ? 'selected'
                                    : '' ?>
                            >
                                12 hours
                            </option>

                            <option
                                value="24"
                                <?= $defaultExpiry === 24
                                    ? 'selected'
                                    : '' ?>
                            >
                                24 hours
                            </option>

                            <option value="48">
                                48 hours
                            </option>

                        </select>
                    </div>

                    <div class="field">
                        <label>Location details</label>

                        <input
                            name="location_details"
                            value="<?= e($hospital['address']) ?>"
                        >
                    </div>

                </div>

                <div class="field">
                    <label>
                        Practical instructions for responding donors
                    </label>

                    <textarea
                        name="notes"
                        maxlength="1000"
                        placeholder="Example: report to the hospital blood bank reception. Do not include patient-identifying information."
                    ></textarea>
                </div>

                <button class="btn">
                    Create & Start Outreach
                </button>

            </form>

        </div>
    </div>
</section>

<?php

require dirname(dirname(__DIR__)) . '/src/templates/footer.php';

?>
