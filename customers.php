<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

$user = require_auth();
$isAdmin = $user['role'] === 'administrator';
$error = null;
$search = trim((string) ($_GET['q'] ?? ''));
$historyId = filter_var($_GET['history'] ?? null, FILTER_VALIDATE_INT) ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Only administrators can manage customers.');
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        try {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $name = trim((string) ($_POST['name'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $address = trim((string) ($_POST['address'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150 || mb_strlen($phone) > 40 ||
                mb_strlen($email) > 254 || mb_strlen($address) > 4000 ||
                ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
                throw new InvalidArgumentException('Enter a customer name and valid optional contact details.');
            }
            $params = [
                'name' => $name, 'phone' => $phone ?: null, 'email' => $email ?: null,
                'address' => $address ?: null,
            ];
            if ($id) {
                $params['id'] = $id;
                $sql = 'UPDATE customers SET name = :name, phone = :phone, email = :email, address = :address WHERE id = :id';
            } else {
                $sql = 'INSERT INTO customers (name, phone, email, address) VALUES (:name, :phone, :email, :address)';
            }
            $statement = db()->prepare($sql);
            $statement->execute($params);
            set_flash('success', 'Customer saved.');
            redirect('/customers.php');
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('[Coffee Management] Customer save failed: ' . $exception->getMessage());
            $error = 'The customer could not be saved. Check the application error log.';
        }
    }
}

$customers = [];
$historyCustomer = null;
$historyOrders = [];
$editing = null;
try {
    $pdo = db();
    $sql = 'SELECT c.id, c.name, c.phone, c.email, c.created_at, COUNT(o.id) AS order_count
            FROM customers c LEFT JOIN orders o ON o.customer_id = c.id';
    $params = [];
    if ($search !== '') {
        $sql .= ' WHERE c.name LIKE :name_search OR c.phone LIKE :phone_search OR c.email LIKE :email_search';
        $params['name_search'] = '%' . $search . '%';
        $params['phone_search'] = '%' . $search . '%';
        $params['email_search'] = '%' . $search . '%';
    }
    $sql .= ' GROUP BY c.id, c.name, c.phone, c.email, c.created_at ORDER BY c.name';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $customers = $statement->fetchAll();
    if ($isAdmin && isset($_GET['edit'])) {
        $statement = $pdo->prepare('SELECT * FROM customers WHERE id = :id');
        $statement->execute(['id' => filter_var($_GET['edit'], FILTER_VALIDATE_INT)]);
        $editing = $statement->fetch() ?: null;
    }
    if ($historyId) {
        $statement = $pdo->prepare('SELECT id, name FROM customers WHERE id = :id');
        $statement->execute(['id' => $historyId]);
        $historyCustomer = $statement->fetch() ?: null;
        if ($historyCustomer) {
            $statement = $pdo->prepare(
                'SELECT id, order_number, order_date, final_total, status
                 FROM orders WHERE customer_id = :id ORDER BY order_date DESC LIMIT 100'
            );
            $statement->execute(['id' => $historyId]);
            $historyOrders = $statement->fetchAll();
        }
    }
} catch (Throwable $exception) {
    error_log('[Coffee Management] Customers query failed: ' . $exception->getMessage());
    $error = 'Customer records are unavailable. Confirm that the database is imported and running.';
}

$pageTitle = 'Customers';
require __DIR__ . '/includes/header.php';
?>
<section class="welcome-row"><div><h2>Customer directory</h2><p>Keep customer contact details and order history together.</p></div></section>
<?php if ($error !== null): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="content-grid management-grid <?= $isAdmin ? '' : 'management-single' ?>">
    <?php if ($isAdmin): ?><article class="panel">
        <div class="panel-heading"><div><span class="eyebrow">CUSTOMER PROFILE</span><h3><?= $editing ? 'Edit customer' : 'Add customer' ?></h3></div></div>
        <form method="post" class="stacked-form">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
            <label for="customer-name">Customer name</label><input id="customer-name" name="name" maxlength="150" required value="<?= e($editing['name'] ?? '') ?>">
            <label for="customer-phone">Phone number</label><input id="customer-phone" name="phone" maxlength="40" value="<?= e($editing['phone'] ?? '') ?>">
            <label for="customer-email">Email</label><input id="customer-email" name="email" type="email" maxlength="254" value="<?= e($editing['email'] ?? '') ?>">
            <label for="customer-address">Address</label><textarea id="customer-address" name="address" rows="3"><?= e($editing['address'] ?? '') ?></textarea>
            <button class="button button-primary" type="submit"><?= $editing ? 'Save customer' : 'Add customer' ?></button>
            <?php if ($editing): ?><a class="cancel-link" href="<?= e(APP_BASE_URL) ?>/customers.php">Cancel editing</a><?php endif; ?>
        </form>
    </article><?php endif; ?>
    <article class="panel table-panel">
        <div class="panel-heading"><div><span class="eyebrow">CUSTOMER LIST</span><h3>Customers <span class="count-pill"><?= count($customers) ?></span></h3></div></div>
        <form class="search-form" method="get"><input name="q" type="search" placeholder="Name, phone or email" value="<?= e($search) ?>"><button class="button button-light" type="submit">Search</button></form>
        <?php if ($customers === []): ?><div class="empty-state"><strong>No customers found</strong><p>Walk-in customers can be left unregistered at checkout.</p></div>
        <?php else: ?><div class="table-scroll"><table><thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Orders</th><?php if ($isAdmin): ?><th></th><?php endif; ?><th></th></tr></thead><tbody>
            <?php foreach ($customers as $customer): ?><tr><td><strong><?= e($customer['name']) ?></strong></td><td><?= e($customer['phone'] ?? '—') ?></td><td><?= e($customer['email'] ?? '—') ?></td><td><?= e($customer['order_count']) ?></td>
                <?php if ($isAdmin): ?><td><a href="<?= e(APP_BASE_URL) ?>/customers.php?edit=<?= e($customer['id']) ?>">Edit</a></td><?php endif; ?>
                <td><a href="<?= e(APP_BASE_URL) ?>/customers.php?history=<?= e($customer['id']) ?>">History</a></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </article>
</section>
<?php if ($historyId): ?><section class="panel table-panel inventory-list">
    <div class="panel-heading"><div><span class="eyebrow">ORDER HISTORY</span><h3><?= e($historyCustomer['name'] ?? 'Customer not found') ?></h3></div></div>
    <?php if ($historyCustomer && $historyOrders === []): ?><div class="empty-state compact"><strong>No orders for this customer yet</strong></div>
    <?php elseif ($historyCustomer): ?><div class="table-scroll"><table><thead><tr><th>Order</th><th>Date</th><th>Status</th><th class="align-right">Total</th><th></th></tr></thead><tbody>
        <?php foreach ($historyOrders as $order): ?><tr><td><strong><?= e($order['order_number']) ?></strong></td><td><?= e((new DateTimeImmutable($order['order_date']))->format('d M Y, H:i')) ?></td><td><?= e(ucfirst($order['status'])) ?></td><td class="align-right"><?= e(format_money($order['final_total'])) ?></td><td><a href="<?= e(APP_BASE_URL) ?>/order_details.php?id=<?= e($order['id']) ?>">Details</a></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section><?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
