-- OPTIONAL DEMONSTRATION DATA ONLY.
-- Safe to import repeatedly. Demo team entries are inactive and cannot sign in.
USE coffee_management;

-- This is a random, unusable password hash. Demo users are deliberately
-- inactive; create real cashier accounts from Team accounts instead.
INSERT IGNORE INTO users (full_name, username, password_hash, role, status) VALUES
    ('Demo Barista One', 'sample.barista1', '$2y$10$czvqqXeDFvj0hoLiqVFsXuiBUAjgk/rPkYvdGmt0SHBEU0S0J0S5q', 'cashier', 'inactive'),
    ('Demo Barista Two', 'sample.barista2', '$2y$10$czvqqXeDFvj0hoLiqVFsXuiBUAjgk/rPkYvdGmt0SHBEU0S0J0S5q', 'cashier', 'inactive'),
    ('Demo Cashier One', 'sample.cashier1', '$2y$10$czvqqXeDFvj0hoLiqVFsXuiBUAjgk/rPkYvdGmt0SHBEU0S0J0S5q', 'cashier', 'inactive'),
    ('Demo Shift Lead', 'sample.shiftlead', '$2y$10$czvqqXeDFvj0hoLiqVFsXuiBUAjgk/rPkYvdGmt0SHBEU0S0J0S5q', 'cashier', 'inactive');

INSERT INTO categories (name, description) VALUES
    ('Hot Coffee', 'Sample hot coffee menu'),
    ('Cold Coffee', 'Sample iced coffee menu'),
    ('Tea', 'Sample tea menu'),
    ('Snacks', 'Sample snack menu')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO suppliers (name, contact_person, phone, email, address) VALUES
    ('Sample Coffee Supplies', 'Sample Contact', '', '', 'Demonstration supplier')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO ingredients
    (supplier_id, name, unit, current_stock, minimum_stock, cost_per_unit)
SELECT s.id, i.name, i.unit, i.opening_stock, i.minimum_stock, i.cost_per_unit
FROM suppliers s
CROSS JOIN (
    SELECT 'Sample Coffee Beans' AS name, 'g' AS unit, 1000.000 AS opening_stock, 200.000 AS minimum_stock, 0.250 AS cost_per_unit
    UNION ALL SELECT 'Sample Milk', 'ml', 5000.000, 1000.000, 0.080
    UNION ALL SELECT 'Sample Sugar', 'g', 2000.000, 300.000, 0.060
    UNION ALL SELECT 'Sample Chocolate Syrup', 'ml', 1000.000, 200.000, 0.200
    UNION ALL SELECT 'Sample Paper Cups', 'pcs', 500.000, 100.000, 1.000
) AS i
WHERE s.name = 'Sample Coffee Supplies'
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO ingredient_stock_movements
    (ingredient_id, direction, reason, quantity, reference_type, reference_id, notes)
SELECT id, 'in', 'manual_adjustment', current_stock, 'sample_opening', id,
       'Optional demonstration opening stock; not real business data.'
FROM ingredients
WHERE name IN (
    'Sample Coffee Beans', 'Sample Milk', 'Sample Sugar',
    'Sample Chocolate Syrup', 'Sample Paper Cups'
)
  AND current_stock > 0
ON DUPLICATE KEY UPDATE notes = VALUES(notes);

INSERT INTO products (category_id, name, description, selling_price, status)
SELECT c.id, p.name, 'Optional demonstration menu item', p.price, 'available'
FROM (
    SELECT 'Hot Coffee' AS category_name, 'Espresso' AS name, 120.00 AS price
    UNION ALL SELECT 'Hot Coffee', 'Cappuccino', 160.00
    UNION ALL SELECT 'Hot Coffee', 'Latte', 170.00
    UNION ALL SELECT 'Hot Coffee', 'Americano', 130.00
    UNION ALL SELECT 'Cold Coffee', 'Cold Coffee', 180.00
    UNION ALL SELECT 'Hot Coffee', 'Mocha', 190.00
    UNION ALL SELECT 'Tea', 'Masala Tea', 90.00
    UNION ALL SELECT 'Snacks', 'Croissant', 110.00
) AS p
INNER JOIN categories c ON c.name = p.category_name
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    description = VALUES(description),
    selling_price = VALUES(selling_price),
    status = VALUES(status);

INSERT INTO product_recipes (product_id, ingredient_id, quantity_required)
SELECT p.id, i.id, r.quantity_required
FROM (
    SELECT 'Espresso' AS product_name, 'Sample Coffee Beans' AS ingredient_name, 18.000 AS quantity_required
    UNION ALL SELECT 'Cappuccino', 'Sample Coffee Beans', 18.000
    UNION ALL SELECT 'Cappuccino', 'Sample Milk', 150.000
    UNION ALL SELECT 'Cappuccino', 'Sample Paper Cups', 1.000
    UNION ALL SELECT 'Latte', 'Sample Coffee Beans', 18.000
    UNION ALL SELECT 'Latte', 'Sample Milk', 200.000
    UNION ALL SELECT 'Latte', 'Sample Paper Cups', 1.000
    UNION ALL SELECT 'Americano', 'Sample Coffee Beans', 18.000
    UNION ALL SELECT 'Americano', 'Sample Paper Cups', 1.000
    UNION ALL SELECT 'Cold Coffee', 'Sample Coffee Beans', 18.000
    UNION ALL SELECT 'Cold Coffee', 'Sample Milk', 150.000
    UNION ALL SELECT 'Cold Coffee', 'Sample Sugar', 10.000
    UNION ALL SELECT 'Cold Coffee', 'Sample Paper Cups', 1.000
    UNION ALL SELECT 'Mocha', 'Sample Coffee Beans', 18.000
    UNION ALL SELECT 'Mocha', 'Sample Milk', 150.000
    UNION ALL SELECT 'Mocha', 'Sample Chocolate Syrup', 20.000
    UNION ALL SELECT 'Mocha', 'Sample Paper Cups', 1.000
    UNION ALL SELECT 'Masala Tea', 'Sample Milk', 120.000
    UNION ALL SELECT 'Masala Tea', 'Sample Sugar', 10.000
    UNION ALL SELECT 'Masala Tea', 'Sample Paper Cups', 1.000
) AS r
INNER JOIN products p ON p.name = r.product_name
INNER JOIN ingredients i ON i.name = r.ingredient_name
ON DUPLICATE KEY UPDATE quantity_required = VALUES(quantity_required);
