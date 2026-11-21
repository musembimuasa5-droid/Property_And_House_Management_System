<?php
/**
 * Create a Super Admin account from the command line (first login on a new installation).
 *
 *   php tools/create_admin.php --name="Jane Doe" --email=admin@example.com --phone=+254700000000
 *
 * The password is read from the terminal (hidden on Linux and macOS) or from the
 * ADMIN_PASSWORD environment variable, never from the command line, so it does not end up
 * in the shell history.
 */

if (PHP_SAPI !== 'cli') exit("Run this script from the command line.\n");

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

$options = getopt('', ['name:', 'email:', 'phone:']);
$name = trim($options['name'] ?? '');
$email = trim($options['email'] ?? '');
$phone = trim($options['phone'] ?? '');

function fail(string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function read_password(string $prompt): string {
    $fromEnv = getenv('ADMIN_PASSWORD');
    if ($fromEnv !== false && $fromEnv !== '') return $fromEnv;
    fwrite(STDOUT, $prompt);
    $hide = DIRECTORY_SEPARATOR === '/' && posix_isatty_safe();
    if ($hide) shell_exec('stty -echo');
    $value = rtrim((string)fgets(STDIN), "\r\n");
    if ($hide) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return $value;
}

function posix_isatty_safe(): bool {
    return function_exists('posix_isatty') ? posix_isatty(STDIN) : true;
}

if ($name === '' || mb_strlen($name) > 100) fail('Give --name (up to 100 characters).');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Give a valid --email.');
if (!preg_match('/^\+?[0-9][0-9 ()-]{6,19}$/', $phone)) fail('Give a valid --phone, for example +254712345678.');

$password = read_password('Password (at least 10 characters): ');
if (mb_strlen($password) < 10) fail('The password must be at least 10 characters.');
if (getenv('ADMIN_PASSWORD') === false && read_password('Repeat the password: ') !== $password) fail('The passwords do not match.');

$db = db();
$role = (int)$db->query("SELECT id FROM roles WHERE name = 'Super Admin'")->fetchColumn();
if (!$role) fail('The Super Admin role is missing. Import database/reference_data.sql first.');
$exists = $db->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
$exists->execute([$email]);
if ((int)$exists->fetchColumn() > 0) fail('An account with that email already exists.');

$db->prepare("INSERT INTO users (role_id, full_name, email, phone, password_hash, status) VALUES (?, ?, ?, ?, ?, 'Active')")
    ->execute([$role, $name, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);
$id = (int)$db->lastInsertId();
logAuditAction('CREATE', 'users', $id, 'Super Admin created from the command line', null, ['full_name' => $name, 'email' => $email, 'role_id' => $role], null);
echo "Super Admin #$id created for $email. Sign in at " . BASE_URL . "/public/login.php\n";
