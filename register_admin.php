<?php
session_start();

// Redirect if already logged in
if (!empty($_SESSION['logged_in'])) {
    header('Location: dashboard/dashboard.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name        = trim($_POST['full_name']        ?? '');
    $email            = trim($_POST['email']            ?? '');
    $password         = $_POST['password']              ?? '';
    $confirm_password = $_POST['confirm_password']      ?? '';

    // Basic validation
    if (empty($full_name) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        require_once __DIR__ . '/config/db.php';

        try {
            $pdo = getPDO();

            // Check if email already exists
            $check = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $check->execute([$email]);

            if ($check->fetch()) {
                $error = 'An account with that email address already exists.';
            } else {
                $username      = explode('@', $email)[0]; // derive username from email
                $password_hash = password_hash($password, PASSWORD_BCRYPT);

                $insert = $pdo->prepare(
                    "INSERT INTO users (username, email, password_hash, full_name, role, is_active)
                     VALUES (?, ?, ?, ?, 'Staff', 1)"
                );
                $insert->execute([$username, $email, $password_hash, $full_name]);

                $success = 'Account created successfully! You can now log in.';
            }
        } catch (Exception $e) {
            $error = 'A system error occurred. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - WMSU</title>

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
                            950: '#4D0001', 900: '#800002', 800: '#AA0003',
                            700: '#D91619', 600: '#FF3336', 500: '#FF4D50',
                            400: '#FF666A', 300: '#FF8083', 200: '#FF999D',
                            100: '#FFB3B6', 50:  '#FFCCCE',
                        }
                    },
                    fontFamily: {
                        'main':      ['"Noto Nastaliq Urdu"', 'serif'],
                        'secondary': ['"IBM Plex Sans"', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    <style>
        body { font-family: 'IBM Plex Sans', sans-serif; }
        h1, h2, h3, h4, h5, h6 { font-family: 'Noto Nastaliq Urdu', serif; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 relative">

    <!-- Background -->
    <div class="absolute inset-0 z-0">
        <img src="wmsu_background.jpg" alt="Background" class="w-full h-full object-cover">
        <div class="absolute inset-0 bg-crimson-950 bg-opacity-70"></div>
    </div>

    <!-- Register Container -->
    <div class="w-full max-w-md relative z-10">
        <div class="bg-white rounded-2xl shadow-2xl overflow-hidden">

            <!-- Header -->
            <div class="bg-crimson-700 px-8 py-10 text-center">
                <div class="w-20 h-20 bg-white rounded-full mx-auto mb-4 flex items-center justify-center">
                    <svg class="w-10 h-10 text-crimson-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                    </svg>
                </div>
                <h1 class="text-3xl font-bold text-white font-main">Register</h1>
                <p class="text-crimson-100 mt-2 font-secondary">Create your WMSU account</p>
            </div>

            <!-- Form -->
            <div class="px-8 py-10">

                <?php if ($success): ?>
                <div class="mb-4 p-3 bg-green-50 border border-green-300 text-green-700 rounded-lg text-sm font-secondary flex items-center gap-2">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <?= htmlspecialchars($success) ?>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm font-secondary">
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="registerForm" class="space-y-5">

                    <div>
                        <label for="full_name" class="block text-sm font-semibold text-gray-700 mb-2 font-secondary">
                            Full Name
                        </label>
                        <input type="text" id="full_name" name="full_name" required
                            value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                            class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none
                                   focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary"
                            placeholder="Juan Dela Cruz">
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-semibold text-gray-700 mb-2 font-secondary">
                            Email Address
                        </label>
                        <input type="email" id="email" name="email" required
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                            class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none
                                   focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary"
                            placeholder="you@wmsu.edu.ph">
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-semibold text-gray-700 mb-2 font-secondary">
                            Password
                        </label>
                        <input type="password" id="password" name="password" required
                            class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none
                                   focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary"
                            placeholder="Min. 8 characters">
                        <!-- Password strength bar -->
                        <div class="mt-2 h-1.5 w-full bg-gray-200 rounded-full overflow-hidden">
                            <div id="strengthBar" class="h-full rounded-full transition-all duration-300 w-0"></div>
                        </div>
                        <p id="strengthLabel" class="mt-1 text-xs text-gray-400 font-secondary"></p>
                    </div>

                    <div>
                        <label for="confirm_password" class="block text-sm font-semibold text-gray-700 mb-2 font-secondary">
                            Confirm Password
                        </label>
                        <input type="password" id="confirm_password" name="confirm_password" required
                            class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none
                                   focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary"
                            placeholder="••••••••">
                        <p id="matchMsg" class="mt-1 text-xs font-secondary hidden"></p>
                    </div>

                    <button type="submit" id="submitBtn"
                        class="w-full bg-crimson-700 text-white font-bold py-3 px-4 rounded-lg hover:bg-crimson-800
                               focus:outline-none focus:ring-4 focus:ring-crimson-300 transition duration-200
                               transform hover:scale-[1.02] active:scale-[0.98] font-secondary mt-2">
                        Create Account
                    </button>

                </form>

                <p class="mt-8 text-center text-sm text-gray-600 font-secondary">
                    Already have an account?
                    <a href="login.php" class="font-semibold text-crimson-700 hover:text-crimson-800 transition duration-200">
                        Sign in
                    </a>
                </p>

            </div>
        </div>

        <p class="text-center text-white text-sm mt-6 opacity-75 font-secondary">
            © <?= date('Y') ?> WMSU. All rights reserved.
        </p>
    </div>

    <script>
        // Password strength meter
        const passwordInput = document.getElementById('password');
        const strengthBar   = document.getElementById('strengthBar');
        const strengthLabel = document.getElementById('strengthLabel');

        passwordInput.addEventListener('input', function () {
            const val = this.value;
            let score = 0;
            if (val.length >= 8)                      score++;
            if (/[A-Z]/.test(val))                    score++;
            if (/[0-9]/.test(val))                    score++;
            if (/[^A-Za-z0-9]/.test(val))             score++;

            const levels = [
                { width: '0%',   color: '',                      label: '' },
                { width: '25%',  color: 'bg-red-500',            label: 'Weak' },
                { width: '50%',  color: 'bg-yellow-400',         label: 'Fair' },
                { width: '75%',  color: 'bg-blue-400',           label: 'Good' },
                { width: '100%', color: 'bg-green-500',          label: 'Strong' },
            ];

            const level = val.length === 0 ? 0 : Math.max(1, score);
            strengthBar.className  = `h-full rounded-full transition-all duration-300 ${levels[level].color}`;
            strengthBar.style.width = levels[level].width;
            strengthLabel.textContent = levels[level].label;
            strengthLabel.className   = `mt-1 text-xs font-secondary ${level <= 1 ? 'text-red-500' : level === 2 ? 'text-yellow-600' : level === 3 ? 'text-blue-500' : 'text-green-600'}`;
        });

        // Confirm password match indicator
        const confirmInput = document.getElementById('confirm_password');
        const matchMsg     = document.getElementById('matchMsg');

        confirmInput.addEventListener('input', function () {
            if (this.value === '') {
                matchMsg.classList.add('hidden');
                return;
            }
            matchMsg.classList.remove('hidden');
            if (this.value === passwordInput.value) {
                matchMsg.textContent  = '✓ Passwords match';
                matchMsg.className    = 'mt-1 text-xs font-secondary text-green-600';
            } else {
                matchMsg.textContent  = '✗ Passwords do not match';
                matchMsg.className    = 'mt-1 text-xs font-secondary text-red-500';
            }
        });

        // Loading state on submit
        document.getElementById('registerForm').addEventListener('submit', function () {
            const full_name       = document.getElementById('full_name').value;
            const email           = document.getElementById('email').value;
            const password        = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (full_name && email && password && confirmPassword) {
                const btn       = document.getElementById('submitBtn');
                btn.textContent = 'Creating account…';
                btn.disabled    = true;
            }
        });

        <?php if ($success): ?>
        // Redirect to login after successful registration
        setTimeout(() => { window.location.href = 'login.php'; }, 2000);
        <?php endif; ?>
    </script>

</body>
</html>