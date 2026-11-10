<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// A signed-in user has nothing to do here; send them to their own dashboard.
if (isLoggedIn()) redirectToRoleDashboard(getCurrentUser()['role_name']);

$error = '';
$notice = '';
if (!empty($_SESSION['session_expired'])) {
    $notice = 'You were signed out after 30 minutes without activity. Please sign in again.';
    unset($_SESSION['session_expired']);
}
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) $error = 'Your session expired. Please submit the form again.';
    $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    if ($error) {
        // Do not authenticate a form with a missing or invalid CSRF token.
    } elseif ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } else {
        $db = db();
        if (login_is_throttled($db, $email, $ip)) {
            $error = 'Too many failed sign-in attempts. Please wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again.';
            logAuditAction('LOGIN_BLOCKED', 'auth', null, 'Sign-in refused after repeated failures for ' . $email);
        } else {
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
                $_SESSION['last_activity'] = time();

                $update = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $update->execute([$user['id']]);
                login_clear_failures($db, $email);
                logAuditAction('LOGIN', 'auth', (int)$user['id'], 'Signed in');

                redirectToRoleDashboard($user['role_name']);
            } else {
                login_record_failure($db, $email, $ip);
                logAuditAction('LOGIN_FAILED', 'auth', null, 'Failed sign-in for ' . $email);
                // One message for an unknown email and a wrong password, so accounts cannot be discovered.
                $error = 'Invalid email address or password.';
            }
        }
    }
}

$page_title = 'Login';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<div class="auth-page flex-1 flex items-center justify-center px-4 py-12">
    <div class="auth-card max-w-md w-full bg-white rounded-xl shadow-lg p-8 border border-gray-100">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-blue-100 text-primary mb-3">
                <i class="fa-solid fa-lock text-xl"></i>
            </div>
            <h2 class="text-2xl font-bold text-gray-900">Welcome Back</h2>
            <p class="text-sm text-gray-500 mt-1">Sign in to <?php echo APP_NAME; ?></p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="bg-red-50 border-l-4 border-danger p-4 mb-6 rounded-r-lg" role="alert">
                <p class="text-sm text-red-700"><?php echo e($error); ?></p>
            </div>
        <?php elseif ($notice): ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-500 p-4 mb-6 rounded-r-lg" role="status">
                <p class="text-sm text-yellow-800"><?php echo e($notice); ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="space-y-5">
            <?php echo csrf_field(); ?>
            <div>
                <label for="login-email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                <input id="login-email" type="email" name="email" required autocomplete="username" value="<?php echo e($email); ?>" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition" placeholder="you@example.com">
            </div>

            <div>
                <label for="login-password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input id="login-password" type="password" name="password" required autocomplete="current-password" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition" placeholder="••••••••">
            </div>

            <button type="submit" class="w-full bg-primary hover:bg-blue-800 text-white font-semibold py-2.5 rounded-lg transition shadow-md">
                Sign In
            </button>
        </form>

        <div class="mt-6 text-center border-t border-gray-100 pt-4">
            <p class="text-xs text-gray-500">New tenant? <a class="text-primary font-semibold" href="<?php echo BASE_URL; ?>/public/register.php">Create an account</a>. Forgotten your password? Ask management to reset it.</p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
