<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

$user = require_auth();
$currency = 'INR';
$dashboardError = null;
$handoverError = null;
$handoverNotes = [];
$handoverInput = '';
$metrics = [
    'sales' => 0,
    'orders' => 0,
    'products' => 0,
    'low_stock' => 0,
    'pending' => 0,
];
$recentOrders = [];
$bestSellers = [];
$salesLabels = [];
$salesValues = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $handoverError = 'Your form expired. Refresh the page and try again.';
    } elseif ($action === 'add_handover_note') {
        $handoverInput = trim((string) ($_POST['note'] ?? ''));
        if ($handoverInput === '' || mb_strlen($handoverInput) > 500) {
            $handoverError = 'Enter a note of 1 to 500 characters.';
        } else {
            try {
                $statement = db()->prepare(
                    'INSERT INTO shift_handover_notes (note, user_id)
                     VALUES (:note, :user_id)'
                );
                $statement->execute([
                    'note' => $handoverInput,
                    'user_id' => $user['id'],
                ]);
                set_flash('success', 'Shift note shared with the team.');
                redirect('/dashboard.php');
            } catch (Throwable $exception) {
                error_log('[Coffee Management] Handover note creation failed: ' . $exception->getMessage());
                $handoverError = 'Shift notes could not be saved. Confirm the database is up to date and check the application error log.';
            }
        }
    } elseif ($action === 'resolve_handover_note') {
        $noteId = filter_var($_POST['note_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$noteId || $noteId < 1) {
            $handoverError = 'Select a valid shift note.';
        } else {
            try {
                $statement = db()->prepare(
                    'UPDATE shift_handover_notes
                     SET resolved_at = CURRENT_TIMESTAMP, resolved_by = :user_id
                     WHERE id = :id AND resolved_at IS NULL'
                );
                $statement->execute([
                    'user_id' => $user['id'],
                    'id' => $noteId,
                ]);
                if ($statement->rowCount() === 0) {
                    $handoverError = 'This note was already completed or is no longer available.';
                } else {
                    set_flash('success', 'Shift note marked complete.');
                    redirect('/dashboard.php');
                }
            } catch (Throwable $exception) {
                error_log('[Coffee Management] Handover note completion failed: ' . $exception->getMessage());
                $handoverError = 'The shift note could not be updated. Confirm the database is up to date and check the application error log.';
            }
        }
    } else {
        $handoverError = 'Choose a valid shift note action.';
    }
}

try {
    $pdo = db();
    $settings = $pdo->query(
        "SELECT setting_value FROM settings WHERE setting_key = 'currency'"
    )->fetchColumn();
    if (is_string($settings) && $settings !== '') {
        $currency = $settings;
    }

    $metrics['sales'] = (float) $pdo->query(
        "SELECT COALESCE(SUM(final_total), 0)
         FROM orders
         WHERE status = 'completed' AND payment_status = 'paid'
           AND order_date >= CURDATE() AND order_date < CURDATE() + INTERVAL 1 DAY"
    )->fetchColumn();
    $metrics['orders'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM orders
         WHERE status = 'completed'
           AND order_date >= CURDATE() AND order_date < CURDATE() + INTERVAL 1 DAY"
    )->fetchColumn();
    $metrics['products'] = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $metrics['low_stock'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM ingredients WHERE current_stock <= minimum_stock'
    )->fetchColumn();
    $metrics['pending'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM orders WHERE payment_status = 'pending' AND status = 'completed'"
    )->fetchColumn();

    $recentOrders = $pdo->query(
        'SELECT o.id, o.order_number, o.order_date, o.final_total,
                o.payment_method, o.payment_status, c.name AS customer_name
         FROM orders o
         LEFT JOIN customers c ON c.id = o.customer_id
         ORDER BY o.order_date DESC, o.id DESC LIMIT 6'
    )->fetchAll();
    $bestSellers = $pdo->query(
        "SELECT oi.product_name_snapshot AS product_name, SUM(oi.quantity) AS units_sold
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         WHERE o.status = 'completed'
         GROUP BY oi.product_id, oi.product_name_snapshot
         ORDER BY units_sold DESC, product_name ASC LIMIT 5"
    )->fetchAll();

    $salesByDay = $pdo->query(
        "SELECT DATE(order_date) AS sales_day, SUM(final_total) AS sales_total
         FROM orders
         WHERE status = 'completed' AND payment_status = 'paid'
           AND order_date >= CURDATE() - INTERVAL 6 DAY
           AND order_date < CURDATE() + INTERVAL 1 DAY
         GROUP BY DATE(order_date)"
    )->fetchAll();
    $salesMap = [];
    foreach ($salesByDay as $row) {
        $salesMap[$row['sales_day']] = (float) $row['sales_total'];
    }
    for ($offset = 6; $offset >= 0; $offset--) {
        $day = new DateTimeImmutable("-{$offset} days");
        $key = $day->format('Y-m-d');
        $salesLabels[] = $day->format('D');
        $salesValues[] = $salesMap[$key] ?? 0.0;
    }
} catch (Throwable $exception) {
    error_log('[Coffee Management] Dashboard query failed: ' . $exception->getMessage());
    $dashboardError = 'Dashboard data is unavailable. Confirm that the database schema is imported and MySQL is running.';
}

try {
    $handoverNotes = db()->query(
        'SELECT n.id, n.note, n.created_at, u.full_name
         FROM shift_handover_notes n
         INNER JOIN users u ON u.id = n.user_id
         WHERE n.resolved_at IS NULL
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT 8'
    )->fetchAll();
} catch (Throwable $exception) {
    error_log('[Coffee Management] Handover notes query failed: ' . $exception->getMessage());
    $handoverError = 'Shift notes are unavailable. Import database/migrations/001_shift_handover_notes.sql and refresh this page.';
}

$pageTitle = 'Overview';
require __DIR__ . '/includes/header.php';
?>
<section class="welcome-row">
    <div>
        <h2>Good day, <?= e(explode(' ', trim((string) $user['full_name']))[0] ?: 'there') ?> <span aria-hidden="true">☕</span></h2>
        <p>Here’s what’s happening in your cafe today.</p>
    </div>
    <div class="date-pill"><?= e((new DateTimeImmutable())->format('l, d M Y')) ?></div>
</section>
<div class="demo-data-note">DEMO dataset: orders with a <strong>DEMO-</strong> number are sample sales, not real customer transactions.</div>

<?php if ($dashboardError !== null): ?>
    <div class="alert alert-error" role="alert"><?= e($dashboardError) ?></div>
<?php endif; ?>
<?php if ($handoverError !== null): ?>
    <div class="alert alert-error" role="alert"><?= e($handoverError) ?></div>
<?php endif; ?>

<section class="metric-grid" aria-label="Today's key figures">
    <article class="metric-card">
        <div class="metric-heading"><span>Today’s sales</span><span class="metric-icon icon-sand">₹</span></div>
        <strong><?= e(format_money($metrics['sales'], $currency)) ?></strong>
        <small>Completed, paid orders</small>
    </article>
    <article class="metric-card">
        <div class="metric-heading"><span>Orders today</span><span class="metric-icon icon-mint">↗</span></div>
        <strong><?= e($metrics['orders']) ?></strong>
        <small>Completed orders</small>
    </article>
    <article class="metric-card">
        <div class="metric-heading"><span>Products</span><span class="metric-icon icon-lilac">◈</span></div>
        <strong><?= e($metrics['products']) ?></strong>
        <small>Products in the catalogue</small>
    </article>
    <article class="metric-card">
        <div class="metric-heading"><span>Low stock</span><span class="metric-icon icon-peach">!</span></div>
        <strong><?= e($metrics['low_stock']) ?></strong>
        <small>Ingredients at or below minimum</small>
    </article>
</section>

<section class="content-grid">
    <article class="panel sales-panel">
        <div class="panel-heading">
            <div><span class="eyebrow">PERFORMANCE</span><h3>Sales overview</h3></div>
            <span class="subtle-tag">Last 7 days</span>
        </div>
        <div class="chart-wrap">
            <canvas id="sales-chart" aria-label="Paid sales for the last seven days" role="img"
                    data-labels="<?= e(json_encode($salesLabels, JSON_THROW_ON_ERROR)) ?>"
                    data-values="<?= e(json_encode($salesValues, JSON_THROW_ON_ERROR)) ?>"></canvas>
            <p class="empty-chart" id="chart-empty" <?= array_sum($salesValues) > 0 ? 'hidden' : '' ?>>Sales activity will appear here once orders are recorded.</p>
        </div>
    </article>
    <article class="panel">
        <div class="panel-heading">
            <div><span class="eyebrow">AT A GLANCE</span><h3>Needs attention</h3></div>
        </div>
        <div class="attention-list">
            <div><span class="attention-icon icon-peach">!</span><span><strong>Low-stock ingredients</strong><small>At or below minimum level</small></span><b><?= e($metrics['low_stock']) ?></b></div>
            <div><span class="attention-icon icon-sand">◷</span><span><strong>Pending payments</strong><small>Completed orders awaiting payment</small></span><b><?= e($metrics['pending']) ?></b></div>
        </div>
    </article>
</section>

<section class="panel handover-panel">
    <div>
        <div class="panel-heading">
            <div><span class="eyebrow">TEAM HANDOVER</span><h3>Shift notes</h3></div>
        </div>
        <p class="form-hint">Leave a short update for the next worker, such as an item running low or a task to finish.</p>
        <form method="post" class="stacked-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_handover_note">
            <label for="handover-note">Note</label>
            <textarea id="handover-note" name="note" rows="3" maxlength="500" required><?= e($handoverInput) ?></textarea>
            <button class="button button-primary" type="submit">Share shift note</button>
        </form>
    </div>
    <div>
        <div class="panel-heading">
            <div><span class="eyebrow">OPEN UPDATES</span><h3>For the next shift</h3></div>
        </div>
        <?php if ($handoverNotes === []): ?>
            <div class="empty-state compact"><strong>No open shift notes</strong><p>New team updates will appear here.</p></div>
        <?php else: ?>
            <div class="handover-list">
                <?php foreach ($handoverNotes as $note): ?>
                    <article class="handover-note">
                        <p><?= nl2br(e($note['note'])) ?></p>
                        <div class="handover-note-footer">
                            <small><?= e($note['full_name']) ?> · <?= e((new DateTimeImmutable($note['created_at']))->format('d M, H:i')) ?></small>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="resolve_handover_note">
                                <input type="hidden" name="note_id" value="<?= e($note['id']) ?>">
                                <button class="text-button" type="submit">Mark done</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="content-grid lower-grid">
    <article class="panel table-panel">
        <div class="panel-heading">
            <div><span class="eyebrow">LATEST ACTIVITY</span><h3>Recent orders</h3></div>
            <span class="subtle-tag">Latest 6</span>
        </div>
        <?php if ($recentOrders === []): ?>
            <div class="empty-state"><span aria-hidden="true">☕</span><strong>No orders yet</strong><p>Once your first sale is recorded, it will show up here.</p></div>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Order</th><th>Customer</th><th>Time</th><th>Payment</th><th class="align-right">Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentOrders as $order): ?>
                        <tr>
                            <td><strong><?= e($order['order_number']) ?></strong></td>
                            <td><?= e($order['customer_name'] ?? 'Walk-in') ?></td>
                            <td><?= e((new DateTimeImmutable($order['order_date']))->format('d M, H:i')) ?></td>
                            <td><span class="status-chip"><?= e(ucfirst($order['payment_status'])) ?></span></td>
                            <td class="align-right"><?= e(format_money($order['final_total'], $currency)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
    <article class="panel best-panel">
        <div class="panel-heading">
            <div><span class="eyebrow">CUSTOMER FAVOURITES</span><h3>Best sellers</h3></div>
        </div>
        <?php if ($bestSellers === []): ?>
            <div class="empty-state compact"><span aria-hidden="true">✦</span><strong>No sales data yet</strong><p>Best sellers appear after completed orders.</p></div>
        <?php else: ?>
            <ol class="seller-list">
                <?php foreach ($bestSellers as $index => $product): ?>
                    <li><span class="seller-rank"><?= e($index + 1) ?></span><span class="seller-name"><?= e($product['product_name']) ?></span><span class="seller-count"><?= e($product['units_sold']) ?> sold</span></li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
