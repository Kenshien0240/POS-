<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_admin();
$pdo  = db();

$id      = (int) get('id', 0);
$editing = $id > 0;
$account = [
    'username'  => '',
    'name' => '',
    'role'      => 'cashier',
    'status' => 1,
];

if ($editing) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) {
        flash('danger', 'User not found.');
        redirect('admin/users.php');
    }
    $account = $found;
}

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf_or_fail();

    $account['username']  = post('username', '');
    $account['name'] = post('name', '');
    $account['role']      = in_array(post('role'), ['admin', 'cashier'], true) ? post('role') : 'cashier';
    $account['status'] = post('status') ? 1 : 0;

    $password = post('password', '');
    $confirm  = post('password_confirm', '');

    if ($account['username'] === '') {
        $errors['username'] = 'Username is required.';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $account['username'])) {
        $errors['username'] = 'Use 3â€“50 letters, numbers, dots, dashes or underscores.';
    } else {
        $chk = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
        $chk->execute([$account['username'], $id]);
        if ($chk->fetch()) {
            $errors['username'] = 'That username is already taken.';
        }
    }

    if ($account['name'] === '') {
        $errors['name'] = 'Full name is required.';
    } elseif (mb_strlen($account['name']) > 100) {
        $errors['name'] = 'Full name must be 100 characters or fewer.';
    }

    if (!$editing || $password !== '') {
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        } elseif (strlen($password) > 72) {
            $errors['password'] = 'Password must be 72 characters or fewer.';
        } elseif ($password !== $confirm) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }
    }

    // Safety rules around the administrator role.
    if ($editing && (int) $id === (int) $user['id']) {
        if ($account['role'] !== 'admin') {
            $errors['role'] = 'You cannot remove your own administrator role.';
        }
        if ((int) $account['status'] !== 1) {
            $errors['status'] = 'You cannot deactivate your own account.';
        }
    }
    if ($editing && $found['role'] === 'admin' && ($account['role'] !== 'admin' || (int) $account['status'] !== 1)) {
        $activeAdmins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 1")->fetchColumn();
        if ($activeAdmins <= 1) {
            $errors['role'] = 'At least one active administrator is required.';
        }
    }

    if (!$errors) {
        if ($editing) {
            $sql = 'UPDATE users SET username = ?, name = ?, role = ?, status = ?';
            $params = [$account['username'], $account['name'], $account['role'], $account['status']];
            if ($password !== '') {
                $sql .= ', password_hash = ?';
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id = ?';
            $params[] = $id;
            $pdo->prepare($sql)->execute($params);
            flash('success', 'User updated.');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, name, role, status, password_hash) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $account['username'],
                $account['name'],
                $account['role'],
                $account['status'],
                password_hash($password, PASSWORD_DEFAULT),
            ]);
            flash('success', 'User created. Share the password with the new user securely.');
        }
        redirect('admin/users.php');
    }
}

$_page_title    = $editing ? 'Edit user' : 'Add user';
$_page_subtitle = $editing ? 'Update account details and access.' : 'Create a new administrator or cashier account.';
$_active_nav    = 'users';
$_page_actions  = '<a class="btn btn--ghost btn--sm" href="' . e(url('admin/users.php')) . '">' . icon('arrow-left', 'icon', 16) . ' Back to users</a>';

require __DIR__ . '/../includes/header.php';
?>

<form method="post" action="<?= e(url('admin/user_form.php' . ($editing ? '?id=' . $id : ''))) ?>" novalidate style="max-width:760px">
    <?= csrf_field() ?>
    <div class="card mb-3">
        <header class="card__header"><h2>Account details</h2></header>
        <div class="card__body form-grid form-grid--2">
            <label class="field">
                <span class="field__label">Full name <span class="req">*</span></span>
                <input class="input <?= isset($errors['name']) ? 'is-invalid' : '' ?>" type="text" name="name" value="<?= e($account['name']) ?>" maxlength="100" required>
                <?php if (isset($errors['name'])): ?><span class="field__error"><?= icon('alert-circle', 'icon', 14) ?><?= e($errors['name']) ?></span><?php endif; ?>
            </label>

            <label class="field">
                <span class="field__label">Username <span class="req">*</span></span>
                <input class="input mono <?= isset($errors['username']) ? 'is-invalid' : '' ?>" type="text" name="username" value="<?= e($account['username']) ?>" maxlength="50" autocomplete="off" required>
                <?php if (isset($errors['username'])): ?><span class="field__error"><?= icon('alert-circle', 'icon', 14) ?><?= e($errors['username']) ?></span><?php endif; ?>
            </label>

            <label class="field">
                <span class="field__label">Role <span class="req">*</span></span>
                <select class="select <?= isset($errors['role']) ? 'is-invalid' : '' ?>" name="role">
                    <option value="cashier" <?= $account['role'] === 'cashier' ? 'selected' : '' ?>>Cashier â€” point of sale and own sales</option>
                    <option value="admin" <?= $account['role'] === 'admin' ? 'selected' : '' ?>>Administrator â€” full management access</option>
                </select>
                <?php if (isset($errors['role'])): ?><span class="field__error"><?= icon('alert-circle', 'icon', 14) ?><?= e($errors['role']) ?></span><?php endif; ?>
            </label>

            <div class="field">
                <span class="field__label">Status</span>
                <label class="check" style="padding-top:.4rem">
                    <input type="checkbox" name="status" value="1" <?= (int) $account['status'] === 1 ? 'checked' : '' ?>>
                    <span class="check__text"><strong>Active account</strong><small>Inactive users cannot sign in.</small></span>
                </label>
                <?php if (isset($errors['status'])): ?><span class="field__error"><?= icon('alert-circle', 'icon', 14) ?><?= e($errors['status']) ?></span><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <header class="card__header">
            <h2><?= $editing ? 'Change password' : 'Set password' ?></h2>
            <?php if ($editing): ?><small class="ml-auto text-muted">Leave blank to keep the current password</small><?php endif; ?>
        </header>
        <div class="card__body form-grid form-grid--2">
            <label class="field">
                <span class="field__label">Password <?= $editing ? '' : '<span class="req">*</span>' ?></span>
                <input class="input <?= isset($errors['password']) ? 'is-invalid' : '' ?>" type="password" name="password" autocomplete="new-password" <?= $editing ? '' : 'required' ?>>
                <?php if (isset($errors['password'])): ?><span class="field__error"><?= icon('alert-circle', 'icon', 14) ?><?= e($errors['password']) ?></span><?php else: ?><span class="field__hint">Minimum 8 characters.</span><?php endif; ?>
            </label>
            <label class="field">
                <span class="field__label">Confirm password</span>
                <input class="input <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>" type="password" name="password_confirm" autocomplete="new-password">
                <?php if (isset($errors['password_confirm'])): ?><span class="field__error"><?= icon('alert-circle', 'icon', 14) ?><?= e($errors['password_confirm']) ?></span><?php endif; ?>
            </label>
        </div>
    </div>

    <div class="flex gap-sm">
        <button type="submit" class="btn btn--primary btn--lg"><?= icon('check', 'icon', 18) ?> <?= $editing ? 'Save changes' : 'Create user' ?></button>
        <a class="btn btn--ghost btn--lg" href="<?= e(url('admin/users.php')) ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
