<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

$user = require_auth();
$isAdmin = $user['role'] === 'administrator';
$error = null;
$search = trim((string) ($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Only administrators can change inventory.');
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        $pdo = null;
        try {
            $pdo = db();
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'save_ingredient') {
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
                $name = trim((string) ($_POST['name'] ?? ''));
                $unit = trim((string) ($_POST['unit'] ?? ''));
                $minimum = filter_var($_POST['minimum_stock'] ?? null, FILTER_VALIDATE_FLOAT);
                $cost = filter_var($_POST['cost_per_unit'] ?? null, FILTER_VALIDATE_FLOAT);
                $supplier = filter_var($_POST['supplier_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
                if ($name === '' || mb_strlen($name) > 150 || $unit === '' || mb_strlen($unit) > 32 ||
                    $minimum === false || $minimum < 0 || $minimum > 999999999.999 ||
                    $cost === false || $cost < 0 || $cost > 9999999.999) {
                    throw new InvalidArgumentException('Enter a name, unit, non-negative minimum stock and unit cost.');
                }
                if ($id) {
                    $statement = $pdo->prepare(
                        'UPDATE ingredients SET name = :name, unit = :unit, minimum_stock = :minimum,
                         cost_per_unit = :cost, supplier_id = :supplier WHERE id = :id'
                    );
                    $statement->execute([
                        'name' => $name, 'unit' => $unit,
                        'minimum' => number_format((float) $minimum, 3, '.', ''),
                        'cost' => number_format((float) $cost, 3, '.', ''),
                        'supplier' => $supplier, 'id' => $id,
                    ]);
                } else {
                    $opening = filter_var($_POST['opening_stock'] ?? '0', FILTER_VALIDATE_FLOAT);
                    if ($opening === false || $opening < 0 || $opening > 999999999.999) {
                        throw new InvalidArgumentException('Opening stock must be zero or a positive quantity.');
                    }
                    $pdo->beginTransaction();
                    $statement = $pdo->prepare(
                        'INSERT INTO ingredients
                         (name, unit, current_stock, minimum_stock, cost_per_unit, supplier_id)
                         VALUES (:name, :unit, :stock, :minimum, :cost, :supplier)'
                    );
                    $statement->execute([
                        'name' => $name, 'unit' => $unit,
                        'stock' => number_format((float) $opening, 3, '.', ''),
                        'minimum' => number_format((float) $minimum, 3, '.', ''),
                        'cost' => number_format((float) $cost, 3, '.', ''),
                        'supplier' => $supplier,
                    ]);
                    $ingredientId = (int) $pdo->lastInsertId();
                    if ((float) $opening > 0) {
                        $movement = $pdo->prepare(
                            "INSERT INTO ingredient_stock_movements
                             (ingredient_id, direction, reason, quantity, reference_type, user_id, notes)
                             VALUES (:id, 'in', 'manual_adjustment', :quantity, 'opening', :user, 'Opening stock')"
                        );
                        $movement->execute([
                            'id' => $ingredientId,
                            'quantity' => number_format((float) $opening, 3, '.', ''),
                            'user' => $user['id'],
                        ]);
                    }
                    $pdo->commit();
                }
                set_flash('success', 'Ingredient saved.');
                redirect('/inventory.php');
            }

            if ($action === 'adjust_stock') {
                $ingredientId = filter_var($_POST['ingredient_id'] ?? null, FILTER_VALIDATE_INT);
                $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
                $direction = (string) ($_POST['direction'] ?? '');
                $reason = (string) ($_POST['reason'] ?? '');
                $notes = trim((string) ($_POST['notes'] ?? ''));
                if (!$ingredientId || $quantity === false || $quantity <= 0 || $quantity > 999999999.999 ||
                    !in_array($direction, ['in', 'out'], true) ||
                    !in_array($reason, ['manual_adjustment', 'wastage'], true) || mb_strlen($notes) > 500) {
                    throw new InvalidArgumentException('Enter a valid ingredient, quantity, movement type and note.');
                }
                $pdo->beginTransaction();
                $lock = $pdo->prepare('SELECT name, current_stock FROM ingredients WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $ingredientId]);
                $ingredient = $lock->fetch();
                if (!$ingredient) {
                    throw new InvalidArgumentException('That ingredient no longer exists.');
                }
                if ($direction === 'out' && (float) $ingredient['current_stock'] < (float) $quantity) {
                    throw new InvalidArgumentException('The adjustment cannot reduce stock below zero.');
                }
                $operator = $direction === 'in' ? '+' : '-';
                $update = $pdo->prepare("UPDATE ingredients SET current_stock = current_stock $operator :quantity WHERE id = :id");
                $update->execute(['quantity' => number_format((float) $quantity, 3, '.', ''), 'id' => $ingredientId]);
                $movement = $pdo->prepare(
                    'INSERT INTO ingredient_stock_movements
                     (ingredient_id, direction, reason, quantity, reference_type, user_id, notes)
                     VALUES (:ingredient, :direction, :reason, :quantity, :reference_type, :user, :notes)'
                );
                $movement->execute([
                    'ingredient' => $ingredientId, 'direction' => $direction, 'reason' => $reason,
                    'quantity' => number_format((float) $quantity, 3, '.', ''),
                    'reference_type' => $reason === 'wastage' ? 'wastage' : 'manual',
                    'user' => $user['id'], 'notes' => $notes ?: null,
                ]);
                $pdo->commit();
                set_flash('success', 'Stock updated and movement recorded.');
                redirect('/inventory.php');
            }

            if ($action === 'save_recipe') {
                $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
                $ingredientId = filter_var($_POST['ingredient_id'] ?? null, FILTER_VALIDATE_INT);
                $quantity = filter_var($_POST['quantity_required'] ?? null, FILTER_VALIDATE_FLOAT);
                if (!$productId || !$ingredientId || $quantity === false || $quantity <= 0 || $quantity > 999999999.999) {
                    throw new InvalidArgumentException('Choose a product and ingredient, then enter a recipe quantity greater than zero.');
                }
                $statement = $pdo->prepare(
                    'INSERT INTO product_recipes (product_id, ingredient_id, quantity_required)
                     VALUES (:product, :ingredient, :quantity)
                     ON DUPLICATE KEY UPDATE quantity_required = VALUES(quantity_required)'
                );
                $statement->execute([
                    'product' => $productId, 'ingredient' => $ingredientId,
                    'quantity' => number_format((float) $quantity, 3, '.', ''),
                ]);
                set_flash('success', 'Recipe quantity saved for one product unit.');
                redirect('/inventory.php');
            }

            if ($action === 'delete_recipe') {
                $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
                $ingredientId = filter_var($_POST['ingredient_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$productId || !$ingredientId) {
                    throw new InvalidArgumentException('Choose a valid recipe item.');
                }
                $statement = $pdo->prepare(
                    'DELETE FROM product_recipes WHERE product_id = :product AND ingredient_id = :ingredient'
                );
                $statement->execute(['product' => $productId, 'ingredient' => $ingredientId]);
                set_flash('success', 'Recipe ingredient removed.');
                redirect('/inventory.php');
            }
            throw new InvalidArgumentException('Invalid inventory action.');
        } catch (InvalidArgumentException $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                $error = 'That ingredient name already exists or a referenced record is invalid.';
            } else {
                error_log('[Coffee Management] Inventory update failed: ' . $exception->getMessage());
                $error = 'Inventory could not be updated. Check the application error log.';
            }
        }
    }
}

$ingredients = [];
$suppliers = [];
$products = [];
$recipes = [];
$movements = [];
$editing = null;
try {
    $pdo = db();
    $suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();
    $products = $pdo->query('SELECT id, name FROM products ORDER BY name')->fetchAll();
    $sql = 'SELECT i.*, s.name AS supplier_name FROM ingredients i LEFT JOIN suppliers s ON s.id = i.supplier_id';
    $params = [];
    if ($search !== '') {
        $sql .= ' WHERE i.name LIKE :search';
        $params['search'] = '%' . $search . '%';
    }
    $sql .= ' ORDER BY i.name';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $ingredients = $statement->fetchAll();
    if ($isAdmin && isset($_GET['edit'])) {
        $statement = $pdo->prepare('SELECT * FROM ingredients WHERE id = :id');
        $statement->execute(['id' => filter_var($_GET['edit'], FILTER_VALIDATE_INT)]);
        $editing = $statement->fetch() ?: null;
    }
    $recipes = $pdo->query(
        'SELECT pr.product_id, pr.ingredient_id, pr.quantity_required,
                p.name AS product_name, i.name AS ingredient_name, i.unit
         FROM product_recipes pr
         INNER JOIN products p ON p.id = pr.product_id
         INNER JOIN ingredients i ON i.id = pr.ingredient_id
         ORDER BY p.name, i.name'
    )->fetchAll();
    $movements = $pdo->query(
        'SELECT m.created_at, m.direction, m.reason, m.quantity, m.notes,
                i.name AS ingredient_name, i.unit, u.full_name
         FROM ingredient_stock_movements m
         INNER JOIN ingredients i ON i.id = m.ingredient_id
         LEFT JOIN users u ON u.id = m.user_id
         ORDER BY m.created_at DESC, m.id DESC LIMIT 30'
    )->fetchAll();
} catch (Throwable $exception) {
    error_log('[Coffee Management] Inventory query failed: ' . $exception->getMessage());
    $error = 'Inventory is unavailable. Confirm that the database is imported and running.';
}

$pageTitle = 'Inventory';
require __DIR__ . '/includes/header.php';
?>
<section class="welcome-row"><div><h2>Ingredient inventory</h2><p>Track stock, low-stock thresholds and drink recipes.</p></div></section>
<?php if ($error !== null): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($isAdmin): ?>
<section class="content-grid management-grid">
    <article class="panel">
        <div class="panel-heading"><div><span class="eyebrow">STOCK CATALOGUE</span><h3><?= $editing ? 'Edit ingredient' : 'Add ingredient' ?></h3></div></div>
        <form method="post" class="stacked-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_ingredient"><input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
            <label for="ingredient-name">Ingredient name</label><input id="ingredient-name" name="name" maxlength="150" required value="<?= e($editing['name'] ?? '') ?>">
            <label for="ingredient-unit">Unit of measurement</label><input id="ingredient-unit" name="unit" maxlength="32" required placeholder="g, ml, pcs" value="<?= e($editing['unit'] ?? '') ?>">
            <?php if (!$editing): ?><label for="opening-stock">Opening stock</label><input id="opening-stock" name="opening_stock" type="number" min="0" step="0.001" value="0"><?php endif; ?>
            <label for="minimum-stock">Low-stock threshold</label><input id="minimum-stock" name="minimum_stock" type="number" min="0" step="0.001" required value="<?= e($editing['minimum_stock'] ?? '0') ?>">
            <label for="ingredient-cost">Cost per unit (INR)</label><input id="ingredient-cost" name="cost_per_unit" type="number" min="0" step="0.001" required value="<?= e($editing['cost_per_unit'] ?? '0') ?>">
            <label for="ingredient-supplier">Supplier <span class="optional-label">Optional</span></label><select id="ingredient-supplier" name="supplier_id"><option value="">No supplier</option><?php foreach ($suppliers as $supplier): ?><option value="<?= e($supplier['id']) ?>" <?= (string) ($editing['supplier_id'] ?? '') === (string) $supplier['id'] ? 'selected' : '' ?>><?= e($supplier['name']) ?></option><?php endforeach; ?></select>
            <button class="button button-primary" type="submit"><?= $editing ? 'Save ingredient' : 'Add ingredient' ?></button>
            <?php if ($editing): ?><a class="cancel-link" href="<?= e(APP_BASE_URL) ?>/inventory.php">Cancel editing</a><?php endif; ?>
        </form>
    </article>
    <article class="panel">
        <div class="panel-heading"><div><span class="eyebrow">ADJUSTMENT</span><h3>Record stock movement</h3></div></div>
        <form method="post" class="stacked-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="adjust_stock">
            <label for="adjust-ingredient">Ingredient</label><select id="adjust-ingredient" name="ingredient_id" required><option value="">Choose ingredient</option><?php foreach ($ingredients as $ingredient): ?><option value="<?= e($ingredient['id']) ?>"><?= e($ingredient['name']) ?> (<?= e($ingredient['current_stock']) ?> <?= e($ingredient['unit']) ?>)</option><?php endforeach; ?></select>
            <label for="adjust-direction">Movement</label><select id="adjust-direction" name="direction"><option value="in">Add stock</option><option value="out">Deduct stock</option></select>
            <label for="adjust-quantity">Quantity</label><input id="adjust-quantity" name="quantity" type="number" min="0.001" step="0.001" required>
            <label for="adjust-reason">Reason</label><select id="adjust-reason" name="reason"><option value="manual_adjustment">Manual adjustment</option><option value="wastage">Wastage</option></select>
            <label for="adjust-notes">Note <span class="optional-label">Recommended</span></label><input id="adjust-notes" name="notes" maxlength="500" placeholder="Explain why stock changed">
            <button class="button button-primary" type="submit">Save stock movement</button>
        </form>
    </article>
</section>
<section class="content-grid management-grid recipe-grid">
    <article class="panel">
        <div class="panel-heading"><div><span class="eyebrow">RECIPE BUILDER</span><h3>Configure a recipe</h3></div></div>
        <form method="post" class="stacked-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_recipe">
            <label for="recipe-product">Product</label><select id="recipe-product" name="product_id" required><option value="">Choose product</option><?php foreach ($products as $product): ?><option value="<?= e($product['id']) ?>"><?= e($product['name']) ?></option><?php endforeach; ?></select>
            <label for="recipe-ingredient">Ingredient</label><select id="recipe-ingredient" name="ingredient_id" required><option value="">Choose ingredient</option><?php foreach ($ingredients as $ingredient): ?><option value="<?= e($ingredient['id']) ?>"><?= e($ingredient['name']) ?> (<?= e($ingredient['unit']) ?>)</option><?php endforeach; ?></select>
            <label for="recipe-quantity">Quantity for one product</label><input id="recipe-quantity" name="quantity_required" type="number" min="0.001" step="0.001" required>
            <button class="button button-primary" type="submit">Save recipe line</button>
            <p class="form-hint">Saving an existing product/ingredient pair updates its recipe quantity.</p>
        </form>
    </article>
    <article class="panel table-panel">
        <div class="panel-heading"><div><span class="eyebrow">PRODUCT RECIPES</span><h3>Configured ingredients</h3></div></div>
        <?php if ($recipes === []): ?><div class="empty-state"><strong>No recipes configured</strong><p>Products without recipe lines do not consume ingredient stock at checkout.</p></div>
        <?php else: ?><div class="table-scroll"><table><thead><tr><th>Product</th><th>Ingredient</th><th>Per item</th><th></th></tr></thead><tbody>
            <?php foreach ($recipes as $recipe): ?><tr><td><strong><?= e($recipe['product_name']) ?></strong></td><td><?= e($recipe['ingredient_name']) ?></td><td><?= e($recipe['quantity_required']) ?> <?= e($recipe['unit']) ?></td>
                <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete_recipe"><input type="hidden" name="product_id" value="<?= e($recipe['product_id']) ?>"><input type="hidden" name="ingredient_id" value="<?= e($recipe['ingredient_id']) ?>"><button class="text-button danger-text" type="submit">Remove</button></form></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </article>
</section>
<?php endif; ?>
<section class="panel table-panel inventory-list">
    <div class="panel-heading"><div><span class="eyebrow">ON-HAND</span><h3>Ingredients <span class="count-pill"><?= count($ingredients) ?></span></h3></div><span class="subtle-tag"><?= count(array_filter($ingredients, static fn(array $ingredient): bool => (float) $ingredient['current_stock'] <= (float) $ingredient['minimum_stock'])) ?> low stock</span></div>
    <form class="search-form" method="get"><input name="q" type="search" placeholder="Search ingredients" value="<?= e($search) ?>"><button class="button button-light" type="submit">Search</button></form>
    <?php if ($ingredients === []): ?><div class="empty-state"><strong>No ingredients recorded</strong><p>Add inventory items to begin tracking stock.</p></div>
    <?php else: ?><div class="table-scroll"><table><thead><tr><th>Ingredient</th><th>On hand</th><th>Minimum</th><th>Cost / unit</th><th>Supplier</th><?php if ($isAdmin): ?><th></th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($ingredients as $ingredient): $low = (float) $ingredient['current_stock'] <= (float) $ingredient['minimum_stock']; ?><tr>
            <td><strong><?= e($ingredient['name']) ?></strong></td><td><span class="stock-value <?= $low ? 'stock-low' : '' ?>"><?= e($ingredient['current_stock']) ?> <?= e($ingredient['unit']) ?></span></td>
            <td><?= e($ingredient['minimum_stock']) ?> <?= e($ingredient['unit']) ?></td><td><?= e(format_money($ingredient['cost_per_unit'])) ?></td><td><?= e($ingredient['supplier_name'] ?? '—') ?></td>
            <?php if ($isAdmin): ?><td><a href="<?= e(APP_BASE_URL) ?>/inventory.php?edit=<?= e($ingredient['id']) ?>">Edit</a></td><?php endif; ?>
        </tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
<section class="panel table-panel inventory-list">
    <div class="panel-heading"><div><span class="eyebrow">AUDIT TRAIL</span><h3>Recent stock movements</h3></div></div>
    <?php if ($movements === []): ?><div class="empty-state compact"><strong>No stock movements yet</strong></div>
    <?php else: ?><div class="table-scroll"><table><thead><tr><th>Date</th><th>Ingredient</th><th>Type</th><th>Quantity</th><th>Reason / note</th><th>User</th></tr></thead><tbody>
        <?php foreach ($movements as $movement): ?><tr><td><?= e((new DateTimeImmutable($movement['created_at']))->format('d M, H:i')) ?></td><td><?= e($movement['ingredient_name']) ?></td><td><?= e(strtoupper($movement['direction'])) ?></td><td><?= e($movement['quantity']) ?> <?= e($movement['unit']) ?></td><td><?= e(ucwords(str_replace('_', ' ', $movement['reason']))) ?><?= $movement['notes'] ? ' · ' . e($movement['notes']) : '' ?></td><td><?= e($movement['full_name'] ?? 'System') ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
