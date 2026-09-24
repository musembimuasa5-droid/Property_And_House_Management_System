<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        $db = db();
        $stmt = $db->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.email = ? AND u.status = 'Active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Regenerate session ID to prevent session fixation attacks
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role_id'] = $user['role_id'];
            $_SESSION['role_name'] = $user['role_name'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];

            // Update last login timestamp
            $update = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $update->execute([$user['id']]);

            // Redirect based on role
            if ($user['role_name'] === 'Tenant') {
                redirect('tenant/dashboard.php');
            } else {
                redirect('admin/dashboard.php');
            }
        } else {
            $error = 'Invalid email address or password.';
        }
    }
}

$page_title = 'Login';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex-1 flex items-center justify-center px-4 py-12">
    <div class="max-w-md w-full bg-white rounded-xl shadow-lg p-8 border border-gray-100">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-blue-100 text-primary mb-3">
                <i class="fa-solid fa-lock text-xl"></i>
            </div>
            <h2 class="text-2xl font-bold text-gray-900">Welcome Back</h2>
            <p class="text-sm text-gray-500 mt-1">Sign in to <?php echo APP_NAME; ?></p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="bg-red-50 border-l-4 border-danger p-4 mb-6 rounded-r-lg">
                <p class="text-sm text-red-700"><?php echo e($error); ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="space-y-5">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                <input type="email" name="email" required class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition" placeholder="admin@nairobiproperty.co.ke">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="password" required class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition" placeholder="••••••••">
            </div>

            <button type="submit" class="w-full bg-primary hover:bg-blue-800 text-white font-semibold py-2.5 rounded-lg transition shadow-md">
                Sign In
            </button>
        </form>

        <div class="mt-6 text-center border-t border-gray-100 pt-4">
            <p class="text-xs text-gray-500">Default Developer Accounts in README / Seed Data</p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>