<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

require_role('administrator');
$fromDate = (string) ($_GET['from'] ?? date('Y-m-01'));
$toDate = (string) ($_GET['to'] ?? date('Y-m-d'));
$error = null;
$currency = 'INR';
$salesTotal = 0.0;
$orderCount = 0;
$dailySales = [];
$bestSellers = [];
$categorySales = [];
$paymentSummary = [];
$stockStatus = [];
$purchaseSummary = [];
$expenseSummary = [];

$isValidDate = static function (string $value): bool {
    $parsed = DateTime::createFromFormat('!Y-m-d', $value);
    return $parsed !== false && $parsed->format('Y-m-d') === $value;
};

if (!$isValidDate($fromDate) || !$isValidDate($toDate) || $fromDate > $toDate) {
    $error = 'Choose valid dates, with the start date on or before the end date.';
}

if ($error === null) {
    try {
        $pdo = db();
        $currencyValue = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'currency'")->fetchColumn();
        if (is_string($currencyValue) && $currencyValue !== '') {
            $currency = $currencyValue;
        }
        $salesParams = ['from_date' => $fromDate . ' 00:00:00', 'to_date' => $toDate . ' 00:00:00'];
        $summary = $pdo->prepare(
            "SELECT COALESCE(SUM(final_total), 0) AS total_sales, COUNT(*) AS order_count
             FROM orders WHERE status = 'completed' AND payment_status = 'paid'
               AND order_date >= :from_date AND order_date < DATE_ADD(:to_date, INTERVAL 1 DAY)"
        );
        $summary->execute($salesParams);
        $summaryRow = $summary->fetch();
        $salesTotal = (float) $summaryRow['total_sales'];
        $orderCount = (int) $summaryRow['order_count'];

        $daily = $pdo->prepare(
            "SELECT DATE(order_date) AS report_date, COUNT(*) AS order_count,
                    SUM(final_total) AS sales_total
             FROM orders WHERE status = 'completed' AND payment_status = 'paid'
               AND order_date >= :from_date AND order_date < DATE_ADD(:to_date, INTERVAL 1 DAY)
             GROUP BY DATE(order_date) ORDER BY report_date DESC"
        );
        $daily->execute($salesParams);
        $dailySales = $daily->fetchAll();

        $best = $pdo->prepare(
            "SELECT oi.product_name_snapshot AS product_name, SUM(oi.quantity) AS units_sold,
                    SUM(oi.line_total) AS sales_total
             FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id
             WHERE o.status = 'completed' AND o.payment_status = 'paid'
               AND o.order_date >= :from_date AND o.order_date < DATE_ADD(:to_date, INTERVAL 1 DAY)
             GROUP BY oi.product_id, oi.product_name_snapshot
             ORDER BY units_sold DESC LIMIT 10"
        );
        $best->execute($salesParams);
        $bestSellers = $best->fetchAll();

        $categories = $pdo->prepare(
            "SELECT c.name AS category_name, SUM(oi.quantity) AS units_sold,
                    SUM(oi.line_total) AS sales_total
             FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id
             INNER JOIN products p ON p.id = oi.product_id
             INNER JOIN categories c ON c.id = p.category_id
             WHERE o.status = 'completed' AND o.payment_status = 'paid'
               AND o.order_date >= :from_date AND o.order_date < DATE_ADD(:to_date, INTERVAL 1 DAY)
             GROUP BY c.id, c.name ORDER BY sales_total DESC"
        );
        $categories->execute($salesParams);
        $categorySales = $categories->fetchAll();

        $payments = $pdo->prepare(
            "SELECT payment_method, COUNT(*) AS order_count, SUM(final_total) AS sales_total
             FROM orders WHERE status = 'completed' AND payment_status = 'paid'
               AND order_date >= :from_date AND order_date < DATE_ADD(:to_date, INTERVAL 1 DAY)
             GROUP BY payment_method ORDER BY sales_total DESC"
        );
        $payments->execute($salesParams);
        $paymentSummary = $payments->fetchAll();

        $stockStatus = $pdo->query(
            'SELECT i.name, i.current_stock, i.minimum_stock, i.unit, i.cost_per_unit,
                    s.name AS supplier_name
             FROM ingredients i LEFT JOIN suppliers s ON s.id = i.supplier_id
             ORDER BY (i.current_stock <= i.minimum_stock) DESC, i.name'
        )->fetchAll();

        $purchases = $pdo->prepare(
            "SELECT p.purchase_number, p.purchase_date, p.status, p.total_amount,
                    s.name AS supplier_name
             FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id
             WHERE p.purchase_date >= :from_date AND p.purchase_date < DATE_ADD(:to_date, INTERVAL 1 DAY)
             ORDER BY p.purchase_date DESC LIMIT 200"
        );
        $purchases->execute($salesParams);
        $purchaseSummary = $purchases->fetchAll();

        $expenses = $pdo->prepare(
            'SELECT category, COUNT(*) AS entry_count, SUM(amount) AS total_amount
             FROM expenses WHERE expense_date >= :from_date AND expense_date <= :to_date
             GROUP BY category ORDER BY total_amount DESC'
        );
        $expenses->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
        $expenseSummary = $expenses->fetchAll();
    } catch (Throwable $exception) {
        error_log('[Coffee Management] Report query failed: ' . $exception->getMessage());
        $error = 'Reports are unavailable. Confirm that the database is imported and running.';
    }
}

$pageTitle = 'Reports';
require __DIR__ . '/includes/header.php';
?>
<section class="welcome-row"><div><h2>Business reports</h2><p>Review recorded sales, stock, purchases and expenses.</p></div><button class="button button-light no-print" type="button" data-print>Print reports <span>⎙</span></button></section>
<div class="demo-data-note">DEMO dataset: totals include sample orders with a <strong>DEMO-</strong> number.</div>
<section class="panel report-filter no-print">
    <form class="filter-bar" method="get"><label for="report-from">From</label><input id="report-from" type="date" name="from" required value="<?= e($fromDate) ?>"><label for="report-to">To</label><input id="report-to" type="date" name="to" required value="<?= e($toDate) ?>"><button class="button button-primary" type="submit">Run report</button></form>
</section>
<?php if ($error !== null): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($error === null): ?>
<section class="report-summary-grid">
    <article class="metric-card"><div class="metric-heading"><span>Paid sales</span><span class="metric-icon icon-sand">₹</span></div><strong><?= e(format_money($salesTotal, $currency)) ?></strong><small>Completed and paid orders in selected period</small></article>
    <article class="metric-card"><div class="metric-heading"><span>Paid orders</span><span class="metric-icon icon-mint">↗</span></div><strong><?= e($orderCount) ?></strong><small>Selected date range</small></article>
    <article class="metric-card"><div class="metric-heading"><span>Expense categories</span><span class="metric-icon icon-peach">−</span></div><strong><?= e(count($expenseSummary)) ?></strong><small>Categories with recorded expenses</small></article>
    <article class="metric-card"><div class="metric-heading"><span>Low stock</span><span class="metric-icon icon-lilac">!</span></div><strong><?= e(count(array_filter($stockStatus, static fn(array $row): bool => (float) $row['current_stock'] <= (float) $row['minimum_stock']))) ?></strong><small>Ingredients at or below minimum</small></article>
</section>
<section class="content-grid report-grid">
    <article class="panel table-panel"><div class="panel-heading"><div><span class="eyebrow">DAILY SALES</span><h3>Sales by date</h3></div></div>
        <?php if ($dailySales === []): ?><div class="empty-state compact"><strong>No sales in this period</strong></div><?php else: ?><div class="table-scroll"><table><thead><tr><th>Date</th><th>Paid orders</th><th class="align-right">Sales</th></tr></thead><tbody><?php foreach ($dailySales as $row): ?><tr><td><?= e((new DateTimeImmutable($row['report_date']))->format('d M Y')) ?></td><td><?= e($row['order_count']) ?></td><td class="align-right"><?= e(format_money($row['sales_total'], $currency)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </article>
    <article class="panel table-panel"><div class="panel-heading"><div><span class="eyebrow">TOP MENU ITEMS</span><h3>Best-selling products</h3></div></div>
        <?php if ($bestSellers === []): ?><div class="empty-state compact"><strong>No product sales in this period</strong></div><?php else: ?><div class="table-scroll"><table><thead><tr><th>Product</th><th>Units</th><th class="align-right">Sales</th></tr></thead><tbody><?php foreach ($bestSellers as $row): ?><tr><td><strong><?= e($row['product_name']) ?></strong></td><td><?= e($row['units_sold']) ?></td><td class="align-right"><?= e(format_money($row['sales_total'], $currency)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </article>
</section>
<section class="content-grid report-grid">
    <article class="panel table-panel"><div class="panel-heading"><div><span class="eyebrow">MENU PERFORMANCE</span><h3>Sales by category</h3></div></div>
        <?php if ($categorySales === []): ?><div class="empty-state compact"><strong>No category sales in this period</strong></div><?php else: ?><div class="table-scroll"><table><thead><tr><th>Category</th><th>Units</th><th class="align-right">Sales</th></tr></thead><tbody><?php foreach ($categorySales as $row): ?><tr><td><strong><?= e($row['category_name']) ?></strong></td><td><?= e($row['units_sold']) ?></td><td class="align-right"><?= e(format_money($row['sales_total'], $currency)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </article>
    <article class="panel table-panel"><div class="panel-heading"><div><span class="eyebrow">TENDER MIX</span><h3>Payment method summary</h3></div></div>
        <?php if ($paymentSummary === []): ?><div class="empty-state compact"><strong>No payments in this period</strong></div><?php else: ?><div class="table-scroll"><table><thead><tr><th>Method</th><th>Orders</th><th class="align-right">Sales</th></tr></thead><tbody><?php foreach ($paymentSummary as $row): ?><tr><td><strong><?= e(strtoupper($row['payment_method'])) ?></strong></td><td><?= e($row['order_count']) ?></td><td class="align-right"><?= e(format_money($row['sales_total'], $currency)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </article>
</section>
<section class="panel table-panel inventory-list"><div class="panel-heading"><div><span class="eyebrow">CURRENT INVENTORY</span><h3>Ingredient stock status</h3></div></div>
    <?php if ($stockStatus === []): ?><div class="empty-state compact"><strong>No ingredients recorded</strong></div><?php else: ?><div class="table-scroll"><table><thead><tr><th>Ingredient</th><th>On hand</th><th>Minimum</th><th>Cost per unit</th><th>Supplier</th></tr></thead><tbody><?php foreach ($stockStatus as $row): $low=(float)$row['current_stock'] <= (float)$row['minimum_stock']; ?><tr><td><strong><?= e($row['name']) ?></strong></td><td><span class="stock-value <?= $low ? 'stock-low' : '' ?>"><?= e($row['current_stock']) ?> <?= e($row['unit']) ?></span></td><td><?= e($row['minimum_stock']) ?> <?= e($row['unit']) ?></td><td><?= e(format_money($row['cost_per_unit'], $currency)) ?></td><td><?= e($row['supplier_name'] ?? '—') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<section class="content-grid report-grid">
    <article class="panel table-panel"><div class="panel-heading"><div><span class="eyebrow">PROCUREMENT</span><h3>Purchase history</h3></div></div>
        <?php if ($purchaseSummary === []): ?><div class="empty-state compact"><strong>No purchases in this period</strong></div><?php else: ?><div class="table-scroll"><table><thead><tr><th>Date</th><th>Purchase</th><th>Supplier</th><th>Status</th><th class="align-right">Total</th></tr></thead><tbody><?php foreach ($purchaseSummary as $row): ?><tr><td><?= e((new DateTimeImmutable($row['purchase_date']))->format('d M Y')) ?></td><td><strong><?= e($row['purchase_number']) ?></strong></td><td><?= e($row['supplier_name'] ?? '—') ?></td><td><?= e(ucfirst($row['status'])) ?></td><td class="align-right"><?= e(format_money($row['total_amount'], $currency)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </article>
    <article class="panel table-panel"><div class="panel-heading"><div><span class="eyebrow">OPERATING COSTS</span><h3>Expense summary</h3></div></div>
        <?php if ($expenseSummary === []): ?><div class="empty-state compact"><strong>No expenses in this period</strong></div><?php else: ?><div class="table-scroll"><table><thead><tr><th>Category</th><th>Entries</th><th class="align-right">Total</th></tr></thead><tbody><?php foreach ($expenseSummary as $row): ?><tr><td><strong><?= e($row['category']) ?></strong></td><td><?= e($row['entry_count']) ?></td><td class="align-right"><?= e(format_money($row['total_amount'], $currency)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </article>
</section>
<p class="report-disclaimer">Sales are gross paid order totals. Expenses and purchases are shown separately; no profit figure is presented because ingredient consumption costing is not a complete accounting ledger.</p>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
