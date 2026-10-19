<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

$user = require_role('administrator');
$error = null;
$statusFilter = (string) ($_GET['status'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        $pdo = null;
        try {
            $pdo = db();
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'create') {
                $supplierId = filter_var($_POST['supplier_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
                $items = json_decode((string) ($_POST['items'] ?? ''), true);
                if (!is_array($items) || $items === [] || count($items) > 100) {
                    throw new InvalidArgumentException('Add one or more valid ingredients to the purchase.');
                }
                $validatedItems = [];
                $totalPaise = 0;
                $notes = trim((string) ($_POST['notes'] ?? ''));
                if (mb_strlen($notes) > 4000) {
                    throw new InvalidArgumentException('Purchase notes must be 4,000 characters or fewer.');
                }
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        throw new InvalidArgumentException('A purchase contains an invalid line item.');
                    }
                    $ingredientId = filter_var($item['ingredient_id'] ?? null, FILTER_VALIDATE_INT);
                    $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
                    $unitCost = filter_var($item['unit_cost'] ?? null, FILTER_VALIDATE_FLOAT);
                    if (!$ingredientId || $quantity === false || $quantity <= 0 ||
                        $quantity > 999999999.999 || $unitCost === false || $unitCost < 0 || $unitCost > 9999999.999) {
                        throw new InvalidArgumentException('A purchase line has an invalid ingredient, quantity or unit cost.');
                    }
                    if (isset($validatedItems[$ingredientId])) {
                        throw new InvalidArgumentException('Each ingredient can appear only once in a purchase.');
                    }
                    $validatedItems[$ingredientId] = [
                        'quantity' => (float) $quantity,
                        'unit_cost' => (float) $unitCost,
                    ];
                    $totalPaise += (int) round((float) $quantity * (float) $unitCost * 100);
                }
                if ($totalPaise > 999999999999) {
                    throw new InvalidArgumentException('Purchase total exceeds the maximum supported value.');
                }
                $pdo->beginTransaction();
                if ($supplierId !== null) {
                    $supplierCheck = $pdo->prepare('SELECT id FROM suppliers WHERE id = :id');
                    $supplierCheck->execute(['id' => $supplierId]);
                    if (!$supplierCheck->fetchColumn()) {
                        throw new InvalidArgumentException('Choose an existing supplier.');
                    }
                }
                $ids = array_keys($validatedItems);
                sort($ids, SORT_NUMERIC);
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $ingredientCheck = $pdo->prepare("SELECT id FROM ingredients WHERE id IN ($marks) ORDER BY id FOR UPDATE");
                $ingredientCheck->execute($ids);
                if (count($ingredientCheck->fetchAll()) !== count($ids)) {
                    throw new InvalidArgumentException('One or more selected ingredients no longer exist.');
                }
                $purchaseNumber = 'PUR-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $insert = $pdo->prepare(
                    "INSERT INTO purchases
                     (purchase_number, supplier_id, user_id, total_amount, status, notes)
                     VALUES (:number, :supplier, :user, :total, 'pending', :notes)"
                );
                $insert->execute([
                    'number' => $purchaseNumber,
                    'supplier' => $supplierId,
                    'user' => $user['id'],
                    'total' => number_format($totalPaise / 100, 2, '.', ''),
                    'notes' => $notes ?: null,
                ]);
                $purchaseId = (int) $pdo->lastInsertId();
                $insertItem = $pdo->prepare(
                    'INSERT INTO purchase_items
                     (purchase_id, ingredient_id, quantity, unit_cost, line_total)
                     VALUES (:purchase, :ingredient, :quantity, :unit_cost, :line_total)'
                );
                foreach ($validatedItems as $ingredientId => $item) {
                    $linePaise = (int) round($item['quantity'] * $item['unit_cost'] * 100);
                    $insertItem->execute([
                        'purchase' => $purchaseId,
                        'ingredient' => $ingredientId,
                        'quantity' => number_format($item['quantity'], 3, '.', ''),
                        'unit_cost' => number_format($item['unit_cost'], 3, '.', ''),
                        'line_total' => number_format($linePaise / 100, 2, '.', ''),
                    ]);
                }
                $pdo->commit();
                set_flash('success', 'Purchase recorded as pending. Receive it to add stock.');
                redirect('/purchases.php');
            }

            $purchaseId = filter_var($_POST['purchase_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$purchaseId) {
                throw new InvalidArgumentException('Select a valid purchase.');
            }
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id, purchase_number, status FROM purchases WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $purchaseId]);
            $purchase = $lock->fetch();
            if (!$purchase || $purchase['status'] !== 'pending') {
                throw new InvalidArgumentException('Only a pending purchase can be received or cancelled.');
            }
            if ($action === 'receive') {
                $itemsQuery = $pdo->prepare(
                    'SELECT ingredient_id, quantity, unit_cost FROM purchase_items
                     WHERE purchase_id = :id ORDER BY ingredient_id'
                );
                $itemsQuery->execute(['id' => $purchaseId]);
                $purchaseItems = $itemsQuery->fetchAll();
                if ($purchaseItems === []) {
                    throw new InvalidArgumentException('This purchase has no ingredient lines.');
                }
                $updateStock = $pdo->prepare(
                    'UPDATE ingredients SET current_stock = current_stock + :quantity,
                     cost_per_unit = :unit_cost WHERE id = :id'
                );
                $movement = $pdo->prepare(
                    "INSERT INTO ingredient_stock_movements
                     (ingredient_id, direction, reason, quantity, reference_type, reference_id, user_id, notes)
                     VALUES (:ingredient, 'in', 'purchase', :quantity, 'purchase', :purchase, :user, :notes)"
                );
                foreach ($purchaseItems as $item) {
                    $quantity = number_format((float) $item['quantity'], 3, '.', '');
                    $updateStock->execute([
                        'quantity' => $quantity, 'unit_cost' => $item['unit_cost'],
                        'id' => $item['ingredient_id'],
                    ]);
                    if ($updateStock->rowCount() !== 1) {
                        throw new RuntimeException('An ingredient could not be updated.');
                    }
                    $movement->execute([
                        'ingredient' => $item['ingredient_id'], 'quantity' => $quantity,
                        'purchase' => $purchaseId, 'user' => $user['id'],
                        'notes' => 'Stock received on purchase ' . $purchase['purchase_number'],
                    ]);
                }
                $updatePurchase = $pdo->prepare(
                    "UPDATE purchases SET status = 'received', received_at = NOW()
                     WHERE id = :id AND status = 'pending'"
                );
                $updatePurchase->execute(['id' => $purchaseId]);
                if ($updatePurchase->rowCount() !== 1) {
                    throw new RuntimeException('Purchase status changed before receiving completed.');
                }
                $pdo->commit();
                set_flash('success', 'Purchase received. Ingredient stock was increased once.');
                redirect('/purchases.php');
            }
            if ($action === 'cancel') {
                $update = $pdo->prepare(
                    "UPDATE purchases SET status = 'cancelled' WHERE id = :id AND status = 'pending'"
                );
                $update->execute(['id' => $purchaseId]);
                $pdo->commit();
                set_flash('success', 'Pending purchase cancelled. No stock was changed.');
                redirect('/purchases.php');
            }
            throw new InvalidArgumentException('Invalid purchase action.');
        } catch (InvalidArgumentException $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[Coffee Management] Purchase operation failed: ' . $exception->getMessage());
            $error = 'The purchase could not be processed. No partial stock changes were kept.';
        }
    }
}

$ingredients = [];
$suppliers = [];
$purchases = [];
$purchaseItems = [];
try {
    $pdo = db();
    $ingredients = $pdo->query('SELECT id, name, unit FROM ingredients ORDER BY name')->fetchAll();
    $suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();
    $where = '';
    $params = [];
    if (in_array($statusFilter, ['pending', 'received', 'cancelled'], true)) {
        $where = 'WHERE p.status = :status';
        $params['status'] = $statusFilter;
    }
    $statement = $pdo->prepare(
        'SELECT p.id, p.purchase_number, p.purchase_date, p.total_amount, p.status, p.received_at,
                s.name AS supplier_name, u.full_name
         FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id
         INNER JOIN users u ON u.id = p.user_id ' . $where . '
         ORDER BY p.purchase_date DESC LIMIT 200'
    );
    $statement->execute($params);
    $purchases = $statement->fetchAll();
    $purchaseIds = array_column($purchases, 'id');
    if ($purchaseIds !== []) {
        $marks = implode(',', array_fill(0, count($purchaseIds), '?'));
        $statement = $pdo->prepare(
            "SELECT pi.purchase_id, pi.quantity, pi.unit_cost, pi.line_total, i.name, i.unit
             FROM purchase_items pi INNER JOIN ingredients i ON i.id = pi.ingredient_id
             WHERE pi.purchase_id IN ($marks) ORDER BY pi.purchase_id, i.name"
        );
        $statement->execute($purchaseIds);
        foreach ($statement->fetchAll() as $row) {
            $purchaseItems[$row['purchase_id']][] = $row;
        }
    }
} catch (Throwable $exception) {
    error_log('[Coffee Management] Purchases query failed: ' . $exception->getMessage());
    $error = 'Purchases are unavailable. Confirm that the database is imported and running.';
}

$pageTitle = 'Purchases';
require __DIR__ . '/includes/header.php';
?>
<section class="welcome-row"><div><h2>Ingredient purchases</h2><p>Record supplier purchases. Only receiving a purchase updates inventory.</p></div></section>
<?php if ($error !== null): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="content-grid management-grid">
    <article class="panel">
        <div class="panel-heading"><div><span class="eyebrow">PURCHASE ORDER</span><h3>Record a purchase</h3></div></div>
        <?php if ($ingredients === []): ?><div class="empty-state"><strong>Add ingredients first</strong><p>Purchases need at least one ingredient.</p></div>
        <?php else: ?><form method="post" class="stacked-form purchase-form" data-purchase-form data-ingredients="<?= e(json_encode($ingredients, JSON_THROW_ON_ERROR)) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="items" value="[]" data-purchase-json>
            <label for="purchase-supplier">Supplier <span class="optional-label">Optional</span></label><select id="purchase-supplier" name="supplier_id"><option value="">No supplier</option><?php foreach ($suppliers as $supplier): ?><option value="<?= e($supplier['id']) ?>"><?= e($supplier['name']) ?></option><?php endforeach; ?></select>
            <div class="purchase-lines" data-purchase-lines></div>
            <button class="button button-light add-purchase-line" type="button" data-add-purchase-line>＋ Add ingredient line</button>
            <label for="purchase-notes">Notes</label><textarea id="purchase-notes" name="notes" rows="2" maxlength="4000"></textarea>
            <div class="purchase-total"><span>Purchase total</span><strong data-purchase-total>₹0.00</strong></div>
            <button class="button button-primary" type="submit" data-purchase-submit disabled>Save as pending</button>
            <p class="form-hint">Save first, then receive from the purchase history to add stock.</p>
        </form><?php endif; ?>
    </article>
    <article class="panel table-panel">
        <div class="panel-heading"><div><span class="eyebrow">PURCHASE RECORDS</span><h3>Purchase history</h3></div></div>
        <form class="filter-bar" method="get"><select name="status"><option value="">All statuses</option><?php foreach (['pending', 'received', 'cancelled'] as $value): ?><option value="<?= e($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= e(ucfirst($value)) ?></option><?php endforeach; ?></select><button class="button button-light" type="submit">Filter</button></form>
        <?php if ($purchases === []): ?><div class="empty-state"><strong>No purchases recorded</strong><p>Purchase orders will appear here.</p></div>
        <?php else: ?><div class="purchase-history">
            <?php foreach ($purchases as $purchase): ?><article class="purchase-record">
                <div class="purchase-record-heading"><span><strong><?= e($purchase['purchase_number']) ?></strong><small><?= e((new DateTimeImmutable($purchase['purchase_date']))->format('d M Y')) ?> · <?= e($purchase['supplier_name'] ?? 'No supplier') ?></small></span><span class="status-chip <?= $purchase['status'] === 'cancelled' ? 'status-cancelled' : '' ?>"><?= e(ucfirst($purchase['status'])) ?></span></div>
                <div class="purchase-record-items"><?php foreach ($purchaseItems[$purchase['id']] ?? [] as $item): ?><div><span><?= e($item['name']) ?> <small><?= e($item['quantity']) ?> <?= e($item['unit']) ?> × <?= e(format_money($item['unit_cost'])) ?></small></span><strong><?= e(format_money($item['line_total'])) ?></strong></div><?php endforeach; ?></div>
                <div class="purchase-record-footer"><strong><?= e(format_money($purchase['total_amount'])) ?></strong>
                    <?php if ($purchase['status'] === 'pending'): ?><form method="post" class="inline-actions"><?= csrf_field() ?><input type="hidden" name="purchase_id" value="<?= e($purchase['id']) ?>"><button class="button button-primary" name="action" value="receive" type="submit" onclick="return confirm('Receive this purchase and increase stock?')">Receive stock</button><button class="text-button danger-text" name="action" value="cancel" type="submit" onclick="return confirm('Cancel this pending purchase?')">Cancel purchase</button></form><?php endif; ?>
                </div>
            </article><?php endforeach; ?>
        </div><?php endif; ?>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
