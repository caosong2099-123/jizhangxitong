<?php
require_once 'includes/header.php';
requireLogin();

$user_id = getCurrentUserId();

// 处理删除记录
if (isset($_GET['delete'])) {
    $record_id = intval($_GET['delete']);
    
    try {
        // 验证记录属于当前用户
        $stmt = $pdo->prepare("SELECT id FROM transactions WHERE id = ? AND user_id = ?");
        $stmt->execute([$record_id, $user_id]);
        
        if ($stmt->fetch()) {
            $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ?");
            if ($stmt->execute([$record_id])) {
                setMessage("记录删除成功", "success");
                header("Location: view_records.php");
                exit();
            } else {
                setMessage("删除记录失败", "error");
            }
        } else {
            setMessage("记录不存在", "error");
        }
    } catch (PDOException $e) {
        setMessage("数据库错误: " . $e->getMessage(), "error");
    }
}

// 获取筛选条件 - 设置30天默认值
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// 构建查询条件
$conditions = ["user_id = ?"];
$params = [$user_id];

if (!empty($type_filter)) {
    $conditions[] = "type = ?";
    $params[] = $type_filter;
}

if (!empty($category_filter)) {
    $conditions[] = "category = ?";
    $params[] = $category_filter;
}

if (!empty($start_date)) {
    $conditions[] = "transaction_date >= ?";
    $params[] = $start_date . ' 00:00:00';
}

if (!empty($end_date)) {
    $conditions[] = "transaction_date <= ?";
    $params[] = $end_date . ' 23:59:59';
}

$where_clause = implode(' AND ', $conditions);

try {
    // 获取记录
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE $where_clause ORDER BY transaction_date DESC, created_at DESC");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

    // 获取分类列表用于筛选
    $stmt = $pdo->prepare("SELECT DISTINCT category FROM transactions WHERE user_id = ? ORDER BY category");
    $stmt->execute([$user_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    die("数据库查询错误: " . $e->getMessage());
}
?>

<div class="card">
    <h2>记账记录</h2>
    
    <!-- 筛选表单 -->
    <form method="GET" action="" class="filter-form" id="filterForm">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
            <div class="form-group">
                <label for="type">类型</label>
                <select id="type" name="type">
                    <option value="">全部类型</option>
                    <option value="income" <?php echo $type_filter == 'income' ? 'selected' : ''; ?>>收入</option>
                    <option value="expense" <?php echo $type_filter == 'expense' ? 'selected' : ''; ?>>支出</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="category">分类</label>
                <select id="category" name="category">
                    <option value="">全部分类</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo safeOutput($cat); ?>" <?php echo $category_filter == $cat ? 'selected' : ''; ?>><?php echo safeOutput($cat); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="start_date">开始日期</label>
                <input type="date" id="start_date" name="start_date" value="<?php echo safeOutput($start_date); ?>">
            </div>
            
            <div class="form-group">
                <label for="end_date">结束日期</label>
                <input type="date" id="end_date" name="end_date" value="<?php echo safeOutput($end_date); ?>">
            </div>
        </div>
        
        <!-- 快速日期选择按钮 -->
        <div style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap;">
            <button type="button" class="btn btn-sm" onclick="setDateRange('today')">今天</button>
            <button type="button" class="btn btn-sm" onclick="setDateRange('yesterday')">昨天</button>
            <button type="button" class="btn btn-sm" onclick="setDateRange('week')">本周</button>
            <button type="button" class="btn btn-sm" onclick="setDateRange('month')">本月</button>
            <button type="button" class="btn btn-sm" onclick="setDateRange('30days')">最近30天</button>
            <button type="button" class="btn btn-sm" onclick="setDateRange('year')">一年</button>
        </div>
        
        <button type="submit">筛选</button>
        <a href="view_records.php" class="btn">重置</a>
    </form>
    
    <!-- 记录表格 -->
    <?php if (!empty($transactions)): ?>
    <table>
        <thead>
            <tr>
                <th>日期时间</th>
                <th>类型</th>
                <th>分类</th>
                <th>金额</th>
                <th>描述</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($transactions as $transaction): ?>
            <tr>
                <td><?php echo formatDateTime($transaction['transaction_date']); ?></td>
                <td><span class="<?php echo $transaction['type']; ?>"><?php echo $transaction['type'] == 'income' ? '收入' : '支出'; ?></span></td>
                <td><?php echo safeOutput($transaction['category']); ?></td>
                <td class="<?php echo $transaction['type']; ?>"><?php echo ($transaction['type'] == 'income' ? '+' : '-') . '￥' . number_format($transaction['amount'], 2); ?></td>
                <td><?php echo safeOutput($transaction['description'] ?: '-'); ?></td>
                <td>
                    <a href="view_records.php?delete=<?php echo $transaction['id']; ?>" class="btn btn-danger" onclick="return confirm('确定要删除这条记录吗？')">删除</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <p style="margin-top: 15px;">共 <?php echo count($transactions); ?> 条记录</p>
    <?php else: ?>
    <p>没有找到符合条件的记录。</p>
    <?php endif; ?>
</div>

<script>
function setDateRange(range) {
    const today = new Date();
    const startDate = document.getElementById('start_date');
    const endDate = document.getElementById('end_date');
    
    switch(range) {
        case 'today':
            startDate.value = today.toISOString().split('T')[0];
            endDate.value = today.toISOString().split('T')[0];
            break;
        case 'yesterday':
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            startDate.value = yesterday.toISOString().split('T')[0];
            endDate.value = yesterday.toISOString().split('T')[0];
            break;
        case 'week':
            const startOfWeek = new Date(today);
            startOfWeek.setDate(today.getDate() - today.getDay());
            startDate.value = startOfWeek.toISOString().split('T')[0];
            endDate.value = today.toISOString().split('T')[0];
            break;
        case 'month':
            const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
            startDate.value = startOfMonth.toISOString().split('T')[0];
            endDate.value = today.toISOString().split('T')[0];
            break;
        case '30days':
            const thirtyDaysAgo = new Date(today);
            thirtyDaysAgo.setDate(today.getDate() - 30);
            startDate.value = thirtyDaysAgo.toISOString().split('T')[0];
            endDate.value = today.toISOString().split('T')[0];
            break;
        case 'year':
            const startOfYear = new Date(today.getFullYear(), 0, 1);
            startDate.value = startOfYear.toISOString().split('T')[0];
            endDate.value = today.toISOString().split('T')[0];
            break;
    }
    
    // 自动提交表单
    document.getElementById('filterForm').submit();
}
</script>

<?php
require_once 'includes/footer.php';
?>