<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

$user = require_auth();
$isAdmin = $user['role'] === 'administrator';
$error = null;
$message = null;
$search = trim((string) ($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Only administrators can manage categories.');
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            $pdo = db();
            if ($action === 'save') {
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                $name = trim((string) ($_POST['name'] ?? ''));
                $description = trim((string) ($_POST['description'] ?? ''));
                if ($name === '' || mb_strlen($name) > 100) {
                    throw new InvalidArgumentException('Enter a category name of up to 100 characters.');
                }
                if ($id) {
                    $statement = $pdo->prepare(
                        'UPDATE categories SET name = :name, description = :description WHERE id = :id'
                    );
                    $statement->execute(['name' => $name, 'description' => $description ?: null, 'id' => $id]);
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO categories (name, description) VALUES (:name, :description)'
                    );
                    $statement->execute(['name' => $name, 'description' => $description ?: null]);
                }
                set_flash('success', 'Category saved.');
                redirect('/categories.php');
            }
            if ($action === 'delete') {
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id) {
                    throw new InvalidArgumentException('Select a valid category.');
                }
                $statement = $pdo->prepare('DELETE FROM categories WHERE id = :id');
                $statement->execute(['id' => $id]);
                if ($statement->rowCount() === 0) {
                    throw new InvalidArgumentException('That category no longer exists.');
                }
                set_flash('success', 'Category deleted.');
                redirect('/categories.php');
            }
            throw new InvalidArgumentException('Invalid category action.');
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $error = 'That category name is already in use, or the category is assigned to products.';
            } else {
                error_log('[Coffee Management] Category save failed: ' . $exception->getMessage());
                $error = 'The category could not be saved. Check the application error log.';
            }
        } catch (Throwable $exception) {
            error_log('[Coffee Management] Category operation failed: ' . $exception->getMessage());
            $error = 'The category could not be saved. Check the application error log.';
        }
    }
}

$categories = [];
$editing = null;
try {
    $pdo = db();
    $sql = 'SELECT c.id, c.name, c.description, c.created_at, COUNT(p.id) AS product_count
            FROM categories c LEFT JOIN products p ON p.category_id = c.id';
    $params = [];
    if ($search !== '') {
        $sql .= ' WHERE c.name LIKE :name_search OR c.description LIKE :description_search';
        $params['name_search'] = '%' . $search . '%';
        $params['description_search'] = '%' . $search . '%';
    }
    $sql .= ' GROUP BY c.id, c.name, c.description, c.created_at ORDER BY c.name';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $categories = $statement->fetchAll();

    if (isset($_GET['edit'])) {
        $statement = $pdo->prepare('SELECT id, name, description FROM categories WHERE id = :id');
        $statement->execute(['id' => filter_var($_GET['edit'], FILTER_VALIDATE_INT)]);
        $editing = $statement->fetch() ?: null;
    }
} catch (Throwable $exception) {
    error_log('[Coffee Management] Categories query failed: ' . $exception->getMessage());
    $error = 'Categories are unavailable. Confirm that the database is imported and running.';
}

$pageTitle = 'Categories';
require __DIR__ . '/includes/header.php';
?>
<section class="welcome-row">
    <div><h2>Product categories</h2><p>Organize the menu into clear groups.</p></div>
    <?php if ($isAdmin): ?><a class="button button-primary" href="#category-form">Add category <span>＋</span></a><?php endif; ?>
</section>
<?php if ($error !== null): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="content-grid management-grid <?= $isAdmin ? '' : 'management-single' ?>">
    <?php if ($isAdmin): ?>
    <article class="panel">
        <div class="panel-heading"><div><span class="eyebrow">CATEGORY DETAILS</span><h3><?= $editing ? 'Edit category' : 'New category' ?></h3></div></div>
        <form method="post" id="category-form" class="stacked-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
            <label for="category-name">Name</label>
            <input id="category-name" name="name" maxlength="100" required value="<?= e($editing['name'] ?? '') ?>">
            <label for="category-description">Description <span class="optional-label">Optional</span></label>
            <textarea id="category-description" name="description" rows="4"><?= e($editing['description'] ?? '') ?></textarea>
            <button class="button button-primary" type="submit"><?= $editing ? 'Save changes' : 'Create category' ?></button>
            <?php if ($editing): ?><a class="cancel-link" href="<?= e(APP_BASE_URL) ?>/categories.php">Cancel editing</a><?php endif; ?>
        </form>
    </article>
    <?php endif; ?>
    <article class="panel table-panel">
        <div class="panel-heading"><div><span class="eyebrow">MENU ORGANIZATION</span><h3>All categories <span class="count-pill"><?= count($categories) ?></span></h3></div></div>
        <form class="search-form" method="get">
            <input name="q" type="search" placeholder="Search categories" value="<?= e($search) ?>">
            <button class="button button-light" type="submit">Search</button>
        </form>
        <?php if ($categories === []): ?>
            <div class="empty-state"><strong>No categories found</strong><p>Add a category to start organizing products.</p></div>
        <?php else: ?>
            <div class="table-scroll"><table>
                <thead><tr><th>Category</th><th>Description</th><th>Products</th><?php if ($isAdmin): ?><th>Actions</th><?php endif; ?></tr></thead>
                <tbody><?php foreach ($categories as $category): ?>
                    <tr>
                        <td><strong><?= e($category['name']) ?></strong></td>
                        <td><?= e($category['description'] ?: '—') ?></td>
                        <td><?= e($category['product_count']) ?></td>
                        <?php if ($isAdmin): ?><td class="row-actions">
                            <a href="<?= e(APP_BASE_URL) ?>/categories.php?edit=<?= e($category['id']) ?>">Edit</a>
                            <?php if ((int) $category['product_count'] === 0): ?>
                                <form method="post" data-confirm="Delete this unused category?">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($category['id']) ?>">
                                    <button type="submit" class="text-button danger-text">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td><?php endif; ?>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
