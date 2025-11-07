<?php
class BillModel {
    private $db;
    private $table = 'bills';

    public function __construct() {
        $database = Database::getInstance();
        $this->db = $database->getConnection();
    }

    /**
     * 添加账单（防SQL注入）
     */
    public function addBill($data) {
        $query = "INSERT INTO " . $this->table . " 
                 (user_id, category_id, amount, type, description, bill_date, created_at) 
                 VALUES (:user_id, :category_id, :amount, :type, :description, :bill_date, NOW())";
        
        $stmt = $this->db->prepare($query);
        
        // 过滤和验证数据
        $filtered_data = [
            ':user_id' => (int)$data['user_id'],
            ':category_id' => (int)$data['category_id'],
            ':amount' => floatval($data['amount']),
            ':type' => in_array($data['type'], ['income', 'expense']) ? $data['type'] : 'expense',
            ':description' => htmlspecialchars(strip_tags($data['description'])),
            ':bill_date' => $this->validateDate($data['bill_date']) ? $data['bill_date'] : date('Y-m-d')
        ];

        try {
            if ($stmt->execute($filtered_data)) {
                return $this->db->lastInsertId();
            }
            return false;
        } catch (PDOException $e) {
            error_log("Add bill error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取用户账单（分页优化）
     */
    public function getUserBills($user_id, $page = 1, $limit = 20, $filters = []) {
        $offset = ($page - 1) * $limit;
        
        $where_conditions = ["b.user_id = :user_id", "b.status = 1"];
        $params = [':user_id' => (int)$user_id];

        // 动态过滤条件
        if (!empty($filters['type'])) {
            $where_conditions[] = "b.type = :type";
            $params[':type'] = $filters['type'];
        }
        
        if (!empty($filters['category_id'])) {
            $where_conditions[] = "b.category_id = :category_id";
            $params[':category_id'] = (int)$filters['category_id'];
        }
        
        if (!empty($filters['start_date'])) {
            $where_conditions[] = "b.bill_date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $where_conditions[] = "b.bill_date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }

        $where_sql = implode(" AND ", $where_conditions);

        $query = "SELECT b.*, c.name as category_name 
                 FROM " . $this->table . " b 
                 LEFT JOIN categories c ON b.category_id = c.id 
                 WHERE " . $where_sql . " 
                 ORDER BY b.bill_date DESC, b.id DESC 
                 LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($query);
        
        // 绑定参数
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Get user bills error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取账单统计（性能优化）
     */
    public function getBillStatistics($user_id, $year = null, $month = null) {
        $year = $year ?: date('Y');
        $month = $month ?: date('m');
        
        $query = "SELECT 
                    type,
                    COUNT(*) as count,
                    SUM(amount) as total_amount,
                    AVG(amount) as avg_amount
                 FROM " . $this->table . " 
                 WHERE user_id = :user_id 
                 AND YEAR(bill_date) = :year 
                 AND MONTH(bill_date) = :month 
                 AND status = 1
                 GROUP BY type";

        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':user_id', (int)$user_id, PDO::PARAM_INT);
        $stmt->bindValue(':year', $year, PDO::PARAM_INT);
        $stmt->bindValue(':month', $month, PDO::PARAM_INT);

        try {
            $stmt->execute();
            $result = $stmt->fetchAll();
            
            $statistics = [
                'income' => ['count' => 0, 'total' => 0, 'average' => 0],
                'expense' => ['count' => 0, 'total' => 0, 'average' => 0]
            ];
            
            foreach ($result as $row) {
                $type = $row['type'];
                $statistics[$type] = [
                    'count' => (int)$row['count'],
                    'total' => floatval($row['total_amount']),
                    'average' => floatval($row['avg_amount'])
                ];
            }
            
            return $statistics;
            
        } catch (PDOException $e) {
            error_log("Get statistics error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 日期验证
     */
    private function validateDate($date, $format = 'Y-m-d') {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }

    /**
     * 批量操作支持
     */
    public function batchAddBills($user_id, $bills) {
        $this->db->beginTransaction();
        
        try {
            $success_count = 0;
            foreach ($bills as $bill) {
                $bill['user_id'] = $user_id;
                if ($this->addBill($bill)) {
                    $success_count++;
                }
            }
            
            $this->db->commit();
            return $success_count;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Batch add bills error: " . $e->getMessage());
            return false;
        }
    }
}
?>