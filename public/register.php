<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
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

            if (!$error) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->beginTransaction();

            $user_stmt = $db->prepare("INSERT INTO users (role_id, full_name, email, phone, password_hash, status) VALUES (?, ?, ?, ?, ?, 'Active')");
            $user_stmt->execute([$role_id, $full_name, $email, $phone, $hash]);
            $user_id = $db->lastInsertId();

            $tenant_stmt = $db->prepare("INSERT INTO tenants (user_id, full_name, phone, email, national_id, gender, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
            $tenant_stmt->execute([$user_id, $full_name, $phone, $email, $national_id, $gender]);

            $db->commit();
            $success = 'Account created successfully! You can now log in.';
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
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Full Name</label>
                <input type="text" name="full_name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Email Address</label>
                <input type="email" name="email" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Phone Number</label>
                <input type="tel" name="phone" required placeholder="+2547..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary">
            </div>
            <div><label class="block text-xs font-semibold text-gray-500 uppercase mb-1">National ID</label><input type="text" name="national_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></div>
            <div><label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Gender</label><select name="gender" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"><option value="">Select</option><option>Female</option><option>Male</option><option>Other</option></select></div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Password</label>
                <input type="password" name="password" minlength="10" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary">
            </div>
            <button type="submit" class="w-full bg-primary hover:bg-blue-800 text-white font-semibold py-2.5 rounded-lg text-sm transition">
                Register Account
            </button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
