<?php
session_start();
require_once '../auth-guard/Auth.php';
require_once '../config/db.php';

$pdo = getPDO();

function mapRoleForReceiver(string $role): string {
    $map = ['Admin' => 'ADMIN', 'Staff' => 'STAFF', 'Faculty' => 'FACULTY', 'Employee' => 'STAFF'];
    return $map[$role] ?? 'STAFF';
}

function getInitialsFromEmail($email) {
    if (empty($email)) return 'U';
    $namePart = explode('@', $email)[0];
    $parts    = preg_split('/[._-]/', $namePart);
    $initials = '';
    foreach ($parts as $part) {
        if (!empty($part)) $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) break;
    }
    return $initials ?: strtoupper(substr($email, 0, 1)) ?: 'U';
}

$user_email        = $_SESSION['user_email'] ?? '';
$user_initials     = getInitialsFromEmail($user_email);
$user_role         = $_SESSION['user_role']  ?? 'user';
$user_role_display = ucfirst($user_role);

$success = '';
$error   = '';

// ── ADD ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $username   = trim($_POST['username']   ?? '');
    $email      = trim($_POST['email']      ?? '');
    $password   = trim($_POST['password']   ?? '');
    $full_name  = trim($_POST['full_name']  ?? '');
    $role       = trim($_POST['role']       ?? 'Staff');
    $department = trim($_POST['department'] ?? '');
    $position   = trim($_POST['position']   ?? '');
    $is_active  = ($_POST['is_active'] ?? '0') === '1' ? 1 : 0;

    if (!$username || !$email || !$password || !$full_name) {
        $error = 'Please fill in all required fields.';
    } else {
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = :e OR username = :u LIMIT 1");
        $chk->execute([':e' => $email, ':u' => $username]);
        if ($chk->fetch()) {
            $error = 'Username or email already exists.';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, full_name, role, department, position, is_active)
                    VALUES (:username, :email, :password_hash, :full_name, :role, :department, :position, :is_active)
                ")->execute([
                    ':username'      => $username,
                    ':email'         => $email,
                    ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    ':full_name'     => $full_name,
                    ':role'          => $role,
                    ':department'    => $department,
                    ':position'      => $position,
                    ':is_active'     => $is_active,
                ]);
                $pdo->prepare("
                    INSERT INTO receivers (name, department, role, email)
                    VALUES (:name, :department, :role, :email)
                ")->execute([
                    ':name'       => $full_name,
                    ':department' => $department ?: 'N/A',
                    ':role'       => mapRoleForReceiver($role),
                    ':email'      => $email,
                ]);
                $pdo->commit();
                $success = 'User added successfully.';
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// ── EDIT ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $id         = (int)   ($_POST['id']         ?? 0);
    $username   = trim($_POST['username']        ?? '');
    $email      = trim($_POST['email']           ?? '');
    $full_name  = trim($_POST['full_name']       ?? '');
    $role       = trim($_POST['role']            ?? 'Staff');
    $department = trim($_POST['department']      ?? '');
    $position   = trim($_POST['position']        ?? '');
    $is_active  = ($_POST['is_active'] ?? '0') === '1' ? 1 : 0;
    $password   = trim($_POST['password']        ?? '');

    if (!$id || !$username || !$email || !$full_name) {
        $error = 'Please fill in all required fields.';
    } else {
        $chk = $pdo->prepare("SELECT id FROM users WHERE (email=:e OR username=:u) AND id!=:id LIMIT 1");
        $chk->execute([':e' => $email, ':u' => $username, ':id' => $id]);
        if ($chk->fetch()) {
            $error = 'Username or email already used by another user.';
        } else {
            try {
                $pdo->beginTransaction();
                $old = $pdo->prepare("SELECT email FROM users WHERE id=:id");
                $old->execute([':id' => $id]);
                $oldUser = $old->fetch();

                if ($password) {
                    $pdo->prepare("
                        UPDATE users SET username=:username, email=:email, password_hash=:pw,
                            full_name=:full_name, role=:role, department=:department,
                            position=:position, is_active=:is_active WHERE id=:id
                    ")->execute([':username'=>$username,':email'=>$email,':pw'=>password_hash($password,PASSWORD_BCRYPT),':full_name'=>$full_name,':role'=>$role,':department'=>$department,':position'=>$position,':is_active'=>$is_active,':id'=>$id]);
                } else {
                    $pdo->prepare("
                        UPDATE users SET username=:username, email=:email,
                            full_name=:full_name, role=:role, department=:department,
                            position=:position, is_active=:is_active WHERE id=:id
                    ")->execute([':username'=>$username,':email'=>$email,':full_name'=>$full_name,':role'=>$role,':department'=>$department,':position'=>$position,':is_active'=>$is_active,':id'=>$id]);
                }

                if ($oldUser) {
                    $pdo->prepare("
                        UPDATE receivers SET name=:name, department=:department, role=:role, email=:email
                        WHERE email=:old_email
                    ")->execute([':name'=>$full_name,':department'=>$department?:'N/A',':role'=>mapRoleForReceiver($role),':email'=>$email,':old_email'=>$oldUser['email']]);
                }
                $pdo->commit();
                $success = 'User updated successfully.';
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// ── DELETE ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) {
        $error = 'Invalid user.';
    } else {
        try {
            $pdo->beginTransaction();
            $sel = $pdo->prepare("SELECT email FROM users WHERE id=:id");
            $sel->execute([':id' => $id]);
            $user = $sel->fetch();
            $pdo->prepare("DELETE FROM users WHERE id=:id")->execute([':id' => $id]);
            if ($user) {
                $pdo->prepare("DELETE FROM receivers WHERE email=:email")->execute([':email' => $user['email']]);
            }
            $pdo->commit();
            $success = 'User deleted successfully.';
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

$users = $pdo->query("
    SELECT id, username, email, full_name, role, department, position, is_active, created_at
    FROM users ORDER BY created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — WMSU Document Management</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        crimson: {
                            950:'#4D0001',900:'#800002',800:'#AA0003',
                            700:'#D91619',600:'#FF3336',500:'#FF4D50',
                            400:'#FF666A',300:'#FF8083',200:'#FF999D',
                            100:'#FFB3B6',50:'#FFCCCE',
                        }
                    },
                    fontFamily: {
                        'main':      ['"Noto Nastaliq Urdu"','serif'],
                        'secondary': ['"IBM Plex Sans"','sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family:'IBM Plex Sans',sans-serif; }
        h1,h2,h3,h4,h5,h6 { font-family:'Noto Nastaliq Urdu',serif; }
    </style>
</head>
<body class="bg-gray-100">

    <?php $active_page = 'dashboard'; include __DIR__ . '/../sidebar/sidebar.php'; ?>

    <main class="lg:ml-64 min-h-screen">

        <!-- Top Bar -->
        <header class="bg-white shadow-sm sticky top-0 z-20">
            <div class="px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="ml-12 lg:ml-0">
                        <h2 class="text-2xl font-bold text-gray-800 font-main">Dashboard</h2>
                        <p class="text-sm text-gray-600 mt-1 font-secondary">User management &amp; account overview</p>
                    </div>
                    <div class="flex items-center space-x-4">
                        <button class="relative p-2 text-gray-600 hover:text-crimson-700 transition duration-200">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                            </svg>
                            <span class="absolute top-1 right-1 w-2 h-2 bg-crimson-600 rounded-full"></span>
                        </button>
                        <div class="flex items-center space-x-3">
                            <div class="hidden sm:block text-right">
                                <p class="text-sm font-semibold text-gray-800 font-secondary"><?= htmlspecialchars($user_email ?: 'Guest User') ?></p>
                                <p class="text-xs text-gray-600 font-secondary"><?= htmlspecialchars($user_role_display) ?></p>
                            </div>
                            <div class="w-10 h-10 bg-crimson-700 rounded-full flex items-center justify-center">
                                <span class="text-white font-semibold font-secondary"><?= htmlspecialchars($user_initials) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <div class="px-4 sm:px-6 lg:px-8 py-8">

            <?php if ($success): ?>
            <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm font-secondary flex justify-between items-center">
                <?= htmlspecialchars($success) ?>
                <button onclick="this.parentElement.remove()" class="font-bold text-green-500 hover:text-green-700 ml-4">&times;</button>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm font-secondary flex justify-between items-center">
                <?= htmlspecialchars($error) ?>
                <button onclick="this.parentElement.remove()" class="font-bold text-red-500 hover:text-red-700 ml-4">&times;</button>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-2xl shadow p-6">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h3 class="text-xl font-bold text-gray-800 font-main">User Accounts</h3>
                        <p class="text-sm text-gray-500 font-secondary mt-1"><?= count($users) ?> users registered</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <input type="text" id="searchInput" placeholder="Search users..." oninput="filterTable()"
                            class="px-4 py-2 border-2 border-gray-200 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary w-52">
                        <button onclick="openModal('addModal')"
                            class="bg-crimson-700 hover:bg-crimson-800 text-white px-4 py-2 rounded-lg transition duration-200 text-sm font-semibold font-secondary">
                            + Add User
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-gray-400 uppercase text-xs border-b font-secondary">
                            <tr>
                                <th class="py-3 text-left">#</th>
                                <th class="py-3 text-left">Full Name</th>
                                <th class="py-3 text-left">Email</th>
                                <th class="py-3 text-left">Role</th>
                                <th class="py-3 text-left">Department</th>
                                <th class="py-3 text-left">Status</th>
                                <th class="py-3 text-left">Date Created</th>
                                <th class="py-3 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600 divide-y divide-gray-100 font-secondary">
                            <?php foreach ($users as $i => $u): ?>
                            <tr class="hover:bg-gray-50 user-row"
                                data-search="<?= strtolower(htmlspecialchars($u['full_name'] . ' ' . $u['email'] . ' ' . ($u['department'] ?? ''))) ?>">
                                <td class="py-3 text-gray-400"><?= $i + 1 ?></td>
                                <td class="py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-full bg-crimson-100 text-crimson-700 flex items-center justify-center font-bold text-sm shrink-0">
                                            <?= strtoupper($u['full_name'][0] ?? '?') ?>
                                        </div>
                                        <div>
                                            <p class="font-semibold text-gray-800 leading-tight"><?= htmlspecialchars($u['full_name']) ?></p>
                                            <p class="text-xs text-gray-400">@<?= htmlspecialchars($u['username']) ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3"><?= htmlspecialchars($u['email']) ?></td>
                                <td class="py-3">
                                    <?php
                                    $rc = ['Admin'=>'bg-crimson-100 text-crimson-700','Staff'=>'bg-blue-100 text-blue-600','Faculty'=>'bg-purple-100 text-purple-600','Employee'=>'bg-amber-100 text-amber-700'][$u['role']] ?? 'bg-gray-100 text-gray-600';
                                    ?>
                                    <span class="px-2 py-1 text-xs rounded-full font-semibold <?= $rc ?>"><?= htmlspecialchars($u['role']) ?></span>
                                </td>
                                <td class="py-3 text-gray-500"><?= htmlspecialchars($u['department'] ?? '—') ?></td>
                                <td class="py-3">
                                    <span class="px-2 py-1 text-xs rounded-full font-semibold <?= $u['is_active'] ? 'bg-green-100 text-green-600' : 'bg-gray-200 text-gray-500' ?>">
                                        <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td class="py-3 text-gray-400 text-xs"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                                <td class="py-3">
                                    <div class="flex items-center justify-center gap-2">
                                        <button onclick="openEditModal(<?= $u['id'] ?>,'<?= htmlspecialchars($u['username'],ENT_QUOTES) ?>','<?= htmlspecialchars($u['email'],ENT_QUOTES) ?>','<?= htmlspecialchars($u['full_name'],ENT_QUOTES) ?>','<?= htmlspecialchars($u['role'],ENT_QUOTES) ?>','<?= htmlspecialchars($u['department']??'',ENT_QUOTES) ?>','<?= htmlspecialchars($u['position']??'',ENT_QUOTES) ?>',<?= $u['is_active'] ? 'true':'false' ?>)"
                                            class="px-3 py-1.5 text-xs font-semibold bg-blue-50 text-blue-600 hover:bg-blue-100 rounded-lg transition font-secondary">Edit</button>
                                        <button onclick="openDeleteModal(<?= $u['id'] ?>,'<?= htmlspecialchars($u['full_name'],ENT_QUOTES) ?>')"
                                            class="px-3 py-1.5 text-xs font-semibold bg-crimson-50 text-crimson-700 hover:bg-crimson-100 rounded-lg transition font-secondary">Delete</button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($users)): ?>
                            <tr><td colspan="8" class="py-10 text-center text-gray-400 font-secondary">No users found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <p id="noResults" class="hidden text-center py-8 text-gray-400 text-sm font-secondary">No users match your search.</p>
                </div>
            </div>
        </div>
    </main>

<!-- ══════ ADD MODAL ══════ -->
<div id="addModal" class="fixed inset-0 bg-black bg-opacity-40 hidden items-center justify-center z-50">
    <div class="bg-white w-full max-w-lg rounded-2xl shadow-2xl overflow-hidden">
        <div class="bg-crimson-700 text-white px-6 py-5 flex justify-between items-center">
            <div>
                <h2 class="font-bold text-lg font-main">Add New User</h2>
                <p class="text-sm text-crimson-100 font-secondary">Fill in the details below</p>
            </div>
            <button onclick="closeModal('addModal')" class="text-2xl leading-none hover:opacity-75">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="p-6 space-y-4 max-h-[70vh] overflow-y-auto">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1 font-secondary">Username <span class="text-crimson-600">*</span></label>
                        <input type="text" name="username" required placeholder="e.g. jdelacruz"
                            class="w-full border-2 border-gray-200 px-3 py-2.5 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition font-secondary">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1 font-secondary">Full Name <span class="text-crimson-600">*</span></label>
                        <input type="text" name="full_name" required placeholder="e.g. Juan dela Cruz"
                            class="w-full border-2 border-gray-200 px-3 py-2.5 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition font-secondary">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1 font-secondary">Email <span class="text-crimson-600">*</span></label>
                    <input type="email" name="email" required placeholder="user@wmsu.edu.ph"
                        class="w-full border-2 border-gray-200 px-3 py-2.5 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition font-secondary">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1 font-secondary">Password <span class="text-crimson-600">*</span></label>
                    <input type="password" name="password" required
                        class="w-full border-2 border-gray-200 px-3 py-2.5 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition font-secondary">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1 font-secondary">Role <span class="text-crimson-600">*</span></label>
                        <select name="role" class="w-full border-2 border-gray-200 px-3 py-2.5 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition font-secondary">
                            <option value="Staff">Staff</option>
                            <option value="Admin">Admin</option>
                            <option value="Faculty">Faculty</option>
                            <option value="Employee">Employee</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1 font-secondary">Status</label>
                        <select name="is_active" class="w-full border-2 border-gray-200 px-3 py-2.5 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition font-secondary">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1 font-secondary">Department</label>
                        <input type="text" name="department" placeholder="e.g. IT Department"
                            class="w-full border-2 border-gray-200 px-3 py-2.5 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition font-secondary">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1 font-secondary">Position</label>
                        <input type="text" name="position" placeholder="e.g. System Admin"
                            class="w-full border-2 border-gray-200 px-3 py-2.5 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition font-secondary">
                    </div>
                </div>
            </div>
            <div class="px-6 py-4 bg-gray-50 border-t flex justify-end gap-3">
                <button type="button" onclick="closeModal('addModal')"
                    class="px-4 py-2 rounded-lg border-2 border-gray-200 text-gray-600 hover:bg-gray-100 text-sm font-secondary font-semibold">Cancel</button>
                <button type="submit"
                    class="px-4 py-2 rounded-lg bg-crimson-700 text-white hover:bg-crimson-800 text-sm font-semibold font-secondary transition duration-200">Add User</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════ EDIT MODAL ══════ -->
<div id="editModal" class="fixed inset-0 bg-black bg-opacity-40 hidden items-center justify-center z-50">
    <div class="bg-white w-full max-w-lg rounded-2xl shadow-2xl overflow-hidden">
        <div class="bg-gray-800 text-white px-6 py-5 flex justify-between items-center">
            <div>
                <h2 class="font-bold text-lg font-main">Edit User</h2>
                <p class="text-sm text-gray-300 font-secondary">Update user details</p>
            </div>
            <button onclick="closeModal('editModal')" class="text-2xl leading-none hover:opacity-75">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">
            <div class="p-6 space-y-4 max-h-[70vh] overflow-y-auto">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1 font-secondary">Username <span class="text-crimson-600">*</span></label>
                        <input type="text" name="username" id="editUsername" required
                            class="w-full border-2 border-gray-200 px-3 py-2.5 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition font-secondary">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1 font-secondary">Full Name <span class="text-crimson-600">*</span></label>
                        <input type="text" name="full_name" id="editFullName" required
                            class="w-full border-2 border-gray-200 px-3 py-2.5 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition font-secondary">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1 font-secondary">Email <span class="text-crimson-600">*</span></label>
                    <input type="email" name="email" id="editEmail" required
                        class="w-full border-2 border-gray-200 px-3 py-2.5 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition font-secondary">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1 font-secondary">New Password <span class="text-gray-400 font-normal text-xs">(leave blank to keep current)</span></label>
                    <input type="password" name="password" id="editPassword" placeholder="••••••••"
                        class="w-full border-2 border-gray-200 px-3 py-2.5 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition font-secondary">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1 font-secondary">Role <span class="text-crimson-600">*</span></label>
                        <select name="role" id="editRole" class="w-full border-2 border-gray-200 px-3 py-2.5 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition font-secondary">
                            <option value="Staff">Staff</option>
                            <option value="Admin">Admin</option>
                            <option value="Faculty">Faculty</option>
                            <option value="Employee">Employee</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1 font-secondary">Status</label>
                        <select name="is_active" id="editStatus" class="w-full border-2 border-gray-200 px-3 py-2.5 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition font-secondary">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1 font-secondary">Department</label>
                        <input type="text" name="department" id="editDepartment"
                            class="w-full border-2 border-gray-200 px-3 py-2.5 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition font-secondary">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1 font-secondary">Position</label>
                        <input type="text" name="position" id="editPosition"
                            class="w-full border-2 border-gray-200 px-3 py-2.5 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition font-secondary">
                    </div>
                </div>
            </div>
            <div class="px-6 py-4 bg-gray-50 border-t flex justify-end gap-3">
                <button type="button" onclick="closeModal('editModal')"
                    class="px-4 py-2 rounded-lg border-2 border-gray-200 text-gray-600 hover:bg-gray-100 text-sm font-secondary font-semibold">Cancel</button>
                <button type="submit"
                    class="px-4 py-2 rounded-lg bg-gray-800 text-white hover:bg-gray-900 text-sm font-semibold font-secondary transition duration-200">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════ DELETE MODAL ══════ -->
<div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-40 hidden items-center justify-center z-50">
    <div class="bg-white w-full max-w-sm rounded-2xl shadow-2xl p-8 text-center">
        <div class="w-14 h-14 bg-crimson-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-7 h-7 text-crimson-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
        </div>
        <h3 class="text-lg font-bold text-gray-800 mb-1 font-main">Delete User</h3>
        <p class="text-sm text-gray-500 mb-1 font-secondary">Are you sure you want to delete</p>
        <p class="font-semibold text-gray-800 mb-1 font-secondary" id="deleteUserName"></p>
        <p class="text-xs text-crimson-600 mb-6 font-secondary">This will also remove them from the receivers list.</p>
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId">
            <div class="flex gap-3 justify-center">
                <button type="button" onclick="closeModal('deleteModal')"
                    class="px-5 py-2 rounded-lg border-2 border-gray-200 text-gray-600 hover:bg-gray-100 text-sm font-secondary font-semibold">Cancel</button>
                <button type="submit"
                    class="px-5 py-2 rounded-lg bg-crimson-700 text-white hover:bg-crimson-800 text-sm font-semibold font-secondary transition duration-200">Yes, Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.remove('hidden'); document.getElementById(id).classList.add('flex'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); document.getElementById(id).classList.remove('flex'); }

['addModal','editModal','deleteModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) { if (e.target === this) closeModal(id); });
});

function openEditModal(id, username, email, fullName, role, dept, position, isActive) {
    document.getElementById('editId').value         = id;
    document.getElementById('editUsername').value   = username;
    document.getElementById('editEmail').value      = email;
    document.getElementById('editFullName').value   = fullName;
    document.getElementById('editPassword').value   = '';
    document.getElementById('editDepartment').value = dept;
    document.getElementById('editPosition').value   = position;
    const roleEl = document.getElementById('editRole');
    for (let o of roleEl.options) o.selected = o.value === role;
    const statusEl = document.getElementById('editStatus');
    for (let o of statusEl.options) o.selected = o.value === (isActive ? '1' : '0');
    openModal('editModal');
}

function openDeleteModal(id, name) {
    document.getElementById('deleteId').value             = id;
    document.getElementById('deleteUserName').textContent = name;
    openModal('deleteModal');
}

function filterTable() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    const rows = document.querySelectorAll('.user-row');
    let visible = 0;
    rows.forEach(row => {
        const match = row.dataset.search.includes(q);
        row.classList.toggle('hidden', !match);
        if (match) visible++;
    });
    document.getElementById('noResults').classList.toggle('hidden', visible > 0);
}
</script>
</body>
</html>
