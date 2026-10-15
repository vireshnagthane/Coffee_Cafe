<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

$user = require_auth();
$isAdmin = $user['role'] === 'administrator';
$error = null;
$tokenKey = 'pending_order_key';
if (empty($_SESSION[$tokenKey])) {
    $_SESSION[$tokenKey] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } elseif (!hash_equals((string) $_SESSION[$tokenKey], (string) ($_POST['idempotency_key'] ?? ''))) {
        $error = 'This order form has already been used. Refresh to start a new order.';
    } else {
        $rawItems = json_decode((string) ($_POST['items'] ?? ''), true);
        try {
            if (!is_array($rawItems) || $rawItems === [] || count($rawItems) > 100) {
                throw new InvalidArgumentException('Add at least one valid product to the order.');
            }
            $quantities = [];
            foreach ($rawItems as $item) {
                if (!is_array($item)) {
                    throw new InvalidArgumentException('The cart contains an invalid line item.');
                }
                $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT);
                $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
                if (!$productId || !$quantity || $quantity > 999) {
                    throw new InvalidArgumentException('The cart contains an invalid product or quantity.');
                }
                $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;
                if ($quantities[$productId] > 999) {
                    throw new InvalidArgumentException('A product quantity cannot exceed 999.');
                }
            }

            $discountInput = trim((string) ($_POST['discount'] ?? '0'));
            if (!$isAdmin && $discountInput !== '' && (float) $discountInput !== 0.0) {
                throw new InvalidArgumentException('Only an administrator can apply a discount.');
            }
            $discount = filter_var($discountInput === '' ? '0' : $discountInput, FILTER_VALIDATE_FLOAT);
            if ($discount === false || $discount < 0 || $discount > 9999999999) {
                throw new InvalidArgumentException('Enter a valid non-negative discount.');
            }
            $paymentMethod = (string) ($_POST['payment_method'] ?? '');
            if (!in_array($paymentMethod, ['cash', 'upi', 'card'], true)) {
                throw new InvalidArgumentException('Choose a valid payment method.');
            }
            $paymentStatus = (string) ($_POST['payment_status'] ?? 'paid');
            if (!in_array($paymentStatus, ['paid', 'pending'], true)) {
                throw new InvalidArgumentException('Choose a valid payment status.');
            }
            $customerInput = trim((string) ($_POST['customer_id'] ?? ''));
            $customerId = $customerInput === '' ? null : filter_var($customerInput, FILTER_VALIDATE_INT);
            if ($customerInput !== '' && !$customerId) {
                throw new InvalidArgumentException('Choose a valid customer.');
            }

            $pdo = db();
            $pdo->beginTransaction();
            $productIds = array_keys($quantities);
            sort($productIds, SORT_NUMERIC);
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $productQuery = $pdo->prepare(
                "SELECT id, name, selling_price FROM products
                 WHERE status = 'available' AND id IN ($placeholders)
                 ORDER BY id FOR UPDATE"
            );
            $productQuery->execute($productIds);
            $products = $productQuery->fetchAll();
            if (count($products) !== count($productIds)) {
                throw new InvalidArgumentException('One or more products are no longer available.');
            }
            if ($customerId !== null) {
                $customerQuery = $pdo->prepare('SELECT id FROM customers WHERE id = :id');
                $customerQuery->execute(['id' => $customerId]);
                if (!$customerQuery->fetchColumn()) {
                    throw new InvalidArgumentException('The selected customer no longer exists.');
                }
            }

            $productById = [];
            $subtotalPaise = 0;
            foreach ($products as $product) {
                $productId = (int) $product['id'];
                $unitPricePaise = (int) round((float) $product['selling_price'] * 100);
                $lineTotalPaise = $unitPricePaise * $quantities[$productId];
                $subtotalPaise += $lineTotalPaise;
                $productById[$productId] = [
                    'name' => $product['name'],
                    'price_paise' => $unitPricePaise,
                    'quantity' => $quantities[$productId],
                    'line_total_paise' => $lineTotalPaise,
                ];
            }
            if ($subtotalPaise > 999999999999) {
                throw new InvalidArgumentException('Order subtotal exceeds the maximum supported bill value.');
            }
            $discountPaise = (int) round((float) $discount * 100);
            if ($discountPaise > $subtotalPaise) {
                throw new InvalidArgumentException('Discount cannot be greater than the subtotal.');
            }

            $rateQuery = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'tax_rate'");
            $rateQuery->execute();
            $taxRate = (float) ($rateQuery->fetchColumn() ?: 0);
            if ($taxRate < 0 || $taxRate > 100) {
                throw new RuntimeException('The configured tax rate is invalid.');
            }
            $taxablePaise = $subtotalPaise - $discountPaise;
            $taxPaise = (int) round($taxablePaise * $taxRate / 100);
            $totalPaise = $taxablePaise + $taxPaise;
            if ($taxPaise > 999999999999 || $totalPaise > 999999999999) {
                throw new InvalidArgumentException('Order total exceeds the maximum supported bill value.');
            }

            $recipeQuery = $pdo->prepare(
                "SELECT pr.product_id, pr.ingredient_id, pr.quantity_required
                 FROM product_recipes pr
                 WHERE pr.product_id IN ($placeholders)
                 ORDER BY pr.ingredient_id, pr.product_id"
            );
            $recipeQuery->execute($productIds);
            $requiredStock = [];
            foreach ($recipeQuery->fetchAll() as $recipe) {
                $ingredientId = (int) $recipe['ingredient_id'];
                $productId = (int) $recipe['product_id'];
                $requiredStock[$ingredientId] = ($requiredStock[$ingredientId] ?? 0)
                    + (float) $recipe['quantity_required'] * $quantities[$productId];
            }
            ksort($requiredStock, SORT_NUMERIC);
            foreach ($requiredStock as $ingredientId => $quantity) {
                if ($quantity <= 0 || $quantity > 999999999.999) {
                    throw new InvalidArgumentException('Recipe quantities exceed supported inventory limits.');
                }
                $stockQuery = $pdo->prepare(
                    'SELECT name, current_stock FROM ingredients WHERE id = :id FOR UPDATE'
                );
                $stockQuery->execute(['id' => $ingredientId]);
                $ingredient = $stockQuery->fetch();
                if (!$ingredient || (float) $ingredient['current_stock'] + 0.000001 < $quantity) {
                    $ingredientName = is_array($ingredient) ? $ingredient['name'] : 'An ingredient';
                    throw new InvalidArgumentException('Insufficient stock for ' . $ingredientName . '. The order was not completed.');
                }
            }

            $orderNumber = 'ORD-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $insertOrder = $pdo->prepare(
                'INSERT INTO orders
                 (order_number, idempotency_key, customer_id, user_id, subtotal, discount, tax,
                  final_total, payment_method, payment_status, status)
                 VALUES (:number, :idempotency_key, :customer_id, :user_id, :subtotal, :discount,
                         :tax, :total, :payment_method, :payment_status, \'completed\')'
            );
            $insertOrder->execute([
                'number' => $orderNumber,
                'idempotency_key' => $_SESSION[$tokenKey],
                'customer_id' => $customerId,
                'user_id' => $user['id'],
                'subtotal' => number_format($subtotalPaise / 100, 2, '.', ''),
                'discount' => number_format($discountPaise / 100, 2, '.', ''),
                'tax' => number_format($taxPaise / 100, 2, '.', ''),
                'total' => number_format($totalPaise / 100, 2, '.', ''),
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
            ]);
            $orderId = (int) $pdo->lastInsertId();
            $insertItem = $pdo->prepare(
                'INSERT INTO order_items
                 (order_id, product_id, product_name_snapshot, quantity, unit_price, line_total)
                 VALUES (:order_id, :product_id, :name, :quantity, :unit_price, :line_total)'
            );
            foreach ($productById as $productId => $product) {
                $insertItem->execute([
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'name' => $product['name'],
                    'quantity' => $product['quantity'],
                    'unit_price' => number_format($product['price_paise'] / 100, 2, '.', ''),
                    'line_total' => number_format($product['line_total_paise'] / 100, 2, '.', ''),
                ]);
            }
            $updateStock = $pdo->prepare(
                'UPDATE ingredients SET current_stock = current_stock - :deduct_quantity
                 WHERE id = :id AND current_stock >= :required_quantity'
            );
            $insertMovement = $pdo->prepare(
                "INSERT INTO ingredient_stock_movements
                 (ingredient_id, direction, reason, quantity, reference_type, reference_id, user_id, notes)
                 VALUES (:ingredient_id, 'out', 'sale', :quantity, 'order', :reference_id, :user_id, :notes)"
            );
            foreach ($requiredStock as $ingredientId => $quantity) {
                $stockQuantity = number_format($quantity, 3, '.', '');
                $updateStock->execute([
                    'deduct_quantity' => $stockQuantity,
                    'required_quantity' => $stockQuantity,
                    'id' => $ingredientId,
                ]);
                if ($updateStock->rowCount() !== 1) {
                    throw new InvalidArgumentException('Ingredient stock changed while this order was being placed. Please retry.');
                }
                $insertMovement->execute([
                    'ingredient_id' => $ingredientId,
                    'quantity' => $stockQuantity,
                    'reference_id' => $orderId,
                    'user_id' => $user['id'],
                    'notes' => 'Ingredient use for order ' . $orderNumber,
                ]);
            }

            $pdo->commit();
            $_SESSION[$tokenKey] = bin2hex(random_bytes(32));
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
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                try {
                    $duplicateQuery = db()->prepare(
                        'SELECT id FROM orders WHERE idempotency_key = :key'
                    );
                    $duplicateQuery->execute(['key' => $_SESSION[$tokenKey]]);
                    $existingOrder = $duplicateQuery->fetchColumn();
                    if ($existingOrder) {
                        redirect('/order_details.php?id=' . (int) $existingOrder);
                    }
                } catch (Throwable $lookupException) {
                    error_log('[Coffee Management] Duplicate order lookup failed: ' . $lookupException->getMessage());
                }
            }
            error_log('[Coffee Management] Order creation failed: ' . $exception->getMessage());
            $error = 'The order could not be completed. No stock was changed; check the application error log.';
        }
    }
}

$products = [];
$categories = [];
$customers = [];
$taxRate = 0.0;
$currency = 'INR';
try {
    $pdo = db();
    $products = $pdo->query(
        "SELECT p.id, p.name, p.selling_price, p.category_id, c.name AS category_name
         FROM products p INNER JOIN categories c ON c.id = p.category_id
         WHERE p.status = 'available' ORDER BY p.name"
    )->fetchAll();
    $categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
    $customers = $pdo->query('SELECT id, name, phone FROM customers ORDER BY name')->fetchAll();
    $settings = $pdo->query(
        "SELECT setting_key, setting_value FROM settings
         WHERE setting_key IN ('tax_rate', 'currency')"
    )->fetchAll();
    foreach ($settings as $setting) {
        if ($setting['setting_key'] === 'tax_rate') {
            $taxRate = (float) $setting['setting_value'];
        } elseif ($setting['setting_key'] === 'currency') {
            $currency = $setting['setting_value'];
        }
    }
} catch (Throwable $exception) {
    error_log('[Coffee Management] POS data query failed: ' . $exception->getMessage());
    $error = 'The point of sale is unavailable. Confirm that the database is imported and running.';
}

$pageTitle = 'Point of sale';
require __DIR__ . '/includes/header.php';
?>
<section class="welcome-row">
    <div><h2>Point of sale</h2><p>Build a cart and complete payment in a single step.</p></div>
    <a class="button button-light" href="<?= e(APP_BASE_URL) ?>/orders.php">View orders <span>↗</span></a>
</section>
<?php if ($error !== null): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="pos-layout" data-pos data-tax-rate="<?= e($taxRate) ?>" data-currency="<?= e($currency) ?>" data-can-discount="<?= $isAdmin ? 'yes' : 'no' ?>">
    <section class="panel menu-panel">
        <div class="panel-heading"><div><span class="eyebrow">MENU</span><h3>Choose products</h3></div><span class="subtle-tag"><?= count($products) ?> available</span></div>
        <div class="pos-filters">
            <input type="search" placeholder="Search menu…" aria-label="Search products" data-product-search>
            <select aria-label="Filter by category" data-product-category><option value="">All categories</option><?php foreach ($categories as $category): ?><option value="<?= e($category['id']) ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select>
        </div>
        <?php if ($products === []): ?><div class="empty-state"><strong>No available products</strong><p>Add an available product before creating an order.</p></div>
        <?php else: ?><div class="pos-product-grid" data-product-list>
            <?php foreach ($products as $product): ?>
                <article class="pos-product-card" data-product-card data-name="<?= e(mb_strtolower($product['name'])) ?>" data-category="<?= e($product['category_id']) ?>">
                    <span class="product-category"><?= e($product['category_name']) ?></span><h4><?= e($product['name']) ?></h4>
                    <div class="product-card-bottom"><strong><?= e(format_money($product['selling_price'], $currency)) ?></strong><button type="button" data-add-product data-id="<?= e($product['id']) ?>" data-name="<?= e($product['name']) ?>" data-price="<?= e($product['selling_price']) ?>" aria-label="Add <?= e($product['name']) ?>">＋</button></div>
                </article>
            <?php endforeach; ?>
        </div><?php endif; ?>
    </section>
    <aside class="panel checkout-panel">
        <div class="panel-heading"><div><span class="eyebrow">CURRENT SALE</span><h3>Your order</h3></div><span class="cart-bag">▣</span></div>
        <form method="post" data-pos-form>
            <?= csrf_field() ?>
            <input type="hidden" name="idempotency_key" value="<?= e($_SESSION[$tokenKey]) ?>">
            <input type="hidden" name="items" value="[]" data-cart-json>
            <div class="cart-items" data-cart-items></div>
            <div class="checkout-fields">
                <label for="customer">Customer <span class="optional-label">Optional</span></label>
                <select id="customer" name="customer_id"><option value="">Walk-in customer</option><?php foreach ($customers as $customer): ?><option value="<?= e($customer['id']) ?>"><?= e($customer['name']) ?><?= $customer['phone'] ? ' · ' . e($customer['phone']) : '' ?></option><?php endforeach; ?></select>
                <label for="payment">Payment method</label>
                <select id="payment" name="payment_method" required><option value="cash">Cash</option><option value="upi">UPI (record only)</option><option value="card">Card (record only)</option></select>
                <label for="payment-status">Payment status</label>
                <select id="payment-status" name="payment_status" required><option value="paid">Paid</option><option value="pending">Pending</option></select>
                <?php if ($isAdmin): ?><label for="discount">Discount (<?= e($currency) ?>)</label><input id="discount" name="discount" type="number" min="0" step="0.01" value="0.00" data-discount><?php else: ?><input type="hidden" name="discount" value="0"><?php endif; ?>
            </div>
            <div class="totals">
                <div><span>Subtotal</span><strong data-subtotal><?= e(format_money(0, $currency)) ?></strong></div>
                <div><span>Tax (<?= e(number_format($taxRate, 2)) ?>%, exclusive)</span><strong data-tax-total><?= e(format_money(0, $currency)) ?></strong></div>
                <div class="total-line"><strong>Total due</strong><strong data-final-total><?= e(format_money(0, $currency)) ?></strong></div>
            </div>
            <button class="button button-primary button-wide complete-order" type="submit" data-complete-order disabled>Complete order <span>→</span></button>
            <p class="payment-note">Card and UPI are recorded manually. No payment gateway is connected.</p>
        </form>
    </aside>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
