<?php
session_start();
require_once '../auth-guard/Auth.php';

// SAMPLE DATA (Replace with database later)
$users = [
    ["name" => "Juan dela Cruz", "email" => "admin@wmsu.edu.ph", "role" => "Admin", "status" => "Active", "date" => "Jan 10, 2024"],
    ["name" => "Maria Santos", "email" => "viewer@wmsu.edu.ph", "role" => "Viewer", "status" => "Active", "date" => "Feb 15, 2024"],
    ["name" => "Pedro Reyes", "email" => "pedro@wmsu.edu.ph", "role" => "Viewer", "status" => "Inactive", "date" => "Mar 20, 2024"],
    ["name" => "Ana Gonzales", "email" => "ana@wmsu.edu.ph", "role" => "Admin", "status" => "Active", "date" => "Apr 05, 2024"],
    ["name" => "Carlos Mendoza", "email" => "carlos@wmsu.edu.ph", "role" => "Viewer", "status" => "Active", "date" => "May 18, 2024"],
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100">

<div class="flex">

    <?php include '../sidebar/sidebar.php'; ?>

    <!-- MAIN CONTENT -->
    <main class="flex-1 ml-64 p-6">

        <!-- HEADER -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Dashboard</h1>
                <p class="text-gray-500 text-sm">User management</p>
            </div>

            <button onclick="openModal()" 
                class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg shadow">
                Add User
            </button>
        </div>

        <!-- CARD -->
        <div class="bg-white rounded-xl shadow p-5">

            <!-- TOP BAR -->
            <div class="flex justify-between items-center mb-4">
                <div>
                    <h2 class="font-semibold text-gray-700">User Accounts</h2>
                    <p class="text-sm text-gray-400"><?= count($users) ?> users registered</p>
                </div>

                <input type="text" placeholder="Search users..."
                    class="border px-3 py-2 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-red-500">
            </div>

            <!-- TABLE -->
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-gray-400 uppercase text-xs border-b">
                        <tr>
                            <th class="py-3 text-left">#</th>
                            <th class="py-3 text-left">Full Name</th>
                            <th class="py-3 text-left">Email</th>
                            <th class="py-3 text-left">Role</th>
                            <th class="py-3 text-left">Status</th>
                            <th class="py-3 text-left">Date Created</th>
                            <th class="py-3 text-center">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="text-gray-600">
                        <?php foreach ($users as $index => $user): ?>
                            <tr class="border-b hover:bg-gray-50">

                                <td class="py-3"><?= $index + 1 ?></td>

                                <td class="py-3 flex items-center gap-2">
                                    <div class="w-8 h-8 rounded-full bg-red-200 text-red-600 flex items-center justify-center font-bold">
                                        <?= strtoupper($user['name'][0]) ?>
                                    </div>
                                    <?= $user['name'] ?>
                                </td>

                                <td class="py-3"><?= $user['email'] ?></td>

                                <td class="py-3">
                                    <?php if ($user['role'] === "Admin"): ?>
                                        <span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-600">Admin</span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-600">Viewer</span>
                                    <?php endif; ?>
                                </td>

                                <td class="py-3">
                                    <?php if ($user['status'] === "Active"): ?>
                                        <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-600">Active</span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 text-xs rounded-full bg-gray-200 text-gray-500">Inactive</span>
                                    <?php endif; ?>
                                </td>

                                <td class="py-3"><?= $user['date'] ?></td>

                                <td class="py-3 text-center space-x-2">
                                    <button class="hover:text-blue-500">✏️</button>
                                    <button class="hover:text-red-500">🗑️</button>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>

    </main>

</div>

<!-- MODAL -->
<div id="userModal" class="fixed inset-0 bg-black bg-opacity-40 hidden items-center justify-center z-50">

    <div class="bg-white w-full max-w-lg rounded-xl shadow-lg overflow-hidden">

        <!-- HEADER -->
        <div class="bg-red-600 text-white px-6 py-4 flex justify-between items-center">
            <div>
                <h2 class="font-semibold text-lg">Add New User</h2>
                <p class="text-sm">Fill in the details below</p>
            </div>
            <button onclick="closeModal()" class="text-white text-xl">&times;</button>
        </div>

        <!-- FORM -->
        <div class="p-6 space-y-4">

            <div>
                <label class="text-sm font-medium">Full Name *</label>
                <input type="text" placeholder="e.g. Juan dela Cruz"
                    class="w-full border px-3 py-2 rounded-lg mt-1 focus:ring-2 focus:ring-red-500">
            </div>

            <div>
                <label class="text-sm font-medium">Email Address *</label>
                <input type="email" placeholder="user@wmsu.edu.ph"
                    class="w-full border px-3 py-2 rounded-lg mt-1 focus:ring-2 focus:ring-red-500">
            </div>

            <div>
                <label class="text-sm font-medium">Password *</label>
                <input type="password"
                    class="w-full border px-3 py-2 rounded-lg mt-1 focus:ring-2 focus:ring-red-500">
            </div>

            <div class="flex gap-4">
                <div class="w-1/2">
                    <label class="text-sm font-medium">Role *</label>
                    <select class="w-full border px-3 py-2 rounded-lg mt-1">
                        <option>Select role</option>
                        <option>Admin</option>
                        <option>Viewer</option>
                    </select>
                </div>

                <div class="w-1/2">
                    <label class="text-sm font-medium">Status *</label>
                    <select class="w-full border px-3 py-2 rounded-lg mt-1">
                        <option>Active</option>
                        <option>Inactive</option>
                    </select>
                </div>
            </div>

            <!-- ACTIONS -->
<div class="pt-6 flex justify-end gap-3">

    <button onclick="closeModal()" 
        class="h-10 px-6 flex items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-600 hover:bg-gray-100">
        Cancel
    </button>

    <button 
        class="h-10 px-6 flex items-center justify-center rounded-lg bg-red-600 text-white hover:bg-red-700">
        Add User
    </button>

</div>

<!-- SCRIPT -->
<script>
function openModal() {
    const modal = document.getElementById('userModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeModal() {
    const modal = document.getElementById('userModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// OPTIONAL: Close when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('userModal');
    if (event.target === modal) {
        closeModal();
    }
}
</script>

</body>
</html>