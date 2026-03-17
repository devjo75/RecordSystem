<?php
session_start();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        /*
         * Replace this with your real DB authentication, e.g.:
         *   $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
         *   $stmt->execute([$email]);
         *   $user = $stmt->fetch();
         *   if ($user && password_verify($password, $user['password_hash'])) { ... }
         */
        $demo_accounts = [
            'admin@wmsu.edu.ph'  => ['password' => 'password123', 'role' => 'admin'],
            'viewer@wmsu.edu.ph' => ['password' => 'viewer123',   'role' => 'viewer'],
        ];

        if (isset($demo_accounts[$email]) && $demo_accounts[$email]['password'] === $password) {
            session_regenerate_id(true);
            $_SESSION['user_email'] = $email;
            $_SESSION['logged_in']  = true;
            $_SESSION['user_role']  = $demo_accounts[$email]['role'];

            if ($remember) {
                setcookie('remember_email', $email, time() + (30 * 24 * 3600), '/');
            }

            header('Location: main.php');
            exit;
        } else {
            $error = 'Invalid email or password. Please try again.';
        }
    }
}

$remembered_email = $_COOKIE['remember_email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - WMSU</title>

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
                    },
                    borderRadius: {
                        'custom': '10%',
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
<body class="min-h-screen flex items-center justify-center p-4 relative">

    <!-- Background Image with Overlay -->
    <div class="absolute inset-0 z-0">
        <img src="wmsu_background.jpg" alt="Background" class="w-full h-full object-cover">
        <div class="absolute inset-0 bg-crimson-950 bg-opacity-70"></div>
    </div>

    <!-- Login Container -->
    <div class="w-full max-w-md relative z-10">

        <!-- Login Card -->
        <div class="bg-white rounded-2xl shadow-2xl overflow-hidden">

            <!-- Header Section -->
            <div class="bg-crimson-700 px-8 py-10 text-center">
                <div class="w-20 h-20 bg-white rounded-full mx-auto mb-4 flex items-center justify-center">
                    <svg class="w-10 h-10 text-crimson-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                </div>
                <h1 class="text-3xl font-bold text-white font-main">Login</h1>
                <p class="text-crimson-100 mt-2 font-secondary">or Sign up to continue</p>
            </div>

            <!-- Form Section -->
            <div class="px-8 py-10">

                <?php if (isset($_GET['reason']) && $_GET['reason'] === 'unauthenticated'): ?>
                <div class="mb-4 p-3 bg-yellow-50 border border-yellow-300 text-yellow-800 rounded-lg text-sm font-secondary flex items-center gap-2">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    You must be logged in to access that page.
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm font-secondary">
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="loginForm" class="space-y-6">

                    <!-- Email Input -->
                    <div>
                        <label for="email" class="block text-sm font-semibold text-gray-700 mb-2 font-secondary">Email Address</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            required
                            value="<?= htmlspecialchars($remembered_email ?: ($_POST['email'] ?? '')) ?>"
                            class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary"
                            placeholder="you@example.com"
                        >
                    </div>

                    <!-- Password Input -->
                    <div>
                        <label for="password" class="block text-sm font-semibold text-gray-700 mb-2 font-secondary">Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            required
                            class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary"
                            placeholder="••••••••"
                        >
                    </div>

                    <!-- Remember Me & Forgot Password -->
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <input
                                type="checkbox"
                                id="remember"
                                name="remember"
                                <?= $remembered_email ? 'checked' : '' ?>
                                class="w-4 h-4 text-crimson-700 border-gray-300 rounded focus:ring-crimson-500"
                            >
                            <label for="remember" class="ml-2 text-sm text-gray-700 font-secondary">Remember me</label>
                        </div>
                        <a href="forgot_password.php" class="text-sm font-semibold text-crimson-700 hover:text-crimson-800 transition duration-200 font-secondary">
                            Forgot password?
                        </a>
                    </div>

                    <!-- Submit Button -->
                    <button
                        type="submit"
                        id="submitBtn"
                        class="w-full bg-crimson-700 text-white font-bold py-3 px-4 rounded-lg hover:bg-crimson-800 focus:outline-none focus:ring-4 focus:ring-crimson-300 transition duration-200 transform hover:scale-[1.02] active:scale-[0.98] font-secondary"
                    >
                        Sign In
                    </button>

                </form>

                <!-- Sign Up Link -->
                <p class="mt-8 text-center text-sm text-gray-600 font-secondary">
                    Don't have an account?
                    <a href="register.php" class="font-semibold text-crimson-700 hover:text-crimson-800 transition duration-200">
                        Sign up now
                    </a>
                </p>

            </div>

        </div>

        <!-- Footer Text -->
        <p class="text-center text-white text-sm mt-6 opacity-75 font-secondary">
            © <?= date('Y') ?> WMSU. All rights reserved.
        </p>

    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email    = document.getElementById('email').value;
            const password = document.getElementById('password').value;

            if (email && password) {
                const submitBtn = document.getElementById('submitBtn');
                submitBtn.textContent = 'Signing in...';
                submitBtn.disabled = true;
            }
        });
    </script>

</body>
</html>