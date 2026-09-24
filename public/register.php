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

    if (empty($full_name) || empty($email) || empty($password)) {
        $error = 'Please fill in all required fields.';
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
            $role_id = $role['id'] ?? 3;

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->beginTransaction();

            $user_stmt = $db->prepare("INSERT INTO users (role_id, full_name, email, phone_number, password_hash, status) VALUES (?, ?, ?, ?, ?, 'Active')");
            $user_stmt->execute([$role_id, $full_name, $email, $phone, $hash]);
            $user_id = $db->lastInsertId();

            $tenant_stmt = $db->prepare("INSERT INTO tenants (user_id, full_name, phone_number, email, status) VALUES (?, ?, ?, ?, 'Active')");
            $tenant_stmt->execute([$user_id, $full_name, $phone, $email]);

            $db->commit();
            $success = 'Account created successfully! You can now log in.';
        }
    }
}

$page_title = 'Tenant Registration';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex-1 flex items-center justify-center px-4 py-12 bg-gray-50">
    <div class="max-w-md w-full bg-white rounded-xl shadow-sm p-8 border border-gray-100">
        <h2 class="text-2xl font-bold text-gray-900 mb-1">Tenant Registration</h2>
        <p class="text-sm text-gray-500 mb-6">Create your tenant portal account</p>

        <?php if (!empty($error)): ?>
            <div class="bg-red-50 text-red-700 p-3 rounded-lg text-sm mb-4"><?php echo e($error); ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="bg-green-50 text-green-800 p-3 rounded-lg text-sm mb-4"><?php echo e($success); ?></div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
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
                <input type="text" name="phone" placeholder="+2547..." class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Password</label>
                <input type="password" name="password" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary">
            </div>
            <button type="submit" class="w-full bg-primary hover:bg-blue-800 text-white font-semibold py-2.5 rounded-lg text-sm transition">
                Register Account
            </button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>