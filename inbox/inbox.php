<?php
session_start();
require_once '../auth-guard/Auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inbox</title>

<!-- Tailwind -->
<script src="https://cdn.tailwindcss.com"></script>

</head>
<body class="bg-gray-100">

<div class="flex">

    <!-- SIDEBAR -->
    <?php include __DIR__ . '/../sidebar/sidebar.php'; ?>

    <!-- MAIN CONTENT -->
    <div class="flex-1 p-6 lg:ml-64">

        <!-- HEADER -->
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Inbox</h1>
            <p class="text-gray-500 text-sm">Manage incoming document notifications</p>
        </div>

        <!-- CONTAINER -->
        <div class="bg-white rounded-xl shadow p-5">

            <!-- TOP BAR -->
            <div class="flex items-center justify-between mb-4">

                <h2 class="font-semibold text-gray-700">All Message</h2>

                <input type="text" placeholder="Search Message"
                    class="border rounded-lg px-4 py-2 w-64 focus:outline-none focus:ring-2 focus:ring-blue-400">

            </div>

            <!-- TABLE HEADER -->
            <div class="grid grid-cols-12 text-sm text-gray-500 px-4 py-2 border-b">
                <div class="col-span-3">Sender</div>
                <div class="col-span-3">Subject/ Document</div>
                <div class="col-span-2">Type</div>
                <div class="col-span-2">Status</div>
                <div class="col-span-2 text-right">Date</div>
            </div>

            <!-- MESSAGE ROW 1 -->
            <div onclick="openModal(
                'MARK CRUZ',
                'M',
                'request_travel.png',
                'Jun 12, 2025',
                'Requesting travel with 12 student on May 13 2025'
            )"
            class="grid grid-cols-12 items-center bg-gray-50 rounded-lg p-4 mt-3 shadow-sm hover:shadow-md transition cursor-pointer">

                <div class="col-span-3 flex items-center gap-3">
                    <input type="checkbox" onclick="event.stopPropagation()">

                    <div class="w-10 h-10 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold">
                        M
                    </div>

                    <span class="font-semibold text-gray-700">MARK CRUZ</span>
                </div>

                <div class="col-span-3 text-gray-700 font-medium">
                    Travel Request
                </div>

                <div class="col-span-2 text-gray-600">
                    Transcript
                </div>

                <div class="col-span-2">
                    <span class="bg-yellow-100 text-yellow-700 px-3 py-1 rounded-full text-xs font-semibold">
                        Pending
                    </span>
                </div>

                <div class="col-span-2 text-right text-gray-600 text-sm">
                    Jun 12, 2025
                </div>
            </div>

            <!-- MESSAGE ROW 2 -->
            <div onclick="openModal(
                'FERNANDO',
                'F',
                'memo.pdf',
                'May 3, 2025',
                'This is a memorandum for all staff.'
            )"
            class="grid grid-cols-12 items-center bg-gray-50 rounded-lg p-4 mt-3 shadow-sm hover:shadow-md transition cursor-pointer">

                <div class="col-span-3 flex items-center gap-3">
                    <input type="checkbox" onclick="event.stopPropagation()">

                    <div class="w-10 h-10 rounded-full bg-green-600 text-white flex items-center justify-center font-bold">
                        F
                    </div>

                    <span class="font-semibold text-gray-700">FERNANDO</span>
                </div>

                <div class="col-span-3 text-gray-700 font-medium">
                    Memorandum
                </div>

                <div class="col-span-2 text-gray-600">
                    Memorandum
                </div>

                <div class="col-span-2">
                    <span class="bg-yellow-100 text-yellow-700 px-3 py-1 rounded-full text-xs font-semibold">
                        Pending
                    </span>
                </div>

                <div class="col-span-2 text-right text-gray-600 text-sm">
                    May 3, 2025
                </div>
            </div>

        </div>

    </div>

</div>

<!-- MODAL -->
<div id="messageModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">

    <div class="bg-white w-full max-w-2xl rounded-xl shadow-lg p-6 relative">

        <!-- CLOSE -->
        <button onclick="closeModal()" class="absolute top-4 right-4 text-gray-500 hover:text-black text-xl">
            ✕
        </button>

        <!-- HEADER -->
        <div class="flex items-center gap-3 mb-4">
            <div id="modalAvatar"
                class="w-10 h-10 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold">
                M
            </div>
            <h2 id="modalName" class="font-bold text-lg text-gray-800">NAME</h2>
        </div>

        <!-- DETAILS -->
        <p class="text-sm text-gray-600 mb-1">
            <strong>Document File:</strong> <span id="modalFile"></span>
        </p>
        <p class="text-sm text-gray-500 mb-4">
            Received: <span id="modalDate"></span>
        </p>

        <!-- MESSAGE -->
        <div class="mb-4">
            <label class="text-sm text-gray-600">Message</label>
            <div id="modalMessage"
                class="border rounded-lg p-3 mt-1 text-gray-700 bg-gray-50">
            </div>
        </div>

        <!-- BUTTON -->
        <button class="w-full bg-red-700 text-white py-2 rounded-lg hover:bg-red-800">
            Mark as Received
        </button>

    </div>
</div>

<!-- SCRIPT -->
<script>
function openModal(name, initial, file, date, message) {
    document.getElementById('modalName').innerText = name;
    document.getElementById('modalAvatar').innerText = initial;
    document.getElementById('modalFile').innerText = file;
    document.getElementById('modalDate').innerText = date;
    document.getElementById('modalMessage').innerText = message;

    const modal = document.getElementById('messageModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeModal() {
    document.getElementById('messageModal').classList.add('hidden');
}

// CLOSE WHEN CLICK OUTSIDE
document.getElementById('messageModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});
</script>

</body>
</html>