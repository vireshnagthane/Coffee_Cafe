<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

$user = require_role('administrator');
$error = null;
$fromDate = (string) ($_GET['from'] ?? '');
$toDate = (string) ($_GET['to'] ?? '');
$category = trim((string) ($_GET['category'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        try {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $categoryInput = trim((string) ($_POST['category'] ?? ''));
            $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
            $date = (string) ($_POST['expense_date'] ?? '');
            $description = trim((string) ($_POST['description'] ?? ''));
            $parsedDate = DateTime::createFromFormat('!Y-m-d', $date);
            if ($categoryInput === '' || mb_strlen($categoryInput) > 100 ||
                $amount === false || $amount <= 0 || $amount > 9999999999 ||
                !$parsedDate || $parsedDate->format('Y-m-d') !== $date || mb_strlen($description) > 4000) {
                throw new InvalidArgumentException('Enter a category, positive amount and valid expense date.');
            }
            $params = [
                'category' => $categoryInput, 'amount' => number_format((float) $amount, 2, '.', ''),
                'date' => $date, 'description' => $description ?: null,
            ];
            if ($id) {
                $params['id'] = $id;
                $sql = 'UPDATE expenses SET category = :category, amount = :amount, expense_date = :date,
                        description = :description WHERE id = :id';
            } else {
                $params['user'] = $user['id'];
                $sql = 'INSERT INTO expenses (category, amount, expense_date, description, user_id)
                        VALUES (:category, :amount, :date, :description, :user)';
            }
            db()->prepare($sql)->execute($params);
            set_flash('success', 'Expense saved.');
            redirect('/expenses.php');
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('[Coffee Management] Expense save failed: ' . $exception->getMessage());
            $error = 'The expense could not be saved. Check the application error log.';
        }
    }
}

$expenses = [];
$editing = null;
$total = 0.0;
try {
    $pdo = db();
    $where = [];
    $params = [];
    $validDate = static function (string $value): bool {
        if ($value === '') {
            return true;
        }
        $parsed = DateTime::createFromFormat('!Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    };
    if (!$validDate($fromDate) || !$validDate($toDate) ||
        ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate)) {
        throw new InvalidArgumentException('Choose valid expense dates, with the start date on or before the end date.');
    }
    if ($fromDate !== '') {
        $where[] = 'e.expense_date >= :from_date';
        $params['from_date'] = $fromDate;
    }
    if ($toDate !== '') {
        $where[] = 'e.expense_date <= :to_date';
        $params['to_date'] = $toDate;
    }
    if ($category !== '') {
        $where[] = 'e.category = :category';
        $params['category'] = $category;
    }
    $sql = 'SELECT e.*, u.full_name FROM expenses e INNER JOIN users u ON u.id = e.user_id';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY e.expense_date DESC, e.id DESC LIMIT 500';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $expenses = $statement->fetchAll();
    foreach ($expenses as $expense) {
        $total += (float) $expense['amount'];
    }
    if (isset($_GET['edit'])) {
        $statement = $pdo->prepare('SELECT * FROM expenses WHERE id = :id');
        $statement->execute(['id' => filter_var($_GET['edit'], FILTER_VALIDATE_INT)]);
        $editing = $statement->fetch() ?: null;
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('[Coffee Management] Expenses query failed: ' . $exception->getMessage());
    $error = 'Expense records are unavailable. Confirm that the database is imported and running.';
}

$pageTitle = 'Expenses';
require __DIR__ . '/includes/header.php';
?>
<section class="welcome-row"><div><h2>Operating expenses</h2><p>Record and review day-to-day cafe costs.</p></div><strong class="date-pill">Filtered total: <?= e(format_money($total)) ?></strong></section>
<?php if ($error !== null): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="content-grid management-grid">
    <article class="panel">
        <div class="panel-heading"><div><span class="eyebrow">EXPENSE ENTRY</span><h3><?= $editing ? 'Edit expense' : 'Record expense' ?></h3></div></div>
        <form method="post" class="stacked-form">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
            <label for="expense-category">Category</label><input id="expense-category" name="category" maxlength="100" required placeholder="Rent, electricity, staff…" value="<?= e($editing['category'] ?? '') ?>">
            <label for="expense-amount">Amount (INR)</label><input id="expense-amount" name="amount" type="number" min="0.01" step="0.01" required value="<?= e($editing['amount'] ?? '') ?>">
            <label for="expense-date">Date</label><input id="expense-date" name="expense_date" type="date" required value="<?= e($editing['expense_date'] ?? date('Y-m-d')) ?>">
            <label for="expense-description">Description</label><textarea id="expense-description" name="description" rows="3"><?= e($editing['description'] ?? '') ?></textarea>
            <button class="button button-primary" type="submit"><?= $editing ? 'Save changes' : 'Add expense' ?></button>
            <?php if ($editing): ?><a class="cancel-link" href="<?= e(APP_BASE_URL) ?>/expenses.php">Cancel editing</a><?php endif; ?>
        </form>
    </article>
    <article class="panel table-panel">
        <div class="panel-heading"><div><span class="eyebrow">EXPENSE HISTORY</span><h3>Recorded expenses</h3></div></div>
        <form class="filter-bar" method="get"><input type="date" name="from" aria-label="From date" value="<?= e($fromDate) ?>"><input type="date" name="to" aria-label="To date" value="<?= e($toDate) ?>"><input name="category" placeholder="Exact category" value="<?= e($category) ?>"><button class="button button-light" type="submit">Filter</button></form>
        <?php if ($expenses === []): ?><div class="empty-state"><strong>No expenses found</strong><p>Recorded business costs will show here.</p></div>
        <?php else: ?><div class="table-scroll"><table><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Recorded by</th><th class="align-right">Amount</th><th></th></tr></thead><tbody>
            <?php foreach ($expenses as $expense): ?><tr><td><?= e((new DateTimeImmutable($expense['expense_date']))->format('d M Y')) ?></td><td><strong><?= e($expense['category']) ?></strong></td><td><?= e($expense['description'] ?? '—') ?></td><td><?= e($expense['full_name']) ?></td><td class="align-right"><?= e(format_money($expense['amount'])) ?></td><td><a href="<?= e(APP_BASE_URL) ?>/expenses.php?edit=<?= e($expense['id']) ?>">Edit</a></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
