<?php
class UserModel {
    private $db;
    private $table = 'users';

    public function __construct() {
        $database = Database::getInstance();
        $this->db = $database->getConnection();
    }

    /**
     * 用户注册（安全增强）
     */
    public function register($username, $email, $password) {
        // 验证邮箱是否已存在
        if ($this->emailExists($email)) {
            throw new Exception("邮箱已被注册");
        }

        // 密码强度验证
        if (!$this->validatePassword($password)) {
            throw new Exception("密码必须包含字母和数字，且长度不少于6位");
        }

        $query = "INSERT INTO " . $this->table . " 
                 (username, email, password, created_at, status) 
                 VALUES (:username, :email, :password, NOW(), 1)";

        $stmt = $this->db->prepare($query);

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $stmt->bindParam(":username", $username);
        $stmt->bindParam(":email", $email);
        $stmt->bindParam(":password", $hashed_password);

        try {
            if ($stmt->execute()) {
                return $this->db->lastInsertId();
            }
            return false;
        } catch (PDOException $e) {
            error_log("User registration error: " . $e->getMessage());
            throw new Exception("注册失败，请稍后重试");
        }
    }

    /**
     * 用户登录（安全增强）
     */
    public function login($email, $password) {
        $query = "SELECT id, username, email, password, status 
                 FROM " . $this->table . " 
                 WHERE email = :email AND status = 1 
                 LIMIT 1";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':email', $email);
        
        try {
            $stmt->execute();
            
            if ($stmt->rowCount() == 1) {
                $user = $stmt->fetch();
                
                if (password_verify($password, $user['password'])) {
                    // 更新最后登录时间
                    $this->updateLastLogin($user['id']);
                    
                    unset($user['password']);
                    return $user;
                }
            }
            
            return false;
            
        } catch (PDOException $e) {
            error_log("User login error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 邮箱是否存在
     */
    private function emailExists($email) {
        $query = "SELECT id FROM " . $this->table . " WHERE email = :email LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /**
     * 密码强度验证
     */
    private function validatePassword($password) {
        if (strlen($password) < 6) {
            return false;
        }
        
        // 必须包含字母和数字
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return false;
        }
        
        return true;
    }

    /**
     * 更新最后登录时间
     */
    private function updateLastLogin($user_id) {
        $query = "UPDATE " . $this->table . " SET last_login = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $user_id);
        $stmt->execute();
    }

    /**
     * 获取用户信息（缓存友好）
     */
    public function getUserProfile($user_id) {
        $query = "SELECT id, username, email, created_at, last_login 
                 FROM " . $this->table . " 
                 WHERE id = :id AND status = 1 
                 LIMIT 1";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
        
        try {
            $stmt->execute();
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Get user profile error: " . $e->getMessage());
            return false;
        }
    }
}
?>