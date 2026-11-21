<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $national_id = trim($_POST['national_id'] ?? '');
    $gender = $_POST['gender'] ?? '';

    if (!csrf_validate()) {
        $error = 'Your session expired. Please submit the form again.';
    } elseif (empty($full_name) || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($phone) || empty($national_id) || !in_array($gender, ['Male', 'Female', 'Other'], true) || strlen($password) < 10) {
        $error = 'Complete all fields with a valid email and a password of at least 10 characters.';
    } elseif (mb_strlen($full_name) > 100 || mb_strlen($email) > 150 || !preg_match('/^\+?[0-9][0-9 ()-]{6,19}$/', $phone) || mb_strlen($national_id) > 50) {
        $error = 'Check your details: enter a valid phone number such as +254712345678, and keep names and IDs short.';
    } elseif (($_POST['privacy_consent'] ?? '') !== '1') {
        $error = 'Please read the privacy notice and tick the box to agree before creating your account.';
    } else {
        $db = db();
        // Check if email exists
        $check = $db->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = 'Email address is already registered.';
        } else {
            // Get tenant role id
            $role_stmt = $db->prepare("SELECT id FROM roles WHERE name = 'Tenant'");
            $role_stmt->execute();
            $role = $role_stmt->fetch();
            if (!$role) { $error = 'Tenant registration is temporarily unavailable.'; }
            $role_id = $role['id'] ?? 0;

            // A profile staff already created is linked when the national ID and email both match.
            $existing_stmt = $db->prepare('SELECT id, user_id, email FROM tenants WHERE national_id = ?');
            $existing_stmt->execute([$national_id]);
            $existing = $existing_stmt->fetch();
            if (!$error && $existing && ($existing['user_id'] || strcasecmp($existing['email'], $email) !== 0)) {
                $error = 'This national ID is already registered. Contact management if you need help signing in.';
            }

            if (!$error) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            try {
                $db->beginTransaction();

                $user_stmt = $db->prepare("INSERT INTO users (role_id, full_name, email, phone, password_hash, status) VALUES (?, ?, ?, ?, ?, 'Active')");
                $user_stmt->execute([$role_id, $full_name, $email, $phone, $hash]);
                $user_id = $db->lastInsertId();

                if ($existing) {
                    $db->prepare('UPDATE tenants SET user_id = ? WHERE id = ? AND user_id IS NULL')->execute([$user_id, $existing['id']]);
                } else {
                    $tenant_stmt = $db->prepare("INSERT INTO tenants (user_id, full_name, phone, email, national_id, gender, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
                    $tenant_stmt->execute([$user_id, $full_name, $phone, $email, $national_id, $gender]);
                }

                $db->commit();
                logAuditAction('REGISTER', 'users', (int)$user_id, 'Tenant self-registration' . ($existing ? ' linked to an existing tenant profile' : '') . '; privacy notice accepted', null, null, (int)$user_id);
                $full_name = $email = $phone = $national_id = $gender = '';
                $success = $existing ?'Account created and linked to your tenancy. You can now log in.' : 'Account created successfully! You can now log in.';
            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('Tenant registration failed: ' . $e->getMessage());
                $error = 'Registration could not be completed. Check your details or contact management.';
            }
            }
        }
    }
}

$page_title = 'Tenant Registration';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<div class="auth-page flex-1 flex items-center justify-center px-4 py-12 bg-gray-50">
    <div class="auth-card max-w-md w-full bg-white rounded-xl shadow-sm p-8 border border-gray-100">
        <h2 class="text-2xl font-bold text-gray-900 mb-1">Tenant Registration</h2>
        <p class="text-sm text-gray-500 mb-6">Create your tenant portal account</p>

        <?php if (!empty($error)): ?>
            <div class="bg-red-50 text-red-700 p-3 rounded-lg text-sm mb-4"><?php echo e($error); ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="bg-green-50 text-green-800 p-3 rounded-lg text-sm mb-4"><?php echo e($success); ?></div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <?php echo csrf_field(); ?>
            <div>
                <label for="reg-name" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Full Name</label>
                <input id="reg-name" type="text" name="full_name" required maxlength="100" autocomplete="name" value="<?php echo e($full_name ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary">
            </div>
            <div>
                <label for="reg-email" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Email Address</label>
                <input id="reg-email" type="email" name="email" required maxlength="150" autocomplete="email" value="<?php echo e($email ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary">
            </div>
            <div>
                <label for="reg-phone" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Phone Number</label>
                <input id="reg-phone" type="tel" name="phone" required maxlength="20" autocomplete="tel" placeholder="+2547..." value="<?php echo e($phone ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary">
            </div>
            <div><label for="reg-national-id" class="block text-xs font-semibold text-gray-500 uppercase mb-1">National ID</label><input id="reg-national-id" type="text" name="national_id" required maxlength="50" value="<?php echo e($national_id ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div>
            <div><label for="reg-gender" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Gender</label><select id="reg-gender" name="gender" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><option value="">Select</option><?php foreach (['Female', 'Male', 'Other'] as $option): ?><option <?php echo ($gender ?? '') === $option ? 'selected' : ''; ?>><?php echo $option; ?></option><?php endforeach; ?></select></div>
            <div>
                <label for="reg-password" class="block text-xs font-semibold text-gray-500 uppercase mb-1">Password</label>
                <input id="reg-password" type="password" name="password" minlength="10" required autocomplete="new-password" aria-describedby="reg-password-help" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary">
                <p id="reg-password-help" class="text-xs text-gray-500 mt-1">At least 10 characters.</p>
            </div>
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-xs text-gray-600">
                We use these details only to manage your tenancy: your lease, rent records, receipts, repair requests and notices. Only management staff and you can see them. Read the <a class="text-primary font-semibold" href="<?php echo BASE_URL; ?>/public/privacy.php">privacy notice</a> for how your data is kept and your rights under the Data Protection Act, 2019.
                <label for="reg-consent" class="flex items-start gap-2 mt-2 text-sm text-gray-800"><input id="reg-consent" type="checkbox" name="privacy_consent" value="1" required class="mt-1"> <span>I have read the privacy notice and agree that my details may be used for managing my tenancy.</span></label>
            </div>
            <button type="submit" class="w-full bg-primary hover:bg-blue-800 text-white font-semibold py-2.5 rounded-lg text-sm transition">
                Register Account
            </button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
