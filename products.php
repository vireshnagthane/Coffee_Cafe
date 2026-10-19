<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

$user = require_auth();
$isAdmin = $user['role'] === 'administrator';
$error = null;
$search = trim((string) ($_GET['q'] ?? ''));
$categoryFilter = filter_var($_GET['category'] ?? null, FILTER_VALIDATE_INT) ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Only administrators can manage products.');
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        $imagePath = null;
        $oldImagePath = null;
        try {
            $pdo = db();
            $action = (string) ($_POST['action'] ?? '');
            $idInput = trim((string) ($_POST['id'] ?? ''));
            $id = null;
            if ($idInput !== '') {
                $validatedId = filter_var($idInput, FILTER_VALIDATE_INT);
                if (!is_int($validatedId) || $validatedId < 1) {
                    throw new InvalidArgumentException('Select a valid product.');
                }
                $id = $validatedId;
            }
            if (in_array($action, ['availability', 'delete'], true) && $id === null) {
                throw new InvalidArgumentException('Select a valid product.');
            }
            if ($action === 'availability') {
                $available = (string) ($_POST['status'] ?? '') === 'available';
                $statement = $pdo->prepare('UPDATE products SET status = :status WHERE id = :id');
                $statement->execute(['status' => $available ? 'available' : 'unavailable', 'id' => $id]);
                set_flash('success', 'Product availability updated.');
                redirect('/products.php');
            }
            if ($action === 'delete') {
                $lookup = $pdo->prepare('SELECT image_path FROM products WHERE id = :id');
                $lookup->execute(['id' => $id]);
                $imageToDelete = $lookup->fetchColumn();
                if ($imageToDelete === false) {
                    throw new InvalidArgumentException('That product no longer exists.');
                }
                $history = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE product_id = :id');
                $history->execute(['id' => $id]);
                if ((int) $history->fetchColumn() > 0) {
                    throw new InvalidArgumentException('This product has order history and cannot be deleted. Disable it instead.');
                }
                $delete = $pdo->prepare('DELETE FROM products WHERE id = :id');
                $delete->execute(['id' => $id]);
                if (is_string($imageToDelete) && str_starts_with($imageToDelete, 'assets/images/products/')) {
                    $oldFile = __DIR__ . '/' . $imageToDelete;
                    if (is_file($oldFile)) {
                        unlink($oldFile);
                    }
                }
                set_flash('success', 'Unused product deleted.');
                redirect('/products.php');
            }
            if ($action !== 'save') {
                throw new InvalidArgumentException('Invalid product action.');
            }
            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
            $priceInput = trim((string) ($_POST['selling_price'] ?? ''));
            $price = filter_var($priceInput, FILTER_VALIDATE_FLOAT);
            $status = (string) ($_POST['status'] ?? 'available');
            if ($name === '' || mb_strlen($name) > 150 || !$categoryId || $price === false || $price < 0 || $price > 99999999.99) {
                throw new InvalidArgumentException('Enter a product name, valid category and non-negative price.');
            }
            if (!in_array($status, ['available', 'unavailable'], true)) {
                throw new InvalidArgumentException('Select a valid availability status.');
            }
            $categoryCheck = $pdo->prepare('SELECT id FROM categories WHERE id = :id');
            $categoryCheck->execute(['id' => $categoryId]);
            if (!$categoryCheck->fetchColumn()) {
                throw new InvalidArgumentException('Choose an existing category.');
            }
            if ($id) {
                $lookup = $pdo->prepare('SELECT image_path FROM products WHERE id = :id');
                $lookup->execute(['id' => $id]);
                $oldImagePath = $lookup->fetchColumn();
                if ($oldImagePath === false) {
                    throw new InvalidArgumentException('That product no longer exists.');
                }
            }
            if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $upload = $_FILES['image'];
                if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 3 * 1024 * 1024) {
                    throw new InvalidArgumentException('Choose an image smaller than 3 MB.');
                }
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($upload['tmp_name']);
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($extensions[$mime]) || @getimagesize($upload['tmp_name']) === false) {
                    throw new InvalidArgumentException('Upload a valid JPG, PNG or WebP image.');
                }
                $imageDirectory = __DIR__ . '/assets/images/products';
                if (!is_dir($imageDirectory) && !mkdir($imageDirectory, 0755, true) && !is_dir($imageDirectory)) {
                    throw new RuntimeException('The product image directory could not be created.');
                }
                $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                if (!move_uploaded_file($upload['tmp_name'], $imageDirectory . '/' . $filename)) {
                    throw new RuntimeException('The product image could not be saved.');
                }
                $imagePath = 'assets/images/products/' . $filename;
            } else {
                $imagePath = is_string($oldImagePath) ? $oldImagePath : null;
            }

            if ($id) {
                $statement = $pdo->prepare(
                    'UPDATE products SET category_id = :category_id, name = :name,
                     description = :description, selling_price = :price, image_path = :image_path,
                     status = :status WHERE id = :id'
                );
                $statement->execute([
                    'category_id' => $categoryId, 'name' => $name, 'description' => $description ?: null,
                    'price' => number_format((float) $price, 2, '.', ''), 'image_path' => $imagePath,
                    'status' => $status, 'id' => $id,
                ]);
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO products (category_id, name, description, selling_price, image_path, status)
                     VALUES (:category_id, :name, :description, :price, :image_path, :status)'
                );
                $statement->execute([
                    'category_id' => $categoryId, 'name' => $name, 'description' => $description ?: null,
                    'price' => number_format((float) $price, 2, '.', ''), 'image_path' => $imagePath, 'status' => $status,
                ]);
            }
            if (is_string($oldImagePath) && $imagePath !== $oldImagePath) {
                $oldFile = __DIR__ . '/' . $oldImagePath;
                if (str_starts_with($oldImagePath, 'assets/images/products/') && is_file($oldFile)) {
                    unlink($oldFile);
                }
            }
            set_flash('success', 'Product saved.');
            redirect('/products.php');
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('[Coffee Management] Product operation failed: ' . $exception->getMessage());
            $error = 'The product could not be saved. Check the application error log.';
        }
    }
}

$products = [];
$categories = [];
$editing = null;
try {
    $pdo = db();
    $categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
    $sql = 'SELECT p.id, p.name, p.description, p.selling_price, p.image_path, p.status,
                   c.name AS category_name, COUNT(oi.id) AS order_item_count
            FROM products p INNER JOIN categories c ON c.id = p.category_id
            LEFT JOIN order_items oi ON oi.product_id = p.id';
    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = 'p.name LIKE :search';
        $params['search'] = '%' . $search . '%';
    }
    if ($categoryFilter) {
        $where[] = 'p.category_id = :category_id';
        $params['category_id'] = $categoryFilter;
    }
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' GROUP BY p.id, p.name, p.description, p.selling_price, p.image_path, p.status, c.name ORDER BY p.name';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $products = $statement->fetchAll();
    if (isset($_GET['edit']) && $isAdmin) {
        $statement = $pdo->prepare('SELECT * FROM products WHERE id = :id');
        $statement->execute(['id' => filter_var($_GET['edit'], FILTER_VALIDATE_INT)]);
        $editing = $statement->fetch() ?: null;
    }
} catch (Throwable $exception) {
    error_log('[Coffee Management] Products query failed: ' . $exception->getMessage());
    $error = 'Products are unavailable. Confirm that the database is imported and running.';
}

$pageTitle = 'Products';
require __DIR__ . '/includes/header.php';
?>
<section class="welcome-row">
    <div><h2>Menu products</h2><p>Manage the items your cafe serves.</p></div>
    <?php if ($isAdmin): ?><a class="button button-primary" href="#product-form">Add product <span>＋</span></a><?php endif; ?>
</section>
<?php if ($error !== null): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="content-grid management-grid <?= $isAdmin ? '' : 'management-single' ?> product-management">
    <?php if ($isAdmin): ?>
    <article class="panel">
        <div class="panel-heading"><div><span class="eyebrow">MENU ITEM</span><h3><?= $editing ? 'Edit product' : 'New product' ?></h3></div></div>
        <form method="post" id="product-form" class="stacked-form" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
            <label for="product-name">Product name</label><input id="product-name" name="name" maxlength="150" required value="<?= e($editing['name'] ?? '') ?>">
            <label for="product-category">Category</label>
            <select id="product-category" name="category_id" required>
                <option value="">Choose a category</option>
                <?php foreach ($categories as $category): ?><option value="<?= e($category['id']) ?>" <?= (string) ($editing['category_id'] ?? '') === (string) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?>
            </select>
            <label for="product-price">Selling price (INR)</label><input id="product-price" name="selling_price" type="number" min="0" max="99999999.99" step="0.01" required value="<?= e($editing['selling_price'] ?? '') ?>">
            <label for="product-description">Description <span class="optional-label">Optional</span></label><textarea id="product-description" name="description" rows="3"><?= e($editing['description'] ?? '') ?></textarea>
            <label for="product-image">Product image <span class="optional-label">JPG, PNG or WebP; max 3 MB</span></label><input id="product-image" name="image" type="file" accept="image/jpeg,image/png,image/webp">
            <label for="product-status">Availability</label>
            <select id="product-status" name="status"><option value="available" <?= ($editing['status'] ?? 'available') === 'available' ? 'selected' : '' ?>>Available</option><option value="unavailable" <?= ($editing['status'] ?? '') === 'unavailable' ? 'selected' : '' ?>>Unavailable</option></select>
            <?php if ($editing && !empty($editing['image_path'])): ?><img class="product-preview" src="<?= e(APP_BASE_URL . '/' . $editing['image_path']) ?>" alt="Current product image"><?php endif; ?>
            <button class="button button-primary" type="submit"><?= $editing ? 'Save changes' : 'Create product' ?></button>
            <?php if ($editing): ?><a class="cancel-link" href="<?= e(APP_BASE_URL) ?>/products.php">Cancel editing</a><?php endif; ?>
        </form>
    </article>
    <?php endif; ?>
    <article class="panel table-panel">
        <div class="panel-heading"><div><span class="eyebrow">YOUR MENU</span><h3>All products <span class="count-pill"><?= count($products) ?></span></h3></div></div>
        <form class="search-form" method="get">
            <input name="q" type="search" placeholder="Search products" value="<?= e($search) ?>">
            <select name="category"><option value="">All categories</option><?php foreach ($categories as $category): ?><option value="<?= e($category['id']) ?>" <?= $categoryFilter === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select>
            <button class="button button-light" type="submit">Filter</button>
        </form>
        <?php if ($products === []): ?><div class="empty-state"><strong>No products found</strong><p>Create categories before adding menu items.</p></div>
        <?php else: ?><div class="table-scroll"><table>
            <thead><tr><th>Product</th><th>Category</th><th>Price</th><th>Status</th><?php if ($isAdmin): ?><th>Actions</th><?php endif; ?></tr></thead>
            <tbody><?php foreach ($products as $product): ?><tr>
                <td><strong><?= e($product['name']) ?></strong></td><td><?= e($product['category_name']) ?></td>
                <td><?= e(format_money($product['selling_price'])) ?></td>
                <td><span class="status-chip <?= $product['status'] === 'available' ? '' : 'status-muted' ?>"><?= e(ucfirst($product['status'])) ?></span></td>
                <?php if ($isAdmin): ?><td class="row-actions">
                    <a href="<?= e(APP_BASE_URL) ?>/products.php?edit=<?= e($product['id']) ?>">Edit</a>
                    <form method="post">
                        <?= csrf_field() ?><input type="hidden" name="action" value="availability"><input type="hidden" name="id" value="<?= e($product['id']) ?>">
                        <input type="hidden" name="status" value="<?= $product['status'] === 'available' ? 'unavailable' : 'available' ?>">
                        <button class="text-button" type="submit"><?= $product['status'] === 'available' ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <?php if ((int) $product['order_item_count'] === 0): ?><form method="post" onsubmit="return confirm('Delete this product?')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($product['id']) ?>">
                        <button class="text-button danger-text" type="submit">Delete</button>
                    </form><?php endif; ?>
                </td><?php endif; ?>
            </tr><?php endforeach; ?></tbody>
        </table></div><?php endif; ?>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
