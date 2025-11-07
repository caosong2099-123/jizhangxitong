<?php
require_once 'includes/header.php';
requireLogin();

$user_id = getCurrentUserId();

// 创建备份目录
$backup_dir = 'backups';
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

// 处理备份请求
if (isset($_POST['backup'])) {
    // 获取用户数据
    $user_data = [
        'transactions' => [],
        'budgets' => [],
        'friends' => [],
        'friend_transactions' => [],
        'accounts' => [],
        'account_transactions' => []
    ];
    
    // 交易记录
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user_data['transactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 预算
    $stmt = $pdo->prepare("SELECT * FROM budgets WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user_data['budgets'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 朋友数据
    $stmt = $pdo->prepare("SELECT * FROM friends WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user_data['friends'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 朋友借贷记录
    $stmt = $pdo->prepare("SELECT ft.* FROM friend_transactions ft 
                          JOIN friends f ON ft.friend_id = f.id 
                          WHERE f.user_id = ?");
    $stmt->execute([$user_id]);
    $user_data['friend_transactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 账户数据
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user_data['accounts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 账户流水记录
    $stmt = $pdo->prepare("SELECT at.* FROM account_transactions at WHERE at.user_id = ?");
    $stmt->execute([$user_id]);
    $user_data['account_transactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 生成备份文件名
    $backup_file = $backup_dir . '/backup_' . $_SESSION['username'] . '_' . date('Y-m-d_H-i-s') . '.json';
    
    // 保存备份文件
    if (file_put_contents($backup_file, json_encode($user_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        $_SESSION['message'] = "数据备份成功，文件已保存为: " . basename($backup_file);
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "数据备份失败";
        $_SESSION['message_type'] = "error";
    }
    
    header("Location: backup.php");
    exit();
}

// 处理恢复请求
if (isset($_POST['restore']) && isset($_FILES['backup_file'])) {
    $backup_file = $_FILES['backup_file'];
    
    // 检查文件上传是否成功
    if ($backup_file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['message'] = "文件上传失败";
        $_SESSION['message_type'] = "error";
        header("Location: backup.php");
        exit();
    }
    
    // 检查文件类型
    $file_ext = strtolower(pathinfo($backup_file['name'], PATHINFO_EXTENSION));
    if ($file_ext !== 'json') {
        $_SESSION['message'] = "请上传JSON格式的备份文件";
        $_SESSION['message_type'] = "error";
        header("Location: backup.php");
        exit();
    }
    
    // 读取备份文件
    $backup_content = file_get_contents($backup_file['tmp_name']);
    $user_data = json_decode($backup_content, true);
    
    if (!$user_data) {
        $_SESSION['message'] = "备份文件格式错误";
        $_SESSION['message_type'] = "error";
        header("Location: backup.php");
        exit();
    }
    
    // 开始事务
    $pdo->beginTransaction();
    
    try {
        // 清空现有数据
        $pdo->prepare("DELETE FROM transactions WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM budgets WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM friend_transactions WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM friends WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM account_transactions WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM accounts WHERE user_id = ?")->execute([$user_id]);
        
        // 恢复账户数据
        if (!empty($user_data['accounts'])) {
            $stmt = $pdo->prepare("INSERT INTO accounts (user_id, account_name, account_type, balance, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($user_data['accounts'] as $account) {
                $stmt->execute([
                    $user_id,
                    $account['account_name'],
                    $account['account_type'],
                    $account['balance'],
                    $account['created_at'] ?? date('Y-m-d H:i:s'),
                    $account['updated_at'] ?? date('Y-m-d H:i:s')
                ]);
            }
        }
        
        // 恢复朋友数据
        if (!empty($user_data['friends'])) {
            $stmt = $pdo->prepare("INSERT INTO friends (user_id, name, phone, note, created_at) VALUES (?, ?, ?, ?, ?)");
            foreach ($user_data['friends'] as $friend) {
                $stmt->execute([
                    $user_id,
                    $friend['name'],
                    $friend['phone'],
                    $friend['note'],
                    $friend['created_at'] ?? date('Y-m-d H:i:s')
                ]);
            }
        }
        
        // 恢复交易记录
        if (!empty($user_data['transactions'])) {
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_id, type, amount, category, transaction_date, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($user_data['transactions'] as $transaction) {
                $stmt->execute([
                    $user_id,
                    $transaction['account_id'] ?? null,
                    $transaction['type'],
                    $transaction['amount'],
                    $transaction['category'],
                    $transaction['transaction_date'],
                    $transaction['description'],
                    $transaction['created_at'] ?? date('Y-m-d H:i:s')
                ]);
            }
        }
        
        // 恢复朋友借贷记录
        if (!empty($user_data['friend_transactions'])) {
            $stmt = $pdo->prepare("INSERT INTO friend_transactions (user_id, account_id, friend_id, type, amount, description, transaction_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($user_data['friend_transactions'] as $transaction) {
                $stmt->execute([
                    $user_id,
                    $transaction['account_id'] ?? null,
                    $transaction['friend_id'],
                    $transaction['type'],
                    $transaction['amount'],
                    $transaction['description'],
                    $transaction['transaction_date'],
                    $transaction['created_at'] ?? date('Y-m-d H:i:s')
                ]);
            }
        }
        
        // 恢复预算
        if (!empty($user_data['budgets'])) {
            $stmt = $pdo->prepare("INSERT INTO budgets (user_id, category, amount, month_year, created_at) VALUES (?, ?, ?, ?, ?)");
            foreach ($user_data['budgets'] as $budget) {
                $stmt->execute([
                    $user_id,
                    $budget['category'],
                    $budget['amount'],
                    $budget['month_year'],
                    $budget['created_at'] ?? date('Y-m-d H:i:s')
                ]);
            }
        }
        
        // 恢复账户流水记录
        if (!empty($user_data['account_transactions'])) {
            $stmt = $pdo->prepare("INSERT INTO account_transactions (user_id, account_id, transaction_type, amount, related_table, related_id, description, transaction_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($user_data['account_transactions'] as $transaction) {
                $stmt->execute([
                    $user_id,
                    $transaction['account_id'],
                    $transaction['transaction_type'],
                    $transaction['amount'],
                    $transaction['related_table'],
                    $transaction['related_id'],
                    $transaction['description'],
                    $transaction['transaction_date'],
                    $transaction['created_at'] ?? date('Y-m-d H:i:s')
                ]);
            }
        }
        
        // 提交事务
        $pdo->commit();
        
        $_SESSION['message'] = "数据恢复成功";
        $_SESSION['message_type'] = "success";
    } catch (Exception $e) {
        // 回滚事务
        $pdo->rollBack();
        $_SESSION['message'] = "数据恢复失败: " . $e->getMessage();
        $_SESSION['message_type'] = "error";
    }
    
    header("Location: backup.php");
    exit();
}

// 获取备份文件列表
$backup_files = [];
if (is_dir($backup_dir)) {
    $files = scandir($backup_dir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'json') {
            // 检查是否属于当前用户
            if (strpos($file, '_' . $_SESSION['username'] . '_') !== false) {
                $backup_files[] = [
                    'name' => $file,
                    'path' => $backup_dir . '/' . $file,
                    'size' => filesize($backup_dir . '/' . $file),
                    'time' => filemtime($backup_dir . '/' . $file)
                ];
            }
        }
    }
    
    // 按时间倒序排列
    usort($backup_files, function($a, $b) {
        return $b['time'] - $a['time'];
    });
}
?>

<div class="card">
    <h2>数据备份与恢复</h2>
    
    <div class="backup-actions">
        <div class="backup-section">
            <h3>创建备份</h3>
            <p>将您的所有数据（交易记录、预算设置、借贷记录、账户信息）备份到本地文件。</p>
            <form method="POST" action="">
                <button type="submit" name="backup" class="btn-success">立即备份</button>
            </form>
        </div>
        
        <div class="backup-section">
            <h3>恢复数据</h3>
            <p>从备份文件恢复您的数据。注意：这将覆盖当前的所有数据！</p>
            <form method="POST" action="" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="backup_file">选择备份文件</label>
                    <input type="file" id="backup_file" name="backup_file" accept=".json" required>
                </div>
                <button type="submit" name="restore" class="btn-warning" onclick="return confirm('警告：这将覆盖您当前的所有数据！确定要继续吗？')">恢复数据</button>
            </form>
        </div>
    </div>
    
    <div class="backup-list">
        <h3>备份文件列表</h3>
        <?php if (!empty($backup_files)): ?>
        <table>
            <thead>
                <tr>
                    <th>文件名</th>
                    <th>大小</th>
                    <th>备份时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backup_files as $file): ?>
                <tr>
                    <td><?php echo htmlspecialchars($file['name']); ?></td>
                    <td><?php echo round($file['size'] / 1024, 2); ?> KB</td>
                    <td><?php echo date('Y-m-d H:i:s', $file['time']); ?></td>
                    <td>
                        <a href="<?php echo $file['path']; ?>" download class="btn btn-sm">下载</a>
                        <a href="backup.php?delete=<?php echo urlencode($file['name']); ?>" class="btn btn-danger btn-sm" onclick="return confirm('确定要删除这个备份文件吗？')">删除</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p>暂无备份文件。</p>
        <?php endif; ?>
    </div>
</div>

<style>
.backup-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 30px;
    margin-bottom: 30px;
}

.backup-section {
    padding: 20px;
    border: 1px solid #ddd;
    border-radius: 5px;
}

.backup-section h3 {
    margin-top: 0;
}

@media (max-width: 768px) {
    .backup-actions {
        grid-template-columns: 1fr;
    }
}
</style>

<?php
// 处理备份文件删除
if (isset($_GET['delete'])) {
    $file_name = $_GET['delete'];
    $file_path = $backup_dir . '/' . $file_name;
    
    // 验证文件属于当前用户
    if (strpos($file_name, '_' . $_SESSION['username'] . '_') !== false && file_exists($file_path)) {
        if (unlink($file_path)) {
            $_SESSION['message'] = "备份文件删除成功";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = "文件删除失败";
            $_SESSION['message_type'] = "error";
        }
    } else {
        $_SESSION['message'] = "文件不存在或无权删除";
        $_SESSION['message_type'] = "error";
    }
    
    header("Location: backup.php");
    exit();
}

require_once 'includes/footer.php';
?>