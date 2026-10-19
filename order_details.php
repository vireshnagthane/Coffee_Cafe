<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

$user = require_auth();
$isAdmin = $user['role'] === 'administrator';
$orderId = filter_var($_GET['id'] ?? $_POST['id'] ?? null, FILTER_VALIDATE_INT);
if (!$orderId) {
    http_response_code(400);
    exit('A valid order ID is required.');
}
$error = null;
$order = null;
$items = [];
$currency = 'INR';
$receiptSettings = [
    'cafe_name' => 'Coffee Shop',
    'address' => '',
    'phone' => '',
    'receipt_footer' => 'Thank you for visiting!',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Only administrators can cancel orders.');
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } elseif ((string) ($_POST['action'] ?? '') !== 'cancel') {
        $error = 'Invalid order action.';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $orderQuery = $pdo->prepare('SELECT id, order_number, status, payment_status FROM orders WHERE id = :id FOR UPDATE');
            $orderQuery->execute(['id' => $orderId]);
            $lockedOrder = $orderQuery->fetch();
            if (!$lockedOrder) {
                throw new InvalidArgumentException('That order no longer exists.');
            }
            if ($lockedOrder['status'] !== 'completed') {
                throw new InvalidArgumentException('Only a completed order can be cancelled. This order is already cancelled.');
            }
            $movementQuery = $pdo->prepare(
                "SELECT ingredient_id, SUM(quantity) AS quantity
                 FROM ingredient_stock_movements
                 WHERE reference_type = 'order' AND reference_id = :order_id
                   AND direction = 'out' AND reason = 'sale'
                 GROUP BY ingredient_id ORDER BY ingredient_id"
            );
            $movementQuery->execute(['order_id' => $orderId]);
            $restoreItems = $movementQuery->fetchAll();
            $restoreStock = $pdo->prepare('UPDATE ingredients SET current_stock = current_stock + :quantity WHERE id = :id');
            $restoreMovement = $pdo->prepare(
                "INSERT INTO ingredient_stock_movements
                 (ingredient_id, direction, reason, quantity, reference_type, reference_id, user_id, notes)
                 VALUES (:ingredient_id, 'in', 'sale_cancellation', :quantity,
                         'order_cancel', :reference_id, :user_id, :notes)"
            );
            foreach ($restoreItems as $restoreItem) {
                $quantity = number_format((float) $restoreItem['quantity'], 3, '.', '');
                $restoreStock->execute(['quantity' => $quantity, 'id' => $restoreItem['ingredient_id']]);
                $restoreMovement->execute([
                    'ingredient_id' => $restoreItem['ingredient_id'],
                    'quantity' => $quantity,
                    'reference_id' => $orderId,
                    'user_id' => $user['id'],
                    'notes' => 'Stock restored for cancelled order ' . $lockedOrder['order_number'],
                ]);
            }
            $cancel = $pdo->prepare(
                "UPDATE orders SET status = 'cancelled',
                 payment_status = CASE WHEN payment_status = 'paid' THEN 'refunded' ELSE payment_status END,
                 cancelled_at = NOW() WHERE id = :id AND status = 'completed'"
            );
            $cancel->execute(['id' => $orderId]);
            if ($cancel->rowCount() !== 1) {
                throw new RuntimeException('The order status changed before cancellation completed.');
            }
            $pdo->commit();
            set_flash('success', 'Order cancelled. Any ingredient stock deducted for the sale was restored once.');
            redirect('/order_details.php?id=' . $orderId);
        } catch (InvalidArgumentException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[Coffee Management] Order cancellation failed: ' . $exception->getMessage());
            $error = 'The order could not be cancelled. No changes were kept; check the application error log.';
        }
    }
}

try {
    $pdo = db();
    $settingsQuery = $pdo->query(
        "SELECT setting_key, setting_value FROM settings
         WHERE setting_key IN ('currency', 'cafe_name', 'address', 'phone', 'receipt_footer')"
    );
    foreach ($settingsQuery->fetchAll() as $setting) {
        if ($setting['setting_key'] === 'currency') {
            $currency = $setting['setting_value'];
        } elseif (array_key_exists($setting['setting_key'], $receiptSettings)) {
            $receiptSettings[$setting['setting_key']] = $setting['setting_value'];
        }
    }
    $statement = $pdo->prepare(
        'SELECT o.*, c.name AS customer_name, c.phone AS customer_phone,
                u.full_name AS cashier_name
         FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
         INNER JOIN users u ON u.id = o.user_id WHERE o.id = :id'
    );
    $statement->execute(['id' => $orderId]);
    $order = $statement->fetch() ?: null;
    if ($order) {
        $itemQuery = $pdo->prepare(
            'SELECT product_name_snapshot, quantity, unit_price, line_total
             FROM order_items WHERE order_id = :id ORDER BY id'
        );
        $itemQuery->execute(['id' => $orderId]);
        $items = $itemQuery->fetchAll();
    }
} catch (Throwable $exception) {
    error_log('[Coffee Management] Order details query failed: ' . $exception->getMessage());
    $error = 'Order details are unavailable. Confirm that the database is imported and running.';
}
if (!$order && $error === null) {
    http_response_code(404);
    exit('Order not found.');
}

$pageTitle = 'Order receipt';
require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== null): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($order): ?>
<div class="receipt-toolbar">
    <a class="button button-light" href="<?= e(APP_BASE_URL) ?>/orders.php">← Orders</a>
    <div class="receipt-actions">
        <?php if ($isAdmin && $order['status'] === 'completed'): ?>
            <form method="post" data-confirm="Cancel this order? Its recipe stock usage will be restored once.">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($orderId) ?>"><input type="hidden" name="action" value="cancel">
                <button class="button button-danger" type="submit">Cancel order</button>
            </form>
        <?php endif; ?>
        <button class="button button-primary" type="button" data-print>Print receipt <span>⎙</span></button>
    </div>
</div>
<article class="receipt panel">
    <?php if (str_starts_with($order['order_number'], 'DEMO-')): ?><div class="receipt-demo-label">DEMO SAMPLE SALE</div><?php endif; ?>
    <header class="receipt-header">
        <span class="receipt-logo">B</span><span class="eyebrow"><?= e($receiptSettings['cafe_name']) ?></span>
        <?php if ($receiptSettings['address'] !== ''): ?><p><?= nl2br(e($receiptSettings['address'])) ?></p><?php endif; ?>
        <?php if ($receiptSettings['phone'] !== ''): ?><p><?= e($receiptSettings['phone']) ?></p><?php endif; ?>
        <h2>Thank you for your order</h2><p>Order <?= e($order['order_number']) ?></p>
    </header>
    <div class="receipt-meta">
        <div><span>Date</span><strong><?= e((new DateTimeImmutable($order['order_date']))->format('d M Y, H:i')) ?></strong></div>
        <div><span>Customer</span><strong><?= e($order['customer_name'] ?? 'Walk-in') ?></strong></div>
        <div><span>Served by</span><strong><?= e($order['cashier_name']) ?></strong></div>
        <div><span>Payment</span><strong><?= e(strtoupper($order['payment_method'])) ?> · <?= e(ucfirst($order['payment_status'])) ?></strong></div>
        <div><span>Order status</span><strong><?= e(ucfirst($order['status'])) ?></strong></div>
    </div>
    <div class="receipt-items">
        <div class="receipt-item receipt-item-head"><span>Item</span><span>Qty</span><span>Price</span><span>Total</span></div>
        <?php foreach ($items as $item): ?><div class="receipt-item">
            <span><?= e($item['product_name_snapshot']) ?></span><span><?= e($item['quantity']) ?></span>
            <span><?= e(format_money($item['unit_price'], $currency)) ?></span><strong><?= e(format_money($item['line_total'], $currency)) ?></strong>
        </div><?php endforeach; ?>
    </div>
    <div class="receipt-totals">
        <div><span>Subtotal</span><strong><?= e(format_money($order['subtotal'], $currency)) ?></strong></div>
        <?php if ((float) $order['discount'] > 0): ?><div><span>Discount</span><strong>−<?= e(format_money($order['discount'], $currency)) ?></strong></div><?php endif; ?>
        <div><span>Tax</span><strong><?= e(format_money($order['tax'], $currency)) ?></strong></div>
        <div class="receipt-grand-total"><span>Total</span><strong><?= e(format_money($order['final_total'], $currency)) ?></strong></div>
    </div>
    <p class="receipt-thanks"><?= e($receiptSettings['receipt_footer']) ?><br>Payment methods are recorded manually in this local system.</p>
</article>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
