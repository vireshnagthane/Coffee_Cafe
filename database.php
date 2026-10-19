<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

$user = require_role('administrator');
$pdo = null;
$error = null;
$selectedTable = (string) ($_GET['table'] ?? $_POST['table'] ?? '');
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page === false || $page < 1 ? 1 : $page;
$pageSize = 50;
$editingRow = null;
$editKey = null;
$tables = [];
$tableMetadata = [];

$quoteIdentifier = static function (string $identifier): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Invalid database identifier.');
    }

    return '`' . $identifier . '`';
};

$encodeKey = static function (array $key): string {
    return rtrim(strtr(base64_encode(json_encode($key, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
};

$decodeKey = static function (mixed $encoded, array $primaryColumns): ?array {
    if (!is_string($encoded) || strlen($encoded) > 4096) {
        return null;
    }
    $json = base64_decode(strtr($encoded, '-_', '+/'), true);
    if ($json === false) {
        return null;
    }
    $key = json_decode($json, true);
    if (!is_array($key) || array_keys($key) !== $primaryColumns) {
        return null;
    }
    foreach ($primaryColumns as $column) {
        if (!isset($key[$column]) || !is_scalar($key[$column])) {
            return null;
        }
    }

    return $key;
};

try {
    $pdo = db();
    $tableQuery = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\'
         ORDER BY TABLE_NAME'
    );
    $tables = $tableQuery->fetchAll(PDO::FETCH_COLUMN);

    if ($selectedTable !== '' && !in_array($selectedTable, $tables, true)) {
        throw new InvalidArgumentException('Choose a table from this application database.');
    }

    if ($selectedTable !== '') {
        $columnQuery = $pdo->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY,
                    EXTRA, CHARACTER_MAXIMUM_LENGTH, ORDINAL_POSITION
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
             ORDER BY ORDINAL_POSITION'
        );
        $columnQuery->execute(['table' => $selectedTable]);
        foreach ($columnQuery->fetchAll() as $column) {
            $tableMetadata[$column['COLUMN_NAME']] = $column;
        }
        $primaryColumns = array_keys(array_filter(
            $tableMetadata,
            static fn (array $column): bool => $column['COLUMN_KEY'] === 'PRI'
        ));
        if ($primaryColumns === []) {
            throw new RuntimeException('This table has no primary key and cannot be safely managed here.');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
                throw new InvalidArgumentException('Your form expired. Refresh the page and try again.');
            }

            $action = (string) ($_POST['action'] ?? '');
            $key = $decodeKey($_POST['key'] ?? null, $primaryColumns);
            if ($key === null) {
                throw new InvalidArgumentException('The selected record is invalid. Return to the table and try again.');
            }

            $whereParts = [];
            $whereValues = [];
            foreach ($primaryColumns as $index => $column) {
                $whereParts[] = $quoteIdentifier($column) . ' = :key' . $index;
                $whereValues['key' . $index] = $key[$column];
            }
            $whereClause = implode(' AND ', $whereParts);
            $quotedTable = $quoteIdentifier($selectedTable);

            if ($action === 'delete') {
                if (($_POST['confirm_delete'] ?? '') !== 'yes') {
                    throw new InvalidArgumentException('Confirm that you want to permanently delete this record.');
                }
                if ($selectedTable === 'users') {
                    $targetId = (int) ($key['id'] ?? 0);
                    if ($targetId === (int) $user['id']) {
                        throw new InvalidArgumentException('You cannot delete your own signed-in account.');
                    }
                    $activeAdminCount = (int) $pdo->query(
                        "SELECT COUNT(*) FROM users WHERE role = 'administrator' AND status = 'active'"
                    )->fetchColumn();
                    $targetQuery = $pdo->prepare(
                        'SELECT role, status FROM users WHERE id = :id'
                    );
                    $targetQuery->execute(['id' => $targetId]);
                    $targetUser = $targetQuery->fetch();
                    if (
                        is_array($targetUser)
                        && $targetUser['role'] === 'administrator'
                        && $targetUser['status'] === 'active'
                        && $activeAdminCount <= 1
                    ) {
                        throw new InvalidArgumentException('You cannot delete the last active administrator.');
                    }
                }

                $delete = $pdo->prepare(
                    'DELETE FROM ' . $quotedTable . ' WHERE ' . $whereClause
                );
                $delete->execute($whereValues);
                if ($delete->rowCount() !== 1) {
                    throw new InvalidArgumentException('That record no longer exists.');
                }
                set_flash('success', 'Database record deleted.');
                redirect('/database.php?table=' . rawurlencode($selectedTable));
            }

            if ($action !== 'update') {
                throw new InvalidArgumentException('Choose a valid database action.');
            }

            $editableColumns = array_filter(
                $tableMetadata,
                static fn (array $column, string $name): bool =>
                    $column['COLUMN_KEY'] !== 'PRI'
                    && stripos($column['EXTRA'], 'GENERATED') === false
                    && !($selectedTable === 'users' && $name === 'password_hash'),
                ARRAY_FILTER_USE_BOTH
            );
            $postedValues = $_POST['field'] ?? null;
            if (!is_array($postedValues)) {
                throw new InvalidArgumentException('The submitted record fields are invalid.');
            }
            $assignments = [];
            $values = $whereValues;
            $proposedUserValues = [];
            foreach ($editableColumns as $name => $column) {
                if (!array_key_exists($name, $postedValues) || !is_string($postedValues[$name])) {
                    throw new InvalidArgumentException('A record field is missing or invalid.');
                }
                $value = $postedValues[$name];
                if ($column['IS_NULLABLE'] === 'YES' && $value === '') {
                    $value = null;
                }
                if (
                    $selectedTable === 'users'
                    && (int) ($key['id'] ?? 0) === (int) $user['id']
                    && (
                        ($name === 'status' && $value !== 'active')
                        || ($name === 'role' && $value !== 'administrator')
                    )
                ) {
                    throw new InvalidArgumentException('You cannot remove administrator access from your signed-in account.');
                }
                if ($selectedTable === 'users' && in_array($name, ['role', 'status'], true)) {
                    $proposedUserValues[$name] = $value;
                }

                $parameter = 'field_' . count($assignments);
                $assignments[] = $quoteIdentifier($name) . ' = :' . $parameter;
                $values[$parameter] = $value;
            }
            if ($selectedTable === 'users' && isset($key['id'])) {
                $targetQuery = $pdo->prepare('SELECT role, status FROM users WHERE id = :id');
                $targetQuery->execute(['id' => $key['id']]);
                $currentUser = $targetQuery->fetch();
                if (
                    is_array($currentUser)
                    && $currentUser['role'] === 'administrator'
                    && $currentUser['status'] === 'active'
                    && (
                        ($proposedUserValues['role'] ?? $currentUser['role']) !== 'administrator'
                        || ($proposedUserValues['status'] ?? $currentUser['status']) !== 'active'
                    )
                    && (int) $pdo->query(
                        "SELECT COUNT(*) FROM users WHERE role = 'administrator' AND status = 'active'"
                    )->fetchColumn() <= 1
                ) {
                    throw new InvalidArgumentException('You cannot remove access from the last active administrator.');
                }
            }
            if ($assignments === []) {
                throw new InvalidArgumentException('This record has no editable fields.');
            }

            $update = $pdo->prepare(
                'UPDATE ' . $quotedTable . ' SET ' . implode(', ', $assignments)
                . ' WHERE ' . $whereClause
            );
            $update->execute($values);
            $check = $pdo->prepare(
                'SELECT 1 FROM ' . $quotedTable . ' WHERE ' . $whereClause
            );
            $check->execute($whereValues);
            if (!$check->fetchColumn()) {
                throw new InvalidArgumentException('That record no longer exists.');
            }
            set_flash('success', 'Database record updated.');
            redirect('/database.php?table=' . rawurlencode($selectedTable));
        }

        $editingKey = $decodeKey($_GET['edit'] ?? null, $primaryColumns);
        if ($editingKey !== null) {
            $whereParts = [];
            $keyValues = [];
            foreach ($primaryColumns as $index => $column) {
                $whereParts[] = $quoteIdentifier($column) . ' = :key' . $index;
                $keyValues['key' . $index] = $editingKey[$column];
            }
            $selectColumns = array_keys($tableMetadata);
            if ($selectedTable === 'users') {
                $selectColumns = array_values(array_filter(
                    $selectColumns,
                    static fn (string $name): bool => $name !== 'password_hash'
                ));
            }
            $selectList = implode(', ', array_map($quoteIdentifier, $selectColumns));
            $editQuery = $pdo->prepare(
                'SELECT ' . $selectList . ' FROM ' . $quoteIdentifier($selectedTable)
                . ' WHERE ' . implode(' AND ', $whereParts) . ' LIMIT 1'
            );
            $editQuery->execute($keyValues);
            $editingRow = $editQuery->fetch();
            if (!is_array($editingRow)) {
                throw new InvalidArgumentException('That record no longer exists.');
            }
            $editKey = $encodeKey($editingKey);
        }
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    error_log('[Coffee Management] Database browser operation failed: ' . $exception->getMessage());
    $error = 'The database operation failed. Check the submitted values, linked records, and application error log.';
} catch (Throwable $exception) {
    error_log('[Coffee Management] Database browser failed: ' . $exception->getMessage());
    $error = 'The database browser is unavailable. Check the application error log.';
}

$rows = [];
$totalRows = 0;
$primaryColumns = [];
$editableColumns = [];
if ($selectedTable !== '' && $tableMetadata !== [] && $pdo instanceof PDO) {
    $primaryColumns = array_keys(array_filter(
        $tableMetadata,
        static fn (array $column): bool => $column['COLUMN_KEY'] === 'PRI'
    ));
    $editableColumns = array_filter(
        $tableMetadata,
        static fn (array $column, string $name): bool =>
            $column['COLUMN_KEY'] !== 'PRI'
            && stripos($column['EXTRA'], 'GENERATED') === false
            && !($selectedTable === 'users' && $name === 'password_hash'),
        ARRAY_FILTER_USE_BOTH
    );

    try {
        $quotedTable = $quoteIdentifier($selectedTable);
        $totalRows = (int) $pdo->query('SELECT COUNT(*) FROM ' . $quotedTable)->fetchColumn();
        $page = min($page, max(1, (int) ceil($totalRows / $pageSize)));
        $offset = ($page - 1) * $pageSize;
        $selectColumns = array_keys($tableMetadata);
        if ($selectedTable === 'users') {
            $selectColumns = array_values(array_filter(
                $selectColumns,
                static fn (string $name): bool => $name !== 'password_hash'
            ));
        }
        $selectList = implode(', ', array_map($quoteIdentifier, $selectColumns));
        $orderBy = implode(', ', array_map($quoteIdentifier, $primaryColumns));
        $rowQuery = $pdo->query(
            'SELECT ' . $selectList . ' FROM ' . $quotedTable
            . ' ORDER BY ' . $orderBy
            . ' LIMIT ' . $pageSize . ' OFFSET ' . $offset
        );
        $rows = $rowQuery->fetchAll();
    } catch (Throwable $exception) {
        error_log('[Coffee Management] Database browser list failed: ' . $exception->getMessage());
        $error = 'Records could not be loaded. Check the application error log.';
    }
}

$formatValue = static function (mixed $value): string {
    if ($value === null) {
        return 'NULL';
    }
    if (is_string($value) && strlen($value) > 120) {
        return substr($value, 0, 117) . '...';
    }

    return (string) $value;
};

$pageTitle = 'Database';
require __DIR__ . '/includes/header.php';
?>
<section class="welcome-row">
    <div><h2>Database records</h2><p>View, edit, or delete records in the application database.</p></div>
</section>
<div class="alert alert-error" role="note"><strong>Careful:</strong> changes affect live shop data. Editing records here may bypass normal stock and sales workflows; deletions are permanent and may be blocked when linked records exist. Back up your database first and use the normal management pages for routine updates.</div>
<?php if ($error !== null): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>

<section class="panel database-browser">
    <form method="get" class="search-form database-table-picker">
        <label for="database-table">Table</label>
        <select id="database-table" name="table" required>
            <option value="">Choose a table</option>
            <?php foreach ($tables as $table): ?>
                <option value="<?= e($table) ?>" <?= $selectedTable === $table ? 'selected' : '' ?>><?= e($table) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="button button-light" type="submit">Open table</button>
    </form>

    <?php if ($editingRow !== null && $editKey !== null): ?>
        <div class="database-editor">
            <div class="panel-heading">
                <div><span class="eyebrow">EDIT RECORD</span><h3><?= e($selectedTable) ?></h3></div>
                <a class="button button-light" href="<?= e(APP_BASE_URL) ?>/database.php?table=<?= e(rawurlencode($selectedTable)) ?>">Cancel</a>
            </div>
            <?php if ($selectedTable === 'users'): ?><p class="form-hint">Password hashes are hidden and cannot be changed here. Use Team accounts to manage passwords and account access.</p><?php endif; ?>
            <form method="post" class="stacked-form database-edit-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="table" value="<?= e($selectedTable) ?>">
                <input type="hidden" name="key" value="<?= e($editKey) ?>">
                <?php foreach ($primaryColumns as $column): ?>
                    <label><?= e($column) ?> <span class="optional-label">Primary key · read-only</span></label>
                    <input type="text" value="<?= e($formatValue($editingRow[$column])) ?>" disabled>
                <?php endforeach; ?>
                <?php foreach ($editableColumns as $name => $column): ?>
                    <?php
                    $value = $editingRow[$name];
                    $enumValues = [];
                    if (str_starts_with(strtolower($column['COLUMN_TYPE']), 'enum(')) {
                        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $column['COLUMN_TYPE'], $matches);
                        $enumValues = array_map('stripcslashes', $matches[1] ?? []);
                    }
                    $isLongText = in_array(strtolower($column['DATA_TYPE']), ['text', 'mediumtext', 'longtext', 'json'], true);
                    ?>
                    <label for="field-<?= e($name) ?>"><?= e($name) ?><?= $column['IS_NULLABLE'] === 'YES' ? ' (optional; blank saves NULL)' : '' ?></label>
                    <?php if ($enumValues !== []): ?>
                        <select id="field-<?= e($name) ?>" name="field[<?= e($name) ?>]" <?= $column['IS_NULLABLE'] === 'NO' ? 'required' : '' ?>>
                            <?php if ($column['IS_NULLABLE'] === 'YES'): ?><option value="">NULL</option><?php endif; ?>
                            <?php foreach ($enumValues as $enumValue): ?>
                                <option value="<?= e($enumValue) ?>" <?= (string) $value === $enumValue ? 'selected' : '' ?>><?= e($enumValue) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($isLongText): ?>
                        <textarea id="field-<?= e($name) ?>" name="field[<?= e($name) ?>]" rows="3" <?= $column['IS_NULLABLE'] === 'NO' ? 'required' : '' ?>><?= e($value) ?></textarea>
                    <?php else: ?>
                        <input id="field-<?= e($name) ?>" name="field[<?= e($name) ?>]" type="text" value="<?= e($value) ?>" <?= $column['CHARACTER_MAXIMUM_LENGTH'] !== null ? 'maxlength="' . e($column['CHARACTER_MAXIMUM_LENGTH']) . '"' : '' ?> <?= $column['IS_NULLABLE'] === 'NO' ? 'required' : '' ?>>
                    <?php endif; ?>
                <?php endforeach; ?>
                <button class="button button-primary" type="submit">Save record</button>
            </form>
        </div>
    <?php elseif ($selectedTable !== '' && $tableMetadata !== []): ?>
        <div class="panel-heading database-record-heading">
            <div><span class="eyebrow">TABLE RECORDS</span><h3><?= e($selectedTable) ?></h3></div>
            <span class="subtle-tag"><?= e($totalRows) ?> records · showing <?= e(count($rows)) ?> per page</span>
        </div>
        <?php if ($selectedTable === 'users'): ?><p class="form-hint">Password hashes are never displayed. Use Team accounts for password and account-status changes.</p><?php endif; ?>
        <?php if ($rows === []): ?>
            <div class="empty-state compact"><strong>This table has no records</strong></div>
        <?php else: ?>
            <div class="table-scroll">
                <table class="database-table">
                    <thead><tr><?php foreach (array_keys($rows[0]) as $column): ?><th><?= e($column) ?></th><?php endforeach; ?><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $keyValues = [];
                        foreach ($primaryColumns as $column) {
                            $keyValues[$column] = $row[$column];
                        }
                        $encodedKey = $encodeKey($keyValues);
                        ?>
                        <tr>
                            <?php foreach ($row as $value): ?><td><?= e($formatValue($value)) ?></td><?php endforeach; ?>
                            <td>
                                <div class="database-row-actions">
                                    <a class="table-action" href="<?= e(APP_BASE_URL) ?>/database.php?table=<?= e(rawurlencode($selectedTable)) ?>&amp;edit=<?= e(rawurlencode($encodedKey)) ?>">Edit</a>
                                    <form method="post" data-confirm="Permanently delete this record? This cannot be undone.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="table" value="<?= e($selectedTable) ?>">
                                        <input type="hidden" name="key" value="<?= e($encodedKey) ?>">
                                        <label class="database-delete-confirm"><input type="checkbox" name="confirm_delete" value="yes" required> Confirm</label>
                                        <button class="text-button danger-text" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalRows > $pageSize): ?>
                <nav class="database-pagination" aria-label="Database table pages">
                    <?php if ($page > 1): ?><a class="button button-light" href="<?= e(APP_BASE_URL) ?>/database.php?table=<?= e(rawurlencode($selectedTable)) ?>&amp;page=<?= e($page - 1) ?>">Previous</a><?php endif; ?>
                    <span>Page <?= e($page) ?> of <?= e((int) ceil($totalRows / $pageSize)) ?></span>
                    <?php if ($page * $pageSize < $totalRows): ?><a class="button button-light" href="<?= e(APP_BASE_URL) ?>/database.php?table=<?= e(rawurlencode($selectedTable)) ?>&amp;page=<?= e($page + 1) ?>">Next</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
