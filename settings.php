<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

$user = require_role('administrator');
$error = null;
$settings = [
    'cafe_name' => 'Coffee Shop',
    'address' => '',
    'phone' => '',
    'currency' => 'INR',
    'tax_rate' => '0.00',
    'receipt_footer' => 'Thank you for visiting!',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        $input = [];
        foreach (array_keys($settings) as $key) {
            $input[$key] = trim((string) ($_POST[$key] ?? ''));
        }
        $taxRate = filter_var($input['tax_rate'], FILTER_VALIDATE_FLOAT);
        if ($input['cafe_name'] === '' || mb_strlen($input['cafe_name']) > 150 ||
            mb_strlen($input['address']) > 1000 || mb_strlen($input['phone']) > 40 ||
            mb_strlen($input['receipt_footer']) > 500 ||
            $taxRate === false || $taxRate < 0 || $taxRate > 100 ||
            !in_array($input['currency'], ['INR', 'USD', 'EUR', 'GBP'], true)) {
            $error = 'Enter a café name, valid tax rate, supported currency and contact details within the indicated limits.';
        } else {
            try {
                $pdo = db();
                $pdo->beginTransaction();
                $statement = $pdo->prepare(
                    'INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
                );
                foreach ($input as $key => $value) {
                    $statement->execute([
                        'key' => $key,
                        'value' => $key === 'tax_rate' ? number_format((float) $taxRate, 2, '.', '') : $value,
                    ]);
                }
                $pdo->commit();
                set_flash('success', 'Cafe settings saved.');
                redirect('/settings.php');
            } catch (Throwable $exception) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[Coffee Management] Settings save failed: ' . $exception->getMessage());
                $error = 'Settings could not be saved. Check the application error log.';
            }
        }
    }
}

try {
    $statement = db()->query('SELECT setting_key, setting_value FROM settings');
    foreach ($statement->fetchAll() as $setting) {
        if (array_key_exists($setting['setting_key'], $settings)) {
            $settings[$setting['setting_key']] = $setting['setting_value'];
        }
    }
} catch (Throwable $exception) {
    error_log('[Coffee Management] Settings query failed: ' . $exception->getMessage());
    $error = 'Settings are unavailable. Confirm that the database is imported and running.';
}

$pageTitle = 'Settings';
require __DIR__ . '/includes/header.php';
?>
<section class="welcome-row"><div><h2>Cafe settings</h2><p>Set the business details and checkout tax applied to every order.</p></div></section>
<?php if ($error !== null): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="panel settings-panel">
    <div class="panel-heading"><div><span class="eyebrow">BUSINESS PROFILE</span><h3>General settings</h3></div></div>
    <form method="post" class="stacked-form settings-form">
        <?= csrf_field() ?>
        <label for="cafe-name">Cafe name</label><input id="cafe-name" name="cafe_name" maxlength="150" required value="<?= e($settings['cafe_name']) ?>">
        <label for="address">Address</label><textarea id="address" name="address" rows="2" maxlength="1000"><?= e($settings['address']) ?></textarea>
        <label for="phone">Phone</label><input id="phone" name="phone" maxlength="40" value="<?= e($settings['phone']) ?>">
        <label for="currency">Currency</label><select id="currency" name="currency"><?php foreach (['INR', 'USD', 'EUR', 'GBP'] as $currency): ?><option value="<?= e($currency) ?>" <?= $settings['currency'] === $currency ? 'selected' : '' ?>><?= e($currency) ?></option><?php endforeach; ?></select>
        <label for="tax-rate">Tax rate (%)</label><input id="tax-rate" name="tax_rate" type="number" min="0" max="100" step="0.01" required value="<?= e($settings['tax_rate']) ?>">
        <p class="form-hint">Tax is calculated on subtotal minus discount and added to the bill (exclusive tax). Updating the rate affects new orders only.</p>
        <label for="receipt-footer">Receipt footer</label><textarea id="receipt-footer" name="receipt_footer" rows="2" maxlength="500"><?= e($settings['receipt_footer']) ?></textarea>
        <button class="button button-primary" type="submit">Save settings</button>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
