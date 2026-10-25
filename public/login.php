<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$error = '';
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
    } elseif ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } elseif (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address and password.';
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

            redirectToRoleDashboard($user['role_name']);

        } else {
            $error = 'Invalid email address or password.';
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

        <?php if ($error !== ''): ?>
            <div class="login-feedback" role="alert">
                <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                <p><?php echo e($error); ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?php echo BASE_URL; ?>/public/login.php" class="login-form" data-login-form>
            <?php echo csrf_field(); ?>
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
            </div>

            <button type="submit" class="login-submit">
                <span>Sign in</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </button>
        </form>

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
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
