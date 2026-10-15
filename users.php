<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

$user = require_role('administrator');
$error = null;
$search = trim((string) ($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            $pdo = db();
            if ($action === 'create') {
                $name = trim((string) ($_POST['full_name'] ?? ''));
                $username = trim((string) ($_POST['username'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');
                $role = (string) ($_POST['role'] ?? '');
                if ($name === '' || mb_strlen($name) > 150 || !preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $username) ||
                    strlen($password) < 12 || !in_array($role, ['administrator', 'cashier'], true)) {
                    throw new InvalidArgumentException('Enter a name, username (3–80 letters/numbers/._-), password of at least 12 characters and valid role.');
                }
                $statement = $pdo->prepare(
                    "INSERT INTO users (full_name, username, password_hash, role, status)
                     VALUES (:name, :username, :password, :role, 'active')"
                );
                $statement->execute([
                    'name' => $name, 'username' => $username,
                    'password' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role,
                ]);
                set_flash('success', 'User account created.');
                redirect('/users.php');
            }
            if ($action === 'status') {
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                $status = (string) ($_POST['status'] ?? '');
                if (!$id || !in_array($status, ['active', 'inactive'], true)) {
                    throw new InvalidArgumentException('Choose a valid user and account status.');
                }
                $targetQuery = $pdo->prepare('SELECT username FROM users WHERE id = :id');
                $targetQuery->execute(['id' => $id]);
                $targetUsername = $targetQuery->fetchColumn();
                if (!is_string($targetUsername)) {
                    throw new InvalidArgumentException('That user no longer exists.');
                }
                if (str_starts_with($targetUsername, 'sample.')) {
                    throw new InvalidArgumentException('Demo sample accounts are kept inactive and cannot be activated.');
                }
                if ((int) $id === (int) $user['id'] && $status === 'inactive') {
                    throw new InvalidArgumentException('You cannot deactivate your own account.');
                }
                $statement = $pdo->prepare('UPDATE users SET status = :status WHERE id = :id');
                $statement->execute(['status' => $status, 'id' => $id]);
                if ($statement->rowCount() === 0) {
                    $check = $pdo->prepare('SELECT id FROM users WHERE id = :id');
                    $check->execute(['id' => $id]);
                    if (!$check->fetchColumn()) {
                        throw new InvalidArgumentException('That user no longer exists.');
                    }
                }
                set_flash('success', 'User account status updated.');
                redirect('/users.php');
            }
            throw new InvalidArgumentException('Invalid user action.');
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $error = 'That username is already in use.';
            } else {
                error_log('[Coffee Management] User operation failed: ' . $exception->getMessage());
                $error = 'The user account could not be saved. Check the application error log.';
            }
        } catch (Throwable $exception) {
            error_log('[Coffee Management] User operation failed: ' . $exception->getMessage());
            $error = 'The user account could not be saved. Check the application error log.';
        }
    }
}

$users = [];
try {
    $statement = db()->prepare(
        'SELECT id, full_name, username, role, status, created_at FROM users
         WHERE full_name LIKE :name_search OR username LIKE :username_search ORDER BY full_name'
    );
    $statement->execute([
        'name_search' => '%' . $search . '%',
        'username_search' => '%' . $search . '%',
    ]);
    $users = $statement->fetchAll();
} catch (Throwable $exception) {
    error_log('[Coffee Management] User list query failed: ' . $exception->getMessage());
    $error = 'User accounts are unavailable. Confirm that the database is imported and running.';
}

$pageTitle = 'User accounts';
require __DIR__ . '/includes/header.php';
?>
<section class="welcome-row"><div><h2>Team accounts</h2><p>Create administrator and cashier logins, or deactivate accounts.</p></div></section>
<?php if ($error !== null): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="content-grid management-grid">
    <article class="panel">
        <div class="panel-heading"><div><span class="eyebrow">NEW TEAM MEMBER</span><h3>Create account</h3></div></div>
        <form method="post" class="stacked-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="create">
            <label for="user-name">Full name</label><input id="user-name" name="full_name" maxlength="150" required>
            <label for="user-username">Username</label><input id="user-username" name="username" minlength="3" maxlength="80" pattern="[A-Za-z0-9_.-]+" autocomplete="off" required>
            <label for="user-password">Temporary password</label><input id="user-password" name="password" type="password" minlength="12" autocomplete="new-password" required>
            <label for="user-role">Role</label><select id="user-role" name="role"><option value="cashier">Cashier</option><option value="administrator">Administrator</option></select>
            <p class="form-hint">Use a unique password with at least 12 characters. Passwords are stored as secure hashes.</p>
            <button class="button button-primary" type="submit">Create account</button>
        </form>
    </article>
    <article class="panel table-panel">
        <div class="panel-heading"><div><span class="eyebrow">ACCESS CONTROL</span><h3>Application users <span class="count-pill"><?= count($users) ?></span></h3></div></div>
        <form class="search-form" method="get"><input name="q" type="search" placeholder="Search name or username" value="<?= e($search) ?>"><button class="button button-light" type="submit">Search</button></form>
        <div class="table-scroll"><table><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Created</th><th></th></tr></thead><tbody>
            <?php foreach ($users as $listedUser): ?><tr><td><strong><?= e($listedUser['full_name']) ?></strong></td><td><?= e($listedUser['username']) ?></td><td><?= e(ucfirst($listedUser['role'])) ?></td><td><?= e(ucfirst($listedUser['status'])) ?></td><td><?= e((new DateTimeImmutable($listedUser['created_at']))->format('d M Y')) ?></td><td>
                <?php if ((int) $listedUser['id'] === (int) $user['id']): ?><span class="optional-label">You</span>
                <?php elseif (str_starts_with($listedUser['username'], 'sample.')): ?><span class="optional-label">Demo record</span>
                <?php else: ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= e($listedUser['id']) ?>"><input type="hidden" name="status" value="<?= $listedUser['status'] === 'active' ? 'inactive' : 'active' ?>"><button class="text-button" type="submit"><?= $listedUser['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form><?php endif; ?>
            </td></tr><?php endforeach; ?>
        </tbody></table></div>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
