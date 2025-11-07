<?php
class Database {
    private $host = 'localhost';
    private $db_name = 'jizhangxitong';
    private $username = 'your_username';
    private $password = 'your_password';
    private $charset = 'utf8mb4';
    public $conn;

    // 数据库连接单例
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        $this->conn = null;
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
            $this->conn = new PDO($dsn, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            
            // 设置连接超时
            $this->conn->exec("SET SESSION wait_timeout=300");
            
        } catch(PDOException $exception) {
            error_log("Connection error: " . $exception->getMessage());
            throw new Exception("Database connection failed");
        }
        return $this->conn;
    }

    // 防止克隆
    private function __clone() {}
}
?>