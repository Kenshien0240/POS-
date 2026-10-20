<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_admin();
$pdo  = db();

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf_or_fail();
    if (post('action') === 'toggle') {
        $id = (int) post('id');
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();

        if (!$target) {
            flash('danger', 'User not found.');
        } elseif ((int) $target['id'] === (int) $user['id']) {
            flash('danger', 'You cannot deactivate your own account.');
        } elseif ((int) $target['status'] === 1 && $target['role'] === 'admin') {
            // Ensure at least one active administrator remains. Locking the rows
            // stops two concurrent requests from removing the last two admins.
            try {
                $pdo->beginTransaction();
                $activeAdmins = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 1 FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
                if (count($activeAdmins) <= 1) {
                    $pdo->rollBack();
                    flash('danger', 'At least one active administrator is required.');
                } else {
                    $pdo->prepare('UPDATE users SET status = 0 WHERE id = ?')->execute([$id]);
                    $pdo->commit();
                    flash('success', 'User deactivated.');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                log_exception($e, 'User deactivation failed');
                flash('danger', 'Unable to deactivate the user.');
            }
        } else {
            $pdo->prepare('UPDATE users SET status = 1 - status WHERE id = ?')->execute([$id]);
            flash('success', 'User status updated.');
        }
    }
    redirect('admin/users.php');
}

// ---------------------------------------------------------------------------
// Filters + list
// ---------------------------------------------------------------------------
$search = get('q', '');
$role   = get('role', '');

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(username LIKE ? OR name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if (in_array($role, ['admin', 'cashier'], true)) {
    $where[] = 'role = ?';
    $params[] = $role;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count = $pdo->prepare("SELECT COUNT(*) FROM users $whereSql");
$count->execute($params);
$pg = paginate((int) $count->fetchColumn(), 15);

$stmt = $pdo->prepare("SELECT * FROM users $whereSql ORDER BY status DESC, role, name LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$stmt->execute($params);
$users = $stmt->fetchAll();

$_page_title    = 'Users';
$_page_subtitle = 'Manage administrator and cashier accounts.';
$_active_nav    = 'users';
$_page_actions  = '<a class="btn btn--primary btn--sm" href="' . e(url('admin/user_form.php')) . '">' . icon('plus', 'icon', 16) . ' Add user</a>';

require __DIR__ . '/../includes/header.php';
?>

<div class="toolbar">
    <form method="get" action="<?= e(url('admin/users.php')) ?>">
        <div class="search-box">
            <span class="search-box__icon"><?= icon('search', 'icon', 18) ?></span>
            <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search name or usernameâ€¦" aria-label="Search users">
        </div>
        <select class="select" name="role" data-autosubmit aria-label="Filter by role">
            <option value="">All roles</option>
            <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Administrators</option>
            <option value="cashier" <?= $role === 'cashier' ? 'selected' : '' ?>>Cashiers</option>
        </select>
        <button class="btn btn--ghost" type="submit"><?= icon('filter', 'icon', 16) ?> Apply</button>
    </form>
</div>

<div class="card">
    <?php if (!$users): ?>
        <div class="state">
            <div class="state__icon"><?= icon('users', 'icon', 32) ?></div>
            <p class="state__title">No users found</p>
            <p class="state__text">Add a new account to get started.</p>
            <a class="btn btn--primary" href="<?= e(url('admin/user_form.php')) ?>"><?= icon('plus', 'icon', 18) ?> Add user</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last login</th>
                        <th class="actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <span class="cell-title"><?= e($u['name']) ?></span>
                                <?php if ((int) $u['id'] === (int) $user['id']): ?><span class="badge badge--accent" style="margin-left:.4rem">You</span><?php endif; ?>
                            </td>
                            <td class="mono"><?= e($u['username']) ?></td>
                            <td>
                                <span class="role-badge role-badge--<?= e($u['role']) ?>"><?= e(ucfirst($u['role'])) ?></span>
                            </td>
                            <td>
                                <?php if ((int) $u['status'] === 1): ?>
                                    <span class="badge badge--success"><span class="dot"></span>Active</span>
                                <?php else: ?>
                                    <span class="badge badge--muted">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted nowrap"><?= e(nice_date($u['last_login_at'])) ?></td>
                            <td class="actions">
                                <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/user_form.php?id=' . (int) $u['id'])) ?>"><?= icon('edit', 'icon', 15) ?> Edit</a>
                                <?php if ((int) $u['id'] !== (int) $user['id']): ?>
                                    <form method="post" action="<?= e(url('admin/users.php')) ?>" class="inline-form" data-confirm="Change the status of â€œ<?= e($u['name']) ?>â€?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                        <button class="btn btn--ghost btn--sm" type="submit"><?= icon('power', 'icon', 15) ?> <?= (int) $u['status'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination_links($pg) ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
