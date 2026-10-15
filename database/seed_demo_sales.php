<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$salesPlan = [
    [
        ['Cappuccino' => 2, 'Croissant' => 1],
        ['Latte' => 1, 'Espresso' => 1],
    ],
    [
        ['Cold Coffee' => 2, 'Croissant' => 1],
        ['Cappuccino' => 1, 'Latte' => 1],
    ],
    [
        ['Latte' => 2, 'Croissant' => 1],
        ['Mocha' => 1, 'Cappuccino' => 1],
    ],
    [
        ['Cappuccino' => 1, 'Espresso' => 1],
        ['Latte' => 1, 'Croissant' => 1],
    ],
    [
        ['Americano' => 2, 'Croissant' => 1],
        ['Cappuccino' => 2],
    ],
    [
        ['Cold Coffee' => 2],
        ['Latte' => 2, 'Croissant' => 1],
    ],
    [
        ['Cappuccino' => 2, 'Croissant' => 1],
        ['Latte' => 1, 'Americano' => 1],
    ],
];
$paymentMethods = ['cash', 'upi', 'card', 'cash', 'upi', 'card', 'cash'];

try {
    $pdo = db();
    $adminQuery = $pdo->query(
        "SELECT id FROM users WHERE role = 'administrator' AND status = 'active'
         ORDER BY id LIMIT 1"
    );
    $adminId = $adminQuery->fetchColumn();
    if ($adminId === false) {
        throw new RuntimeException('Create an active administrator before seeding demonstration sales.');
    }

    $settingsQuery = $pdo->prepare(
        "SELECT setting_value FROM settings WHERE setting_key = 'tax_rate'"
    );
    $settingsQuery->execute();
    $taxRate = (float) ($settingsQuery->fetchColumn() ?: 0);
    if ($taxRate < 0 || $taxRate > 100) {
        throw new RuntimeException('The configured tax rate must be between 0 and 100 percent.');
    }

    $productQuery = $pdo->query(
        "SELECT p.id, p.name, p.selling_price
         FROM products p WHERE p.status = 'available'"
    );
    $products = [];
    foreach ($productQuery->fetchAll() as $product) {
        $products[$product['name']] = [
            'id' => (int) $product['id'],
            'name' => $product['name'],
            'price_paise' => (int) round((float) $product['selling_price'] * 100),
        ];
    }

    $recipeQuery = $pdo->query(
        'SELECT pr.product_id, pr.ingredient_id, pr.quantity_required, i.name AS ingredient_name
         FROM product_recipes pr
         INNER JOIN ingredients i ON i.id = pr.ingredient_id'
    );
    $recipes = [];
    foreach ($recipeQuery->fetchAll() as $recipe) {
        $recipes[(int) $recipe['product_id']][] = [
            'ingredient_id' => (int) $recipe['ingredient_id'],
            'ingredient_name' => $recipe['ingredient_name'],
            'quantity' => (float) $recipe['quantity_required'],
        ];
    }

    $today = new DateTimeImmutable('today');
    $createdCount = 0;
    $existingCount = 0;
    $ordersToCreate = [];
    foreach ($salesPlan as $dayIndex => $dayOrders) {
        $date = $today->modify('-' . (6 - $dayIndex) . ' days');
        foreach ($dayOrders as $saleIndex => $cart) {
            $saleNumber = $saleIndex + 1;
            $orderNumber = 'DEMO-' . $date->format('Ymd') . '-' . str_pad(
                (string) $saleNumber,
                2,
                '0',
                STR_PAD_LEFT
            );
            $items = [];
            $subtotalPaise = 0;
            $requiredStock = [];
            foreach ($cart as $productName => $quantity) {
                if (!isset($products[$productName])) {
                    throw new RuntimeException(
                        'The available sample product "' . $productName . '" was not found. Import database/sample_data.sql first.'
                    );
                }
                $product = $products[$productName];
                $lineTotalPaise = $product['price_paise'] * $quantity;
                $subtotalPaise += $lineTotalPaise;
                $items[] = [
                    'product_id' => $product['id'],
                    'name' => $product['name'],
                    'quantity' => $quantity,
                    'unit_price_paise' => $product['price_paise'],
                    'line_total_paise' => $lineTotalPaise,
                ];
                foreach ($recipes[$product['id']] ?? [] as $recipe) {
                    $ingredientId = $recipe['ingredient_id'];
                    if (!isset($requiredStock[$ingredientId])) {
                        $requiredStock[$ingredientId] = [
                            'name' => $recipe['ingredient_name'],
                            'quantity' => 0.0,
                        ];
                    }
                    $requiredStock[$ingredientId]['quantity'] += $recipe['quantity'] * $quantity;
                }
            }
            $taxPaise = (int) round($subtotalPaise * $taxRate / 100);
            $paymentStatus = $dayIndex === 6 && $saleIndex === 1 ? 'pending' : 'paid';
            $ordersToCreate[] = [
                'order_number' => $orderNumber,
                'idempotency_key' => hash('sha256', 'coffee-management-' . $orderNumber),
                'order_date' => $date->setTime($saleIndex === 0 ? 9 : 15, $saleIndex === 0 ? 30 : 45)->format('Y-m-d H:i:s'),
                'payment_method' => $paymentMethods[($dayIndex + $saleIndex) % count($paymentMethods)],
                'payment_status' => $paymentStatus,
                'subtotal_paise' => $subtotalPaise,
                'tax_paise' => $taxPaise,
                'total_paise' => $subtotalPaise + $taxPaise,
                'items' => $items,
                'required_stock' => $requiredStock,
            ];
        }
    }

    $existingOrder = $pdo->prepare('SELECT id FROM orders WHERE order_number = :number');
    $lockIngredient = $pdo->prepare(
        'SELECT name, current_stock FROM ingredients WHERE id = :id FOR UPDATE'
    );
    $insertOrder = $pdo->prepare(
        'INSERT INTO orders
         (order_number, idempotency_key, customer_id, user_id, subtotal, discount, tax,
          final_total, payment_method, payment_status, status, order_date)
         VALUES
         (:number, :idempotency, NULL, :user_id, :subtotal, 0, :tax,
          :total, :payment, :payment_status, \'completed\', :order_date)'
    );
    $insertItem = $pdo->prepare(
        'INSERT INTO order_items
         (order_id, product_id, product_name_snapshot, quantity, unit_price, line_total)
         VALUES (:order_id, :product_id, :name, :quantity, :unit_price, :line_total)'
    );
    $updateStock = $pdo->prepare(
        'UPDATE ingredients SET current_stock = current_stock - :quantity
         WHERE id = :id AND current_stock >= :required_quantity'
    );
    $insertMovement = $pdo->prepare(
        "INSERT INTO ingredient_stock_movements
         (ingredient_id, direction, reason, quantity, reference_type, reference_id, user_id, notes)
         VALUES (:ingredient_id, 'out', 'sale', :quantity, 'order', :order_id, :user_id, :notes)"
    );

    $pdo->beginTransaction();
    foreach ($ordersToCreate as $demoOrder) {
        $existingOrder->execute(['number' => $demoOrder['order_number']]);
        if ($existingOrder->fetchColumn() !== false) {
            $existingCount++;
            continue;
        }

        ksort($demoOrder['required_stock'], SORT_NUMERIC);
        foreach ($demoOrder['required_stock'] as $ingredientId => $requirement) {
            $lockIngredient->execute(['id' => $ingredientId]);
            $ingredient = $lockIngredient->fetch();
            if (
                !$ingredient
                || (float) $ingredient['current_stock'] + 0.000001 < $requirement['quantity']
            ) {
                throw new RuntimeException(
                    'Not enough "' . $requirement['name'] . '" stock for sample order ' .
                    $demoOrder['order_number'] . '. Restock the sample ingredient and run the script again.'
                );
            }
        }

        $insertOrder->execute([
            'number' => $demoOrder['order_number'],
            'idempotency' => $demoOrder['idempotency_key'],
            'user_id' => $adminId,
            'subtotal' => number_format($demoOrder['subtotal_paise'] / 100, 2, '.', ''),
            'tax' => number_format($demoOrder['tax_paise'] / 100, 2, '.', ''),
            'total' => number_format($demoOrder['total_paise'] / 100, 2, '.', ''),
            'payment' => $demoOrder['payment_method'],
            'payment_status' => $demoOrder['payment_status'],
            'order_date' => $demoOrder['order_date'],
        ]);
        $orderId = (int) $pdo->lastInsertId();

        foreach ($demoOrder['items'] as $item) {
            $insertItem->execute([
                'order_id' => $orderId,
                'product_id' => $item['product_id'],
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'unit_price' => number_format($item['unit_price_paise'] / 100, 2, '.', ''),
                'line_total' => number_format($item['line_total_paise'] / 100, 2, '.', ''),
            ]);
        }

        foreach ($demoOrder['required_stock'] as $ingredientId => $requirement) {
            $quantity = number_format($requirement['quantity'], 3, '.', '');
            $updateStock->execute([
                'quantity' => $quantity,
                'required_quantity' => $quantity,
                'id' => $ingredientId,
            ]);
            if ($updateStock->rowCount() !== 1) {
                throw new RuntimeException(
                    'Ingredient stock changed while recording demo order ' . $demoOrder['order_number'] . '.'
                );
            }
            $insertMovement->execute([
                'ingredient_id' => $ingredientId,
                'quantity' => $quantity,
                'order_id' => $orderId,
                'user_id' => $adminId,
                'notes' => 'DEMO sample sale; run database/seed_demo_sales.php to seed the demonstration dataset.',
            ]);
        }
        $createdCount++;
    }
    $pdo->commit();

    fwrite(
        STDOUT,
        'Demo sales complete. New orders: ' . $createdCount .
        '; already present: ' . $existingCount . ".\n"
    );
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[Coffee Management] Demo sales seed failed: ' . $exception->getMessage());
    fwrite(STDERR, 'Demo sales were not added: ' . $exception->getMessage() . "\n");
    exit(1);
}
