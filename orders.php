<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

require_auth();
$error = null;
$search = trim((string) ($_GET['q'] ?? ''));
$payment = (string) ($_GET['payment'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$fromDate = (string) ($_GET['from'] ?? '');
$toDate = (string) ($_GET['to'] ?? '');
$orders = [];
$currency = 'INR';

try {
    $validDate = static function (string $value): bool {
        if ($value === '') {
            return true;
        }
        $parsed = DateTime::createFromFormat('!Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    };
    if (!$validDate($fromDate) || !$validDate($toDate) || ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate)) {
        throw new InvalidArgumentException('Choose valid filter dates, with the start date on or before the end date.');
    }
    $pdo = db();
    $settings = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'currency'")->fetchColumn();
    if (is_string($settings) && $settings !== '') {
        $currency = $settings;
    }
    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = 'o.order_number LIKE :number';
        $params['number'] = '%' . $search . '%';
    }
    if (in_array($payment, ['cash', 'upi', 'card'], true)) {
        $where[] = 'o.payment_method = :payment';
        $params['payment'] = $payment;
    }
    if (in_array($status, ['completed', 'cancelled'], true)) {
        $where[] = 'o.status = :status';
        $params['status'] = $status;
    }
    if ($fromDate !== '') {
        $where[] = 'o.order_date >= :from_date';
        $params['from_date'] = $fromDate . ' 00:00:00';
    }
    if ($toDate !== '') {
        $where[] = 'o.order_date < DATE_ADD(:to_date, INTERVAL 1 DAY)';
        $params['to_date'] = $toDate . ' 00:00:00';
    }
    $sql = 'SELECT o.id, o.order_number, o.order_date, o.final_total, o.payment_method,
                   o.payment_status, o.status, c.name AS customer_name
            FROM orders o LEFT JOIN customers c ON c.id = o.customer_id';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY o.order_date DESC, o.id DESC LIMIT 250';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $orders = $statement->fetchAll();
} catch (Throwable $exception) {
    if ($exception instanceof InvalidArgumentException) {
        $error = $exception->getMessage();
    } else {
        error_log('[Coffee Management] Orders query failed: ' . $exception->getMessage());
        $error = 'Orders are unavailable. Confirm that the database is imported and running.';
    }
}

$pageTitle = 'Orders';
require __DIR__ . '/includes/header.php';
?>
<section class="welcome-row">
    <div><h2>Order history</h2><p>Review order details and printed receipts.</p></div>
    <a class="button button-primary" href="<?= e(APP_BASE_URL) ?>/pos.php">New order <span>＋</span></a>
</section>
<div class="demo-data-note">DEMO dataset: orders with a <strong>DEMO-</strong> number are sample sales, not real customer transactions.</div>
<?php if ($error !== null): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="panel table-panel">
    <form class="filter-bar" method="get">
        <input type="search" name="q" placeholder="Order number" value="<?= e($search) ?>">
        <input type="date" name="from" aria-label="From date" value="<?= e($fromDate) ?>">
        <input type="date" name="to" aria-label="To date" value="<?= e($toDate) ?>">
        <select name="payment" aria-label="Payment method"><option value="">All payments</option><?php foreach (['cash' => 'Cash', 'upi' => 'UPI', 'card' => 'Card'] as $value => $label): ?><option value="<?= e($value) ?>" <?= $payment === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
        <select name="status" aria-label="Order status"><option value="">All statuses</option><option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option><option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option></select>
        <button class="button button-light" type="submit">Filter</button>
    </form>
    <?php if ($orders === []): ?><div class="empty-state"><span>◷</span><strong>No matching orders</strong><p>Completed sales will appear in this list.</p></div>
    <?php else: ?><div class="table-scroll"><table class="orders-table">
        <thead><tr><th>Order number</th><th>Customer</th><th>Date and time</th><th>Method</th><th>Status</th><th class="align-right">Total</th><th></th></tr></thead>
        <tbody><?php foreach ($orders as $order): ?><tr>
            <td><strong><?= e($order['order_number']) ?></strong></td>
            <td><?= e($order['customer_name'] ?? 'Walk-in') ?></td>
            <td><?= e((new DateTimeImmutable($order['order_date']))->format('d M Y, H:i')) ?></td>
            <td><?= e(strtoupper($order['payment_method'])) ?></td>
            <td><span class="status-chip <?= $order['status'] === 'cancelled' ? 'status-cancelled' : '' ?>"><?= e(ucfirst($order['status'])) ?></span></td>
            <td class="align-right"><?= e(format_money($order['final_total'], $currency)) ?></td>
            <td><a class="table-action" href="<?= e(APP_BASE_URL) ?>/order_details.php?id=<?= e($order['id']) ?>">Details →</a></td>
        </tr><?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
