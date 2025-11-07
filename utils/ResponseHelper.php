<?php
class ResponseHelper {
    
    /**
     * 成功响应
     */
    public static function success($data = null, $message = '操作成功') {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 错误响应
     */
    public static function error($message = '操作失败', $code = 400, $details = null) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'details' => $details,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 验证错误响应
     */
    public static function validationError($errors) {
        self::error('数据验证失败', 422, $errors);
    }

    /**
     * 分页数据响应
     */
    public static function pagination($data, $total, $page, $limit) {
        self::success([
            'list' => $data,
            'pagination' => [
                'total' => (int)$total,
                'page' => (int)$page,
                'limit' => (int)$limit,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }
}
?>