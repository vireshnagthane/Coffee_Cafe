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
        exit('Only administrators can manage suppliers.');
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        try {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $name = trim((string) ($_POST['name'] ?? ''));
            $contact = trim((string) ($_POST['contact_person'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $address = trim((string) ($_POST['address'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150 || mb_strlen($contact) > 150 ||
                mb_strlen($phone) > 40 || mb_strlen($email) > 254 || mb_strlen($address) > 4000 ||
                ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
                throw new InvalidArgumentException('Enter a supplier name and valid optional contact details.');
            }
            $params = ['name' => $name, 'contact' => $contact ?: null, 'phone' => $phone ?: null, 'email' => $email ?: null, 'address' => $address ?: null];
            if ($id) {
                $params['id'] = $id;
                $sql = 'UPDATE suppliers SET name = :name, contact_person = :contact, phone = :phone, email = :email, address = :address WHERE id = :id';
            } else {
                $sql = 'INSERT INTO suppliers (name, contact_person, phone, email, address) VALUES (:name, :contact, :phone, :email, :address)';
            }
            db()->prepare($sql)->execute($params);
            set_flash('success', 'Supplier saved.');
            redirect('/suppliers.php');
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('[Coffee Management] Supplier save failed: ' . $exception->getMessage());
            $error = 'The supplier could not be saved. Check the application error log.';
        }
    }
}

$suppliers = [];
$editing = null;
try {
    $pdo = db();
    $sql = 'SELECT s.id, s.name, s.contact_person, s.phone, s.email, s.address,
                   COUNT(DISTINCT p.id) AS purchase_count
            FROM suppliers s LEFT JOIN purchases p ON p.supplier_id = s.id';
    $params = [];
    if ($search !== '') {
        $sql .= ' WHERE s.name LIKE :name_search OR s.contact_person LIKE :contact_search OR s.phone LIKE :phone_search';
        $params['name_search'] = '%' . $search . '%';
        $params['contact_search'] = '%' . $search . '%';
        $params['phone_search'] = '%' . $search . '%';
    }
    $sql .= ' GROUP BY s.id, s.name, s.contact_person, s.phone, s.email, s.address ORDER BY s.name';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $suppliers = $statement->fetchAll();
    if ($isAdmin && isset($_GET['edit'])) {
        $statement = $pdo->prepare('SELECT * FROM suppliers WHERE id = :id');
        $statement->execute(['id' => filter_var($_GET['edit'], FILTER_VALIDATE_INT)]);
        $editing = $statement->fetch() ?: null;
    }
} catch (Throwable $exception) {
    error_log('[Coffee Management] Suppliers query failed: ' . $exception->getMessage());
    $error = 'Suppliers are unavailable. Confirm that the database is imported and running.';
}

$pageTitle = 'Suppliers';
require __DIR__ . '/includes/header.php';
?>
<section class="welcome-row"><div><h2>Supplier directory</h2><p>Maintain supplier contacts for ingredient purchasing.</p></div></section>
<?php if ($error !== null): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="content-grid management-grid <?= $isAdmin ? '' : 'management-single' ?>">
    <?php if ($isAdmin): ?><article class="panel">
        <div class="panel-heading"><div><span class="eyebrow">SUPPLIER PROFILE</span><h3><?= $editing ? 'Edit supplier' : 'Add supplier' ?></h3></div></div>
        <form method="post" class="stacked-form">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
            <label for="supplier-name">Supplier name</label><input id="supplier-name" name="name" maxlength="150" required value="<?= e($editing['name'] ?? '') ?>">
            <label for="supplier-contact">Contact person</label><input id="supplier-contact" name="contact_person" maxlength="150" value="<?= e($editing['contact_person'] ?? '') ?>">
            <label for="supplier-phone">Phone</label><input id="supplier-phone" name="phone" maxlength="40" value="<?= e($editing['phone'] ?? '') ?>">
            <label for="supplier-email">Email</label><input id="supplier-email" name="email" type="email" maxlength="254" value="<?= e($editing['email'] ?? '') ?>">
            <label for="supplier-address">Address</label><textarea id="supplier-address" name="address" rows="3"><?= e($editing['address'] ?? '') ?></textarea>
            <button class="button button-primary" type="submit"><?= $editing ? 'Save supplier' : 'Add supplier' ?></button>
            <?php if ($editing): ?><a class="cancel-link" href="<?= e(APP_BASE_URL) ?>/suppliers.php">Cancel editing</a><?php endif; ?>
        </form>
    </article><?php endif; ?>
    <article class="panel table-panel">
        <div class="panel-heading"><div><span class="eyebrow">SUPPLIER LIST</span><h3>Suppliers <span class="count-pill"><?= count($suppliers) ?></span></h3></div></div>
        <form class="search-form" method="get"><input name="q" type="search" placeholder="Name, contact or phone" value="<?= e($search) ?>"><button class="button button-light" type="submit">Search</button></form>
        <?php if ($suppliers === []): ?><div class="empty-state"><strong>No suppliers found</strong><p>Add a supplier before recording ingredient purchases.</p></div>
        <?php else: ?><div class="table-scroll"><table><thead><tr><th>Supplier</th><th>Contact</th><th>Phone</th><th>Email</th><th>Purchases</th><?php if ($isAdmin): ?><th></th><?php endif; ?></tr></thead><tbody>
            <?php foreach ($suppliers as $supplier): ?><tr><td><strong><?= e($supplier['name']) ?></strong></td><td><?= e($supplier['contact_person'] ?? '—') ?></td><td><?= e($supplier['phone'] ?? '—') ?></td><td><?= e($supplier['email'] ?? '—') ?></td><td><?= e($supplier['purchase_count']) ?></td><?php if ($isAdmin): ?><td><a href="<?= e(APP_BASE_URL) ?>/suppliers.php?edit=<?= e($supplier['id']) ?>">Edit</a></td><?php endif; ?></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
