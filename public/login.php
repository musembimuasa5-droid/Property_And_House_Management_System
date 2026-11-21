<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// A signed-in user has nothing to do here; send them to their own dashboard.
if (isLoggedIn()) redirectToRoleDashboard(getCurrentUser()['role_name']);

$error = '';
<<<<<<< HEAD
$email = '';

$loginMessages = [
    'unauthorized' => 'Please sign in to continue.',
    'forbidden' => 'Your account does not have permission to access that page.',
    'role_portal_unavailable' => 'A portal is not configured for your account. Please contact an administrator.',
];
if (isset($_GET['error']) && is_string($_GET['error']) && isset($loginMessages[$_GET['error']])) {
    $error = $loginMessages[$_GET['error']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) && is_string($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';

    if (!csrf_validate()) {
        $error = 'Your session expired. Please submit the form again.';
=======
$notice = '';
if (!empty($_SESSION['session_expired'])) {
    $notice = 'You were signed out after 30 minutes without activity. Please sign in again.';
    unset($_SESSION['session_expired']);
} elseif (($_GET['error'] ?? '') === 'unauthorized') {
    $notice = 'Please sign in to continue.';
}
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) $error = 'Your session expired. Please submit the form again.';
    $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    if ($error) {
        // Do not authenticate a form with a missing or invalid CSRF token.
>>>>>>> 821dfc5081008f5deeee9e752f0a38a164e17c2d
    } elseif ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } elseif (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address and password.';
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

<div class="auth-page login-page flex-1 flex items-center justify-center px-4 py-12">
    <div class="auth-card login-card max-w-md w-full bg-white rounded-xl shadow-lg p-8 border border-gray-100">
        <div class="login-brand-mark" aria-hidden="true"><i class="fa-solid fa-house"></i></div>
        <div class="text-center login-heading">
            <p class="login-eyebrow">Your property portal</p>
            <h1>Welcome <span>back</span></h1>
            <p>Sign in to continue to <?php echo e(APP_NAME); ?>.</p>
        </div>

<<<<<<< HEAD
        <?php if ($error !== ''): ?>
            <div class="login-feedback" role="alert">
                <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                <p><?php echo e($error); ?></p>
=======
        <?php if (!empty($error)): ?>
            <div class="bg-red-50 border-l-4 border-danger p-4 mb-6 rounded-r-lg" role="alert">
                <p class="text-sm text-red-700"><?php echo e($error); ?></p>
>>>>>>> 821dfc5081008f5deeee9e752f0a38a164e17c2d
            </div>
        <?php elseif ($notice): ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-500 p-4 mb-6 rounded-r-lg" role="status">
                <p class="text-sm text-yellow-800"><?php echo e($notice); ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?php echo BASE_URL; ?>/public/login.php" class="login-form" data-login-form>
            <?php echo csrf_field(); ?>
<<<<<<< HEAD
            <div class="login-field">
                <label for="login-email">Email address</label>
                <input id="login-email" type="email" name="email" value="<?php echo e($email); ?>" maxlength="150" autocomplete="username" inputmode="email" required aria-required="true" placeholder="you@example.com">
            </div>

            <div class="login-field">
                <label for="login-password">Password</label>
                <div class="login-password-wrap">
                    <input id="login-password" type="password" name="password" autocomplete="current-password" required aria-required="true" placeholder="Enter your password">
                    <button type="button" data-password-toggle aria-controls="login-password" aria-label="Show password" aria-pressed="false" title="Show password">
                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                    </button>
                </div>
=======
            <div>
                <label for="login-email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                <input id="login-email" type="email" name="email" required autocomplete="username" value="<?php echo e($email); ?>" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition" placeholder="you@example.com">
            </div>

            <div>
                <label for="login-password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input id="login-password" type="password" name="password" required autocomplete="current-password" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition" placeholder="••••••••">
>>>>>>> 821dfc5081008f5deeee9e752f0a38a164e17c2d
            </div>

            <button type="submit" class="login-submit">
                <span>Sign in</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </button>
        </form>

<<<<<<< HEAD
        <div class="login-divider"><span>or</span></div>

        <div class="login-google-block">
            <button type="button" class="login-google-button" disabled aria-describedby="google-signin-status">
                <i class="fa-brands fa-google" aria-hidden="true"></i>
                Continue with Google
            </button>
            <p id="google-signin-status" class="login-google-status">Google sign-in is not configured for this application yet.</p>
        </div>

        <div class="login-register">
            <p>New to the property portal? <a href="<?php echo BASE_URL; ?>/public/register.php">Create a tenant account</a></p>
        </div>

        <div class="login-policy-note">
            <p>By continuing, use of the portal is subject to the applicable platform terms and privacy practices. Policy pages are being prepared and will be linked here when available.</p>
=======
        <div class="mt-6 text-center border-t border-gray-100 pt-4">
            <p class="text-xs text-gray-500">New tenant? <a class="text-primary font-semibold" href="<?php echo BASE_URL; ?>/public/register.php">Create an account</a>. Forgotten your password? Ask management to reset it.</p>
>>>>>>> 821dfc5081008f5deeee9e752f0a38a164e17c2d
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
