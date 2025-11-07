<?php
require_once 'models/BillModel.php';
require_once 'utils/ResponseHelper.php';

class BillController {
    private $billModel;

    public function __construct() {
        $this->billModel = new BillModel();
    }

    /**
     * 添加账单
     */
    public function addBill() {
        try {
            // 获取并验证输入数据
            $input = $this->getValidatedInput();
            
            $bill_id = $this->billModel->addBill($input);
            
            if ($bill_id) {
                ResponseHelper::success(['bill_id' => $bill_id], '账单添加成功');
            } else {
                ResponseHelper::error('账单添加失败');
            }
            
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    /**
     * 获取账单列表
     */
    public function getBills() {
        try {
            $user_id = $this->getUserIdFromSession();
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = min(100, max(1, intval($_GET['limit'] ?? 20)));
            
            $filters = [
                'type' => $_GET['type'] ?? null,
                'category_id' => $_GET['category_id'] ?? null,
                'start_date' => $_GET['start_date'] ?? null,
                'end_date' => $_GET['end_date'] ?? null,
            ];

            $bills = $this->billModel->getUserBills($user_id, $page, $limit, $filters);
            
            ResponseHelper::success($bills);
            
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    /**
     * 获取统计信息
     */
    public function getStatistics() {
        try {
            $user_id = $this->getUserIdFromSession();
            $year = $_GET['year'] ?? null;
            $month = $_GET['month'] ?? null;

            $statistics = $this->billModel->getBillStatistics($user_id, $year, $month);
            
            ResponseHelper::success($statistics);
            
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage());
        }
    }

    /**
     * 数据验证
     */
    private function getValidatedInput() {
        $required_fields = ['category_id', 'amount', 'type', 'bill_date'];
        
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("字段 {$field} 不能为空");
            }
        }

        return [
            'user_id' => $this->getUserIdFromSession(),
            'category_id' => intval($_POST['category_id']),
            'amount' => floatval($_POST['amount']),
            'type' => in_array($_POST['type'], ['income', 'expense']) ? $_POST['type'] : 'expense',
            'description' => $_POST['description'] ?? '',
            'bill_date' => $_POST['bill_date']
        ];
    }

    /**
     * 从会话获取用户ID
     */
    private function getUserIdFromSession() {
        // 根据您的会话管理方式实现
        return $_SESSION['user_id'] ?? 1; // 示例
    }
}
?>