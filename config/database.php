<?php
/**
 * Database Connection using PDO with Prepared Statements
 */

require_once __DIR__ . '/config.php';

class Database {
    private static $instance = null;
    private $conn;

    private $host;
    private $db_name;
    private $username;
    private $password;
    private $port;
    private $charset = 'utf8mb4';

    private function __construct($optional = false) {
        $this->host = getenv('DB_HOST') ?: 'localhost';
        $this->db_name = getenv('DB_NAME') ?: 'nairobi_property_db';
        $this->username = getenv('DB_USER') ?: 'root';
        $this->password = getenv('DB_PASS') ?: '';
        $this->port = getenv('DB_PORT') ?: '3306';
        $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->db_name};charset={$this->charset}";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
        } catch (\PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            if ($optional) {
                $this->conn = null;
                return;
            }
            http_response_code(503);
            die(APP_DEBUG ? 'Database unavailable. Check the local database configuration.' : 'System maintenance in progress. Please try again later.');
        }
    }

    public static function getInstance($optional = false) {
        if (self::$instance === null) {
            $instance = new Database($optional);
            if ($instance->getConnection() === null) {
                return null;
            }
            self::$instance = $instance;
        }
        return self::$instance->getConnection();
    }

    public function getConnection() {
        return $this->conn;
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}

// Helper function for quick DB access
function db($optional = false) {
    return Database::getInstance($optional);
}
