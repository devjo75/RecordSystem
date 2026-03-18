<?php
session_start();

require_once 'auth-guard/Auth.php';

// Uncomment to protect this page:
// if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
//     header('Location: login.php');
//     exit;
// }

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $document_type = $_POST['documentType'] ?? '';

    if (empty($document_type)) {
        $error = 'Please select a document type.';
    } elseif (empty($_FILES['fileUpload']['name'][0])) {
        $error = 'Please upload at least one document.';
    } else {
        // Process uploaded files here
        // move_uploaded_file($_FILES['fileUpload']['tmp_name'][0], 'uploads/' . basename($_FILES['fileUpload']['name'][0]));
        $success = 'Document received successfully! It has been added to the pending queue.';
    }
}

// Function to get initials from email
function getInitialsFromEmail($email) {
    // If email is empty, return default
    if (empty($email)) {
        return 'U';
    }
    
    // Split email by @ and take the first part
    $namePart = explode('@', $email)[0];
    
    // Split by dots, underscores, or hyphens
    $parts = preg_split('/[._-]/', $namePart);
    
    $initials = '';
    
    // Take first letter of each part (up to 2 letters)
    foreach ($parts as $part) {
        if (!empty($part)) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
        // Limit to 2 initials
        if (strlen($initials) >= 2) {
            break;
        }
    }
    
    // If we still don't have initials, use first letter of email
    if (empty($initials) && !empty($email)) {
        $initials = strtoupper(substr($email, 0, 1));
    }
    
    return $initials ?: 'U'; // Default to 'U' if all else fails
}

// Get user email from session
$user_email = $_SESSION['user_email'] ?? '';
$user_initials = getInitialsFromEmail($user_email);

// Get user role from session (or default to 'User')
$user_role = $_SESSION['user_role'] ?? 'user';
$user_role_display = ucfirst($user_role);
?>

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - WMSU Document Management</title>

    <!-- Google Fonts -->
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
                            950: '#4D0001',
                            900: '#800002',
                            800: '#AA0003',
                            700: '#D91619',
                            600: '#FF3336',
                            500: '#FF4D50',
                            400: '#FF666A',
                            300: '#FF8083',
                            200: '#FF999D',
                            100: '#FFB3B6',
                            50: '#FFCCCE',
                        }
                    },
                    fontFamily: {
                        'main': ['"Noto Nastaliq Urdu"', 'serif'],
                        'secondary': ['"IBM Plex Sans"', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        body {
            font-family: 'IBM Plex Sans', sans-serif;
        }
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Noto Nastaliq Urdu', serif;
        }
    </style>
</head>
<body class="bg-gray-100">

    <?php include 'sidebar/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="lg:ml-64 min-h-screen">

        <!-- Top Bar -->
        <header class="bg-white shadow-sm fixed top-0 right-0 left-0 lg:left-64 z-20">
            <div class="px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="ml-12 lg:ml-0">
                        <h2 class="text-2xl font-bold text-gray-800 font-main">Receiving Department</h2>
                        <p class="text-sm text-gray-600 mt-1 font-secondary">Process and verify incoming documents</p>
                    </div>

                    <!-- User Profile -->
                    <div class="flex items-center space-x-4">
                        <button class="relative p-2 text-gray-600 hover:text-crimson-700 transition duration-200">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                            </svg>
                            <span class="absolute top-1 right-1 w-2 h-2 bg-crimson-600 rounded-full"></span>
                        </button>
                        
                            <div class="flex items-center space-x-3">
                                <div class="hidden sm:block text-right">
                                     <p class="text-sm font-semibold text-gray-800 font-secondary">
                                       <?= htmlspecialchars($user_email ?: 'Guest User') ?>
                                    </p>
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
        <div class="px-4 sm:px-6 lg:px-8 py-8 pt-24">

            <div class="grid grid-cols-1">

                <!-- Document Form -->
                <div>
                    <div class="bg-white rounded-2xl shadow p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-xl font-bold text-gray-800 font-main">Receive New Document</h3>
                            <span class="px-3 py-1 bg-crimson-100 text-crimson-700 rounded-lg text-sm font-semibold font-secondary">
                                New Entry
                            </span>
                        </div>

                        <?php if ($success): ?>
                        <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm font-secondary">
                            <?= htmlspecialchars($success) ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                        <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm font-secondary">
                            <?= htmlspecialchars($error) ?>
                        </div>
                        <?php endif; ?>

                        <form id="receiveForm" method="POST" action="" enctype="multipart/form-data" class="space-y-6">

                            <!-- Document Type -->
                            <div>
                                <label for="documentType" class="block text-sm font-semibold text-gray-700 mb-2 font-secondary">
                                    Document Type <span class="text-crimson-600">*</span>
                                </label>
                                <select
                                    id="documentType"
                                    name="documentType"
                                    required
                                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary"
                                >
                                    <option value="">Select document type...</option>
                                    <option value="form"       <?= ($_POST['documentType'] ?? '') === 'form'       ? 'selected' : '' ?>>Form</option>
                                    <option value="transcript" <?= ($_POST['documentType'] ?? '') === 'transcript' ? 'selected' : '' ?>>Transcript</option>
                                    <option value="certificate"<?= ($_POST['documentType'] ?? '') === 'certificate'? 'selected' : '' ?>>Certificate</option>
                                    <option value="diploma"    <?= ($_POST['documentType'] ?? '') === 'diploma'    ? 'selected' : '' ?>>Diploma</option>
                                    <option value="clearance"  <?= ($_POST['documentType'] ?? '') === 'clearance'  ? 'selected' : '' ?>>Clearance</option>
                                    <option value="other"      <?= ($_POST['documentType'] ?? '') === 'other'      ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>

                            <!-- File Upload -->
                            <div>
                                <label for="fileUpload" class="block text-sm font-semibold text-gray-700 mb-2 font-secondary">
                                    Upload Document <span class="text-crimson-600">*</span>
                                </label>
                                <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-crimson-700 transition duration-200">
                                    <input
                                        type="file"
                                        id="fileUpload"
                                        name="fileUpload[]"
                                        multiple
                                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                        class="hidden"
                                    >
                                    <label for="fileUpload" class="cursor-pointer">
                                        <svg class="w-12 h-12 mx-auto text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                                        </svg>
                                        <p class="text-sm text-gray-600 mb-1 font-secondary">
                                            <span class="text-crimson-700 font-semibold">Click to upload</span> or drag and drop
                                        </p>
                                        <p class="text-xs text-gray-500 font-secondary">PDF, DOC, DOCX, JPG, PNG (Max 10MB)</p>
                                    </label>
                                </div>
                                <div id="fileList" class="mt-3 space-y-2"></div>
                            </div>

                            <!-- Buttons -->
                            <div class="flex flex-col sm:flex-row gap-3">
                                <button
                                    type="submit"
                                    class="flex-1 bg-crimson-700 text-white font-bold py-3 px-6 rounded-lg hover:bg-crimson-800 focus:outline-none focus:ring-4 focus:ring-crimson-300 transition duration-200 transform hover:scale-[1.02] active:scale-[0.98] font-secondary"
                                >
                                    Submit Document
                                </button>
                                <button
                                    type="button"
                                    id="saveAsDraft"
                                    class="flex-1 bg-gray-200 text-gray-700 font-bold py-3 px-6 rounded-lg hover:bg-gray-300 focus:outline-none focus:ring-4 focus:ring-gray-300 transition duration-200 font-secondary"
                                >
                                    Save as Draft
                                </button>
                            </div>

                        </form>
                    </div>
                </div>

            </div>

        </div>
    </main>

    <script>

        // File upload handling
        const fileUpload = document.getElementById('fileUpload');
        const fileList   = document.getElementById('fileList');
        let uploadedFiles = [];

        fileUpload.addEventListener('change', (e) => {
            const files = Array.from(e.target.files);
            uploadedFiles = [...uploadedFiles, ...files];
            displayFiles();
        });

        function displayFiles() {
            fileList.innerHTML = '';
            uploadedFiles.forEach((file, index) => {
                const fileItem = document.createElement('div');
                fileItem.className = 'flex items-center justify-between bg-gray-50 p-3 rounded-lg';
                fileItem.innerHTML = `
                    <div class="flex items-center space-x-3">
                        <svg class="w-5 h-5 text-crimson-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <div>
                            <p class="text-sm font-semibold text-gray-800">${file.name}</p>
                            <p class="text-xs text-gray-500">${(file.size / 1024).toFixed(2)} KB</p>
                        </div>
                    </div>
                    <button type="button" onclick="removeFile(${index})" class="text-red-600 hover:text-red-800 transition duration-200">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                `;
                fileList.appendChild(fileItem);
            });
        }

        function removeFile(index) {
            uploadedFiles.splice(index, 1);
            displayFiles();
        }

        // Save as draft
        document.getElementById('saveAsDraft').addEventListener('click', () => {
            alert('Document saved as draft! You can continue editing it later.');
        });
    </script>

</body>
</html>