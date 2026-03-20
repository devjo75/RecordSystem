<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Release Monitoring</title>

<script src="https://cdn.tailwindcss.com"></script>

</head>
<body class="bg-gray-100">

<div class="flex">

    <!-- SIDEBAR -->
    <?php include __DIR__ . '/../sidebar/sidebar.php'; ?>

    <!-- MAIN -->
    <div class="flex-1 p-6 lg:ml-64">

        <!-- HEADER -->
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Release Monitoring</h1>
            <p class="text-gray-500 text-sm">
                Track acknowledgement status per released document
            </p>
        </div>

        <!-- CONTAINER -->
        <div class="bg-white rounded-2xl shadow p-6">

            <!-- TOP BAR -->
            <div class="flex justify-end items-center mb-6 gap-3">
                <input type="text"
                    placeholder="Search Document"
                    class="border rounded-lg px-4 py-2 w-72 focus:outline-none focus:ring-2 focus:ring-red-500">

                <button class="p-2 rounded hover:bg-gray-100">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-600"
                        fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 4h18M6 10h12M10 16h4"/>
                    </svg>
                </button>
            </div>

            <!-- CARD 1 -->
            <div onclick="openReleaseModal(
                'request_travel.png',
                'Jun 12, 2025',
                'Requesting travel with 12 student on May 13 2025',
                ['MARK CRUZ','FERNANDO'],
                ['M','F']
            )"
            class="border rounded-xl p-5 hover:shadow-md transition cursor-pointer">

                <div class="flex justify-between items-start">

                    <div>
                        <h2 class="font-semibold text-gray-800 text-lg">
                            Request Travel
                        </h2>
                        <p class="text-sm text-gray-500 mt-1">
                            Release by Mark • Jun 12, 2025
                        </p>

                        <div class="flex items-center mt-4 -space-x-2">
                            <div class="w-9 h-9 bg-blue-600 text-white flex items-center justify-center rounded-full border-2 border-white font-bold">
                                M
                            </div>
                            <div class="w-9 h-9 bg-green-600 text-white flex items-center justify-center rounded-full border-2 border-white font-bold">
                                F
                            </div>
                        </div>
                    </div>

                    <span class="text-sm font-semibold text-gray-700">
                        Partial
                    </span>

                </div>
            </div>

            <!-- CARD 2 -->
            <div onclick="openReleaseModal(
                'memo.pdf',
                'May 3, 2025',
                'This is a memorandum for all staff.',
                ['FERNANDO'],
                ['F']
            )"
            class="border rounded-xl p-5 hover:shadow-md transition cursor-pointer mt-4">

                <div class="flex justify-between items-start">

                    <div>
                        <h2 class="font-semibold text-gray-800 text-lg">
                            Memorandum Update
                        </h2>
                        <p class="text-sm text-gray-500 mt-1">
                            Release by Fernando • May 3, 2025
                        </p>

                        <div class="flex items-center mt-4 -space-x-2">
                            <div class="w-9 h-9 bg-green-600 text-white flex items-center justify-center rounded-full border-2 border-white font-bold">
                                F
                            </div>
                        </div>
                    </div>

                    <span class="text-sm font-semibold text-green-600">
                        Completed
                    </span>

                </div>
            </div>

        </div>

    </div>

</div>

<!-- MODAL -->
<div id="releaseModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">

    <div class="bg-white w-full max-w-2xl rounded-xl shadow-lg p-6 relative">

        <!-- CLOSE -->
        <button onclick="closeReleaseModal()" 
            class="absolute top-4 right-4 text-gray-500 hover:text-black text-xl">
            ✕
        </button>

        <!-- FILE -->
        <p class="text-sm text-gray-700 mb-1">
            <strong>DOCUMENT FILE:</strong> 
            <span id="releaseFile"></span>
        </p>

        <!-- DATE -->
        <p class="text-sm text-gray-500 mb-4">
            Received: <span id="releaseDate"></span>
        </p>

        <!-- MESSAGE -->
        <div class="mb-5">
            <label class="text-sm text-gray-600">Message</label>
            <div id="releaseMessage"
                class="border rounded-lg p-3 mt-1 bg-gray-50 text-gray-700">
            </div>
        </div>

        <!-- ACKNOWLEDGED -->
        <div>
            <p class="text-sm font-semibold text-gray-700 mb-2">
                Acknowledged by:
            </p>

            <div id="acknowledgedList" class="flex items-center gap-4 flex-wrap">
                <!-- USERS WILL LOAD HERE -->
            </div>
        </div>

    </div>

</div>

<!-- SCRIPT -->
<script>
function openReleaseModal(file, date, message, names, initials) {

    document.getElementById('releaseFile').innerText = file;
    document.getElementById('releaseDate').innerText = date;
    document.getElementById('releaseMessage').innerText = message;

    const container = document.getElementById('acknowledgedList');
    container.innerHTML = '';

    for (let i = 0; i < names.length; i++) {
        const user = `
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 bg-blue-600 text-white flex items-center justify-center rounded-full font-bold">
                    ${initials[i]}
                </div>
                <span class="text-sm text-gray-700 font-medium">
                    ${names[i]}
                </span>
            </div>
        `;
        container.innerHTML += user;
    }

    const modal = document.getElementById('releaseModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeReleaseModal() {
    document.getElementById('releaseModal').classList.add('hidden');
}

// CLICK OUTSIDE CLOSE
document.getElementById('releaseModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeReleaseModal();
    }
});
</script>

</body>
</html>