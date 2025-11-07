[file name]: export.php
[file content begin]
<?php
require_once 'includes/header.php';
requireLogin();

$user_id = getCurrentUserId();

// 设置默认日期范围（最近30天）
$default_end_date = date('Y-m-d');
$default_start_date = date('Y-m-d', strtotime('-30 days'));

// 辅助函数：获取表显示名称
function getTableDisplayName($table_name) {
    $names = [
        'transactions' => '交易记录',
        'friend_transactions' => '朋友借贷记录',
        'account_transactions' => '账户流水',
        'budgets' => '预算设置',
        'friends' => '朋友列表',
        'accounts' => '账户信息'
    ];
    return $names[$table_name] ?? $table_name;
}

// 辅助函数：根据账户名称获取账户ID
function getAccountIdByName($pdo, $user_id, $account_name) {
    $stmt = $pdo->prepare("SELECT id FROM accounts WHERE user_id = ? AND account_name = ?");
    $stmt->execute([$user_id, $account_name]);
    $account = $stmt->fetch();
    return $account ? $account['id'] : null;
}

// 辅助函数：根据朋友姓名获取朋友ID
function getFriendIdByName($pdo, $user_id, $friend_name) {
    $stmt = $pdo->prepare("SELECT id FROM friends WHERE user_id = ? AND name = ?");
    $stmt->execute([$user_id, $friend_name]);
    $friend = $stmt->fetch();
    return $friend ? $friend['id'] : null;
}

// 辅助函数：更新账户余额
function updateAccountBalance($pdo, $account_id, $transaction_type, $amount) {
    $account_effect = [
        'lend' => -1,      // 借出：账户减少
        'repay' => 1,      // 还款：账户增加  
        'borrow' => 1,     // 借入：账户增加
        'return' => -1     // 还钱：账户减少
    ];
    
    if (isset($account_effect[$transaction_type])) {
        $amount_change = $amount * $account_effect[$transaction_type];
        $pdo->prepare("UPDATE accounts SET balance = balance + ?, updated_at = NOW() WHERE id = ?")
            ->execute([$amount_change, $account_id]);
    }
}

// 辅助函数：记录账户流水
function recordAccountTransaction($pdo, $user_id, $account_id, $transaction_type, $amount, $related_table, $related_id, $description, $transaction_date) {
    $transaction_type_map = [
        'lend' => 'lend',
        'repay' => 'repay', 
        'borrow' => 'borrow',
        'return' => 'return'
    ];
    
    $mapped_type = $transaction_type_map[$transaction_type] ?? $transaction_type;
    
    $stmt = $pdo->prepare("
        INSERT INTO account_transactions (user_id, account_id, transaction_type, amount, related_table, related_id, description, transaction_date, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $user_id,
        $account_id,
        $mapped_type,
        $amount,
        $related_table,
        $related_id,
        $description,
        $transaction_date
    ]);
}

// 辅助函数：从章节行提取章节名称
function extractSectionName($line) {
    if (preg_match('/=== (.*) ===/', $line, $matches)) {
        return trim($matches[1]);
    }
    return '';
}

// 辅助函数：导入CSV朋友列表
function importCsvFriends($pdo, $user_id, $data, &$import_count) {
    $count = 0;
    $stmt = $pdo->prepare("INSERT IGNORE INTO friends (user_id, name, phone, note, created_at) VALUES (?, ?, ?, ?, ?)");
    
    foreach ($data as $record) {
        $stmt->execute([
            $user_id,
            $record['姓名'] ?? '',
            $record['电话'] ?? '',
            $record['备注'] ?? '',
            $record['创建时间'] ?? date('Y-m-d H:i:s')
        ]);
        if ($stmt->rowCount() > 0) {
            $count++;
        }
    }
    $import_count['friends'] = $count;
}

// 辅助函数：导入CSV朋友借贷记录
function importCsvFriendTransactions($pdo, $user_id, $data, &$import_count) {
    $count = 0;
    $stmt = $pdo->prepare("
        INSERT INTO friend_transactions (user_id, account_id, friend_id, type, amount, description, transaction_date, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($data as $record) {
        // 获取朋友ID
        $friend_id = getFriendIdByName($pdo, $user_id, $record['朋友姓名'] ?? '');
        
        // 获取账户ID
        $account_id = getAccountIdByName($pdo, $user_id, $record['账户名称'] ?? '');
        
        if ($friend_id && $account_id) {
            $stmt->execute([
                $user_id,
                $account_id,
                $friend_id,
                $record['类型'] ?? '',
                $record['金额'] ?? 0,
                $record['描述'] ?? '',
                $record['日期时间'] ?? date('Y-m-d H:i:s'),
                $record['创建时间'] ?? date('Y-m-d H:i:s')
            ]);
            $count++;
            
            // 更新账户余额
            updateAccountBalance($pdo, $account_id, $record['类型'] ?? '', $record['金额'] ?? 0);
            
            // 记录账户流水
            recordAccountTransaction($pdo, $user_id, $account_id, $record['类型'] ?? '', $record['金额'] ?? 0, 
                                   'friend_transactions', $pdo->lastInsertId(), $record['描述'] ?? '', $record['日期时间'] ?? date('Y-m-d H:i:s'));
        }
    }
    $import_count['friend_transactions'] = $count;
}

// 辅助函数：导入CSV账户信息
function importCsvAccounts($pdo, $user_id, $data, &$import_count) {
    $count = 0;
    $stmt = $pdo->prepare("INSERT IGNORE INTO accounts (user_id, account_name, account_type, balance, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)");
    
    foreach ($data as $record) {
        $stmt->execute([
            $user_id,
            $record['账户名称'] ?? '',
            $record['账户类型'] ?? '',
            $record['余额'] ?? 0,
            $record['创建时间'] ?? date('Y-m-d H:i:s'),
            $record['更新时间'] ?? date('Y-m-d H:i:s')
        ]);
        if ($stmt->rowCount() > 0) {
            $count++;
        }
    }
    $import_count['accounts'] = $count;
}

// 辅助函数：导入CSV账户流水
function importCsvAccountTransactions($pdo, $user_id, $data, &$import_count) {
    $count = 0;
    $stmt = $pdo->prepare("
        INSERT INTO account_transactions (user_id, account_id, transaction_type, amount, related_table, related_id, description, transaction_date, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($data as $record) {
        // 获取账户ID
        $account_id = getAccountIdByName($pdo, $user_id, $record['账户名称'] ?? '');
        
        if ($account_id) {
            $stmt->execute([
                $user_id,
                $account_id,
                $record['交易类型'] ?? '',
                $record['金额'] ?? 0,
                $record['关联表'] ?? '',
                $record['关联ID'] ?? 0,
                $record['描述'] ?? '',
                $record['日期时间'] ?? date('Y-m-d H:i:s'),
                $record['创建时间'] ?? date('Y-m-d H:i:s')
            ]);
            $count++;
        }
    }
    $import_count['account_transactions'] = $count;
}

// 辅助函数：导入CSV交易记录
function importCsvTransactions($pdo, $user_id, $data, &$import_count) {
    $count = 0;
    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_id, type, amount, category, transaction_date, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    foreach ($data as $record) {
        // 转换类型
        $type = ($record['类型'] == '收入') ? 'income' : 'expense';
        
        // 获取账户ID
        $account_id = getAccountIdByName($pdo, $user_id, $record['账户ID']);
        
        $stmt->execute([
            $user_id,
            $account_id,
            $type,
            $record['金额'] ?? 0,
            $record['分类'] ?? '',
            $record['日期时间'] ?? date('Y-m-d H:i:s'),
            $record['描述'] ?? '',
            $record['创建时间'] ?? date('Y-m-d H:i:s')
        ]);
        $count++;
    }
    $import_count['transactions'] = $count;
}

// 辅助函数：导入CSV预算设置
function importCsvBudgets($pdo, $user_id, $data, &$import_count) {
    $count = 0;
    $stmt = $pdo->prepare("INSERT IGNORE INTO budgets (user_id, category, amount, month_year, created_at) VALUES (?, ?, ?, ?, ?)");
    
    foreach ($data as $record) {
        $stmt->execute([
            $user_id,
            $record['分类'] ?? '',
            $record['预算金额'] ?? 0,
            $record['月份'] ?? '',
            $record['创建时间'] ?? date('Y-m-d H:i:s')
        ]);
        if ($stmt->rowCount() > 0) {
            $count++;
        }
    }
    $import_count['budgets'] = $count;
}

// 辅助函数：处理CSV章节数据
function processCsvSection($pdo, $user_id, $section, $data, &$import_count) {
    switch ($section) {
        case '朋友列表':
            importCsvFriends($pdo, $user_id, $data, $import_count);
            break;
        case '朋友借贷记录':
            importCsvFriendTransactions($pdo, $user_id, $data, $import_count);
            break;
        case '账户信息':
            importCsvAccounts($pdo, $user_id, $data, $import_count);
            break;
        case '账户流水':
            importCsvAccountTransactions($pdo, $user_id, $data, $import_count);
            break;
        case '交易记录':
            importCsvTransactions($pdo, $user_id, $data, $import_count);
            break;
        case '预算设置':
            importCsvBudgets($pdo, $user_id, $data, $import_count);
            break;
    }
}

// 辅助函数：导入CSV数据
function importCsvData($pdo, $user_id, $csv_file_path) {
    $pdo->beginTransaction();
    $import_count = [];
    
    try {
        $file_content = file_get_contents($csv_file_path);
        // 移除BOM头
        $bom = pack('H*','EFBBBF');
        $file_content = preg_replace("/^$bom/", '', $file_content);
        $lines = explode("\n", $file_content);
        
        $current_section = '';
        $headers = [];
        $section_data = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // 跳过只有分隔符的行
            if (strpos($line, '===') !== false && count(str_getcsv($line)) == 1) {
                if (!empty($current_section)) {
                    // 处理上一章节的数据
                    processCsvSection($pdo, $user_id, $current_section, $section_data, $import_count);
                }
                
                // 开始新章节
                $current_section = extractSectionName($line);
                $headers = [];
                $section_data = [];
                continue;
            }
            
            // 解析CSV行
            $row = str_getcsv($line);
            if (empty($row)) continue;
            
            if (empty($headers)) {
                $headers = $row;
            } else {
                if (count($row) === count($headers)) {
                    $section_data[] = array_combine($headers, $row);
                }
            }
        }
        
        // 处理最后一个章节
        if (!empty($current_section)) {
            processCsvSection($pdo, $user_id, $current_section, $section_data, $import_count);
        }
        
        $pdo->commit();
        
        // 生成导入统计信息
        $result_parts = [];
        foreach ($import_count as $type => $count) {
            $result_parts[] = getTableDisplayName($type) . "(" . $count . "条)";
        }
        
        return implode(", ", $result_parts);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// 辅助函数：导入JSON数据
function importJsonData($pdo, $user_id, $data) {
    $pdo->beginTransaction();
    $import_count = [];
    
    try {
        // 导入账户信息（先导入账户，因为其他表依赖账户ID）
        if (!empty($data['accounts'])) {
            $count = 0;
            $stmt = $pdo->prepare("INSERT IGNORE INTO accounts (user_id, account_name, account_type, balance, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)");
            
            foreach ($data['accounts'] as $record) {
                $stmt->execute([
                    $user_id,
                    $record['账户名称'],
                    $record['账户类型'],
                    $record['余额'],
                    $record['创建时间'] ?? date('Y-m-d H:i:s'),
                    $record['更新时间'] ?? date('Y-m-d H:i:s')
                ]);
                if ($stmt->rowCount() > 0) {
                    $count++;
                }
            }
            $import_count['accounts'] = $count;
        }
        
        // 导入朋友列表
        if (!empty($data['friends'])) {
            $count = 0;
            $stmt = $pdo->prepare("INSERT IGNORE INTO friends (user_id, name, phone, note, created_at) VALUES (?, ?, ?, ?, ?)");
            
            foreach ($data['friends'] as $record) {
                $stmt->execute([
                    $user_id,
                    $record['姓名'],
                    $record['电话'],
                    $record['备注'],
                    $record['创建时间'] ?? date('Y-m-d H:i:s')
                ]);
                if ($stmt->rowCount() > 0) {
                    $count++;
                }
            }
            $import_count['friends'] = $count;
        }
        
        // 导入交易记录
        if (!empty($data['transactions'])) {
            $count = 0;
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_id, type, amount, category, transaction_date, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($data['transactions'] as $record) {
                // 转换类型
                $type = $record['类型'] == '收入' ? 'income' : 'expense';
                
                // 获取账户ID
                $account_id = getAccountIdByName($pdo, $user_id, $record['账户ID']);
                
                $stmt->execute([
                    $user_id,
                    $account_id,
                    $type,
                    $record['金额'],
                    $record['分类'],
                    $record['日期时间'],
                    $record['描述'],
                    $record['创建时间'] ?? date('Y-m-d H:i:s')
                ]);
                $count++;
            }
            $import_count['transactions'] = $count;
        }
        
        // 导入朋友借贷记录（需要在朋友和账户之后导入）
        if (!empty($data['friend_transactions'])) {
            $count = 0;
            $stmt = $pdo->prepare("
                INSERT INTO friend_transactions (user_id, account_id, friend_id, type, amount, description, transaction_date, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($data['friend_transactions'] as $record) {
                // 获取朋友ID
                $friend_id = getFriendIdByName($pdo, $user_id, $record['朋友姓名']);
                
                // 获取账户ID
                $account_id = getAccountIdByName($pdo, $user_id, $record['账户名称']);
                
                if ($friend_id && $account_id) {
                    $stmt->execute([
                        $user_id,
                        $account_id,
                        $friend_id,
                        $record['类型'],
                        $record['金额'],
                        $record['描述'],
                        $record['日期时间'],
                        $record['创建时间'] ?? date('Y-m-d H:i:s')
                    ]);
                    $count++;
                    
                    // 更新账户余额
                    updateAccountBalance($pdo, $account_id, $record['类型'], $record['金额']);
                    
                    // 记录账户流水
                    recordAccountTransaction($pdo, $user_id, $account_id, $record['类型'], $record['金额'], 
                                           'friend_transactions', $pdo->lastInsertId(), $record['描述'], $record['日期时间']);
                }
            }
            $import_count['friend_transactions'] = $count;
        }
        
        // 导入预算
        if (!empty($data['budgets'])) {
            $count = 0;
            $stmt = $pdo->prepare("INSERT IGNORE INTO budgets (user_id, category, amount, month_year, created_at) VALUES (?, ?, ?, ?, ?)");
            
            foreach ($data['budgets'] as $record) {
                $stmt->execute([
                    $user_id,
                    $record['分类'],
                    $record['预算金额'],
                    $record['月份'],
                    $record['创建时间'] ?? date('Y-m-d H:i:s')
                ]);
                if ($stmt->rowCount() > 0) {
                    $count++;
                }
            }
            $import_count['budgets'] = $count;
        }
        
        // 导入账户流水（需要在所有交易之后导入）
        if (!empty($data['account_transactions'])) {
            $count = 0;
            $stmt = $pdo->prepare("
                INSERT INTO account_transactions (user_id, account_id, transaction_type, amount, related_table, related_id, description, transaction_date, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($data['account_transactions'] as $record) {
                // 获取账户ID
                $account_id = getAccountIdByName($pdo, $user_id, $record['账户名称']);
                
                if ($account_id) {
                    $stmt->execute([
                        $user_id,
                        $account_id,
                        $record['交易类型'],
                        $record['金额'],
                        $record['关联表'],
                        $record['关联ID'],
                        $record['描述'],
                        $record['日期时间'],
                        $record['创建时间'] ?? date('Y-m-d H:i:s')
                    ]);
                    $count++;
                }
            }
            $import_count['account_transactions'] = $count;
        }
        
        $pdo->commit();
        
        // 生成导入统计信息
        $result_parts = [];
        foreach ($import_count as $type => $count) {
            $result_parts[] = getTableDisplayName($type) . "(" . $count . "条)";
        }
        
        return implode(", ", $result_parts);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// 处理导出请求
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['export'])) {
    $export_type = $_POST['export_type'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $data_type = $_POST['data_type'];
    
    // 验证日期
    if (!isValidDate($start_date) || !isValidDate($end_date)) {
        $_SESSION['message'] = "请输入有效的日期范围";
        $_SESSION['message_type'] = "error";
        header("Location: export.php");
        exit();
    }
    
    // 准备导出数据
    $export_data = [];
    
    // 导出交易记录
    if ($data_type == 'all' || $data_type == 'transactions') {
        $stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ? AND transaction_date BETWEEN ? AND ? ORDER BY transaction_date");
        $stmt->execute([$user_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($transactions as $transaction) {
            $export_data['transactions'][] = [
                '日期时间' => formatDateTimeDetailed($transaction['transaction_date']),
                '类型' => $transaction['type'] == 'income' ? '收入' : '支出',
                '分类' => $transaction['category'],
                '金额' => $transaction['amount'],
                '账户ID' => $transaction['account_id'],
                '描述' => $transaction['description'] ?: '',
                '创建时间' => formatDateTimeDetailed($transaction['created_at'])
            ];
        }
    }
    
    // 导出朋友借贷记录
    if ($data_type == 'all' || $data_type == 'friend_transactions') {
        $stmt = $pdo->prepare("
            SELECT ft.*, f.name as friend_name, a.account_name 
            FROM friend_transactions ft 
            JOIN friends f ON ft.friend_id = f.id 
            JOIN accounts a ON ft.account_id = a.id
            WHERE ft.user_id = ? AND ft.transaction_date BETWEEN ? AND ? 
            ORDER BY ft.transaction_date
        ");
        $stmt->execute([$user_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
        $friend_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($friend_transactions as $transaction) {
            $export_data['friend_transactions'][] = [
                '日期时间' => formatDateTimeDetailed($transaction['transaction_date']),
                '朋友姓名' => $transaction['friend_name'],
                '类型' => $transaction['type'],
                '金额' => $transaction['amount'],
                '账户名称' => $transaction['account_name'],
                '描述' => $transaction['description'] ?: '',
                '创建时间' => formatDateTimeDetailed($transaction['created_at'])
            ];
        }
    }
    
    // 导出账户流水
    if ($data_type == 'all' || $data_type == 'account_transactions') {
        $stmt = $pdo->prepare("
            SELECT at.*, a.account_name 
            FROM account_transactions at 
            JOIN accounts a ON at.account_id = a.id 
            WHERE at.user_id = ? AND at.transaction_date BETWEEN ? AND ? 
            ORDER BY at.transaction_date
        ");
        $stmt->execute([$user_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
        $account_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($account_transactions as $transaction) {
            $export_data['account_transactions'][] = [
                '日期时间' => formatDateTimeDetailed($transaction['transaction_date']),
                '账户名称' => $transaction['account_name'],
                '交易类型' => $transaction['transaction_type'],
                '金额' => $transaction['amount'],
                '关联表' => $transaction['related_table'],
                '关联ID' => $transaction['related_id'],
                '描述' => $transaction['description'] ?: '',
                '创建时间' => formatDateTimeDetailed($transaction['created_at'])
            ];
        }
    }
    
    // 导出预算数据
    if ($data_type == 'all' || $data_type == 'budgets') {
        $stmt = $pdo->prepare("SELECT * FROM budgets WHERE user_id = ? ORDER BY month_year, category");
        $stmt->execute([$user_id]);
        $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($budgets as $budget) {
            $export_data['budgets'][] = [
                '分类' => $budget['category'],
                '预算金额' => $budget['amount'],
                '月份' => $budget['month_year'],
                '创建时间' => formatDateTimeDetailed($budget['created_at'])
            ];
        }
    }
    
    // 导出朋友列表
    if ($data_type == 'all' || $data_type == 'friends') {
        $stmt = $pdo->prepare("SELECT * FROM friends WHERE user_id = ? ORDER BY name");
        $stmt->execute([$user_id]);
        $friends = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($friends as $friend) {
            $export_data['friends'][] = [
                '姓名' => $friend['name'],
                '电话' => $friend['phone'] ?: '',
                '备注' => $friend['note'] ?: '',
                '创建时间' => formatDateTimeDetailed($friend['created_at'])
            ];
        }
    }
    
    // 导出账户信息
    if ($data_type == 'all' || $data_type == 'accounts') {
        $stmt = $pdo->prepare("SELECT * FROM accounts WHERE user_id = ? ORDER BY account_type");
        $stmt->execute([$user_id]);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($accounts as $account) {
            $export_data['accounts'][] = [
                '账户名称' => $account['account_name'],
                '账户类型' => $account['account_type'],
                '余额' => $account['balance'],
                '创建时间' => formatDateTimeDetailed($account['created_at']),
                '更新时间' => formatDateTimeDetailed($account['updated_at'])
            ];
        }
    }
    
    // 检查是否有数据可导出
    $has_data = false;
    foreach ($export_data as $data_type => $records) {
        if (!empty($records)) {
            $has_data = true;
            break;
        }
    }
    
    if (!$has_data) {
        $_SESSION['message'] = "指定日期范围内没有数据可导出";
        $_SESSION['message_type'] = "warning";
        header("Location: export.php");
        exit();
    }
    
    // 导出为CSV
    if ($export_type == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="个人记账数据_' . $start_date . '_至_' . $end_date . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // 添加BOM以正确处理中文
        fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));
        
        // 写入各个数据表的数据
        foreach ($export_data as $table_name => $records) {
            if (!empty($records)) {
                // 写入表名作为分隔符
                fputcsv($output, ["=== " . getTableDisplayName($table_name) . " ==="]);
                
                // 写入表头
                fputcsv($output, array_keys($records[0]));
                
                // 写入数据
                foreach ($records as $row) {
                    fputcsv($output, $row);
                }
                
                // 添加空行分隔
                fputcsv($output, []);
                fputcsv($output, []);
            }
        }
        
        fclose($output);
        exit();
    }
    
    // 导出为JSON
    if ($export_type == 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="个人记账数据_' . $start_date . '_至_' . $end_date . '.json"');
        
        // 添加元数据
        $full_export_data = [
            'export_info' => [
                'export_time' => date('Y-m-d H:i:s'),
                'date_range' => $start_date . ' 至 ' . $end_date,
                'data_types' => $data_type == 'all' ? '全部数据' : $data_type,
                'user_id' => $user_id
            ],
            'data' => $export_data
        ];
        
        echo json_encode($full_export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit();
    }
}

// 处理导入请求
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['import'])) {
    $import_file = $_FILES['import_file'];
    
    // 检查文件上传是否成功
    if ($import_file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['message'] = "文件上传失败，错误代码: " . $import_file['error'];
        $_SESSION['message_type'] = "error";
        header("Location: export.php");
        exit();
    }
    
    // 检查文件类型
    $file_ext = strtolower(pathinfo($import_file['name'], PATHINFO_EXTENSION));
    if ($file_ext !== 'json' && $file_ext !== 'csv') {
        $_SESSION['message'] = "请上传JSON或CSV格式的备份文件";
        $_SESSION['message_type'] = "error";
        header("Location: export.php");
        exit();
    }
    
    try {
        if ($file_ext == 'json') {
            // 处理JSON导入
            $file_content = file_get_contents($import_file['tmp_name']);
            $import_data = json_decode($file_content, true);
            
            if (!$import_data || !isset($import_data['data'])) {
                throw new Exception("JSON文件格式错误或数据不完整");
            }
            
            $import_result = importJsonData($pdo, $user_id, $import_data['data']);
            
        } else {
            // 处理CSV导入
            $import_result = importCsvData($pdo, $user_id, $import_file['tmp_name']);
        }
        
        $_SESSION['message'] = "数据导入成功！导入统计: " . $import_result;
        $_SESSION['message_type'] = "success";
        
    } catch (Exception $e) {
        $_SESSION['message'] = "数据导入失败: " . $e->getMessage();
        $_SESSION['message_type'] = "error";
    }
    
    header("Location: export.php");
    exit();
}
?>

<div class="card">
    <h2>数据导出与导入</h2>
    
    <div class="export-import-tabs">
        <div class="tab-buttons">
            <button class="tab-button active" data-tab="export">数据导出</button>
            <button class="tab-button" data-tab="import">数据导入</button>
        </div>
        
        <!-- 导出标签页 -->
        <div id="export" class="tab-content active">
            <p>导出您的个人记账数据为CSV或JSON格式，方便进行离线分析、存档或迁移。</p>
            
            <form method="POST" action="" id="exportForm">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label for="export_type">导出格式</label>
                        <select id="export_type" name="export_type" required>
                            <option value="csv">CSV (Excel可打开)</option>
                            <option value="json">JSON (完整数据)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="data_type">数据类型</label>
                        <select id="data_type" name="data_type" required>
                            <option value="all">全部数据</option>
                            <option value="transactions">交易记录</option>
                            <option value="friend_transactions">朋友借贷</option>
                            <option value="account_transactions">账户流水</option>
                            <option value="budgets">预算设置</option>
                            <option value="friends">朋友列表</option>
                            <option value="accounts">账户信息</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="start_date">开始日期</label>
                        <input type="date" id="start_date" name="start_date" required value="<?php echo $default_start_date; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="end_date">结束日期</label>
                        <input type="date" id="end_date" name="end_date" required value="<?php echo $default_end_date; ?>">
                    </div>
                </div>
                
                <!-- 快速日期选择按钮 -->
                <div class="quick-date-buttons" style="margin-bottom: 20px;">
                    <button type="button" class="btn btn-sm" onclick="setDateRange('today')">今天</button>
                    <button type="button" class="btn btn-sm" onclick="setDateRange('yesterday')">昨天</button>
                    <button type="button" class="btn btn-sm" onclick="setDateRange('week')">本周</button>
                    <button type="button" class="btn btn-sm" onclick="setDateRange('month')">本月</button>
                    <button type="button" class="btn btn-sm" onclick="setDateRange('30days')">最近30天</button>
                    <button type="button" class="btn btn-sm" onclick="setDateRange('90days')">最近90天</button>
                    <button type="button" class="btn btn-sm" onclick="setDateRange('year')">今年</button>
                    <button type="button" class="btn btn-sm" onclick="setDateRange('last_year')">去年</button>
                    <button type="button" class="btn btn-sm" onclick="setDateRange('all')">全部数据</button>
                </div>
                
                <button type="submit" name="export" class="btn-success">导出数据</button>
            </form>
            
            <div class="export-info">
                <h3>导出说明</h3>
                <ul>
                    <li><strong>CSV格式</strong>：适用于Excel、Numbers等电子表格软件，包含清晰的表格结构。</li>
                    <li><strong>JSON格式</strong>：适用于程序处理和数据迁移，包含完整的元数据信息。</li>
                    <li><strong>全部数据</strong>：导出所有类型的数据，包括交易记录、借贷记录、账户流水等。</li>
                    <li><strong>按日期筛选</strong>：可以导出指定日期范围内的数据，方便按时间段分析。</li>
                    <li>导出的数据仅包含您个人的记录，不包含其他用户的任何信息。</li>
                    <li>建议定期导出数据作为备份，以防数据丢失。</li>
                </ul>
            </div>
        </div>
        
        <!-- 导入标签页 -->
        <div id="import" class="tab-content">
            <p>从备份文件恢复您的数据。支持JSON和CSV格式的导入。</p>
            
            <div class="alert alert-warning">
                <strong>注意：</strong> 导入数据会添加新的数据记录，不会删除现有数据。如果导入的数据与现有数据冲突，可能会创建重复记录。
            </div>
            
            <form method="POST" action="" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="import_file">选择备份文件</label>
                    <input type="file" id="import_file" name="import_file" accept=".json,.csv" required>
                    <small>支持JSON和CSV格式（JSON格式包含完整元数据，CSV格式为基础数据）</small>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="confirm_import" required>
                        我确认了解导入操作会添加新数据，并已做好数据备份
                    </label>
                </div>
                
                <button type="submit" name="import" class="btn-warning">导入数据</button>
            </form>
            
            <div class="import-info">
                <h3>导入说明</h3>
                <ul>
                    <li><strong>JSON格式</strong>：支持完整的元数据和所有数据类型的导入。</li>
                    <li><strong>CSV格式</strong>：支持基础数据导入，包含朋友列表、借贷记录、账户信息等。</li>
                    <li>导入过程中会保持数据之间的关系（如朋友借贷记录与朋友列表的关联）。</li>
                    <li>如果导入的数据与现有数据重复，系统会尝试避免创建完全相同的记录。</li>
                    <li>导入完成后会显示详细的导入统计信息。</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<style>
.export-import-tabs {
    margin-top: 20px;
}

.tab-buttons {
    display: flex;
    border-bottom: 1px solid #ddd;
    margin-bottom: 20px;
}

.tab-button {
    padding: 10px 20px;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-size: 16px;
}

.tab-button.active {
    border-bottom-color: #4b6cb7;
    color: #4b6cb7;
    font-weight: bold;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.quick-date-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.export-info, .import-info {
    margin-top: 30px;
    padding: 20px;
    background-color: #f8f9fa;
    border-radius: 5px;
}

.export-info h3, .import-info h3 {
    margin-top: 0;
}

.export-info ul, .import-info ul {
    padding-left: 20px;
}

.export-info li, .import-info li {
    margin-bottom: 10px;
}

.btn-sm {
    padding: 5px 10px;
    font-size: 14px;
}

@media (max-width: 768px) {
    .tab-buttons {
        flex-direction: column;
    }
    
    .tab-button {
        text-align: left;
        border-bottom: 1px solid #ddd;
        border-left: 3px solid transparent;
    }
    
    .tab-button.active {
        border-left-color: #4b6cb7;
        border-bottom-color: #ddd;
    }
    
    .quick-date-buttons {
        flex-direction: column;
    }
    
    .quick-date-buttons .btn-sm {
        text-align: center;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 标签页切换功能
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            const tabId = this.getAttribute('data-tab');
            
            // 移除所有active类
            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));
            
            // 添加active类到当前标签
            this.classList.add('active');
            document.getElementById(tabId).classList.add('active');
        });
    });
});

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
        case '90days':
            const ninetyDaysAgo = new Date(today);
            ninetyDaysAgo.setDate(today.getDate() - 90);
            startDate.value = ninetyDaysAgo.toISOString().split('T')[0];
            endDate.value = today.toISOString().split('T')[0];
            break;
        case 'year':
            const startOfYear = new Date(today.getFullYear(), 0, 1);
            startDate.value = startOfYear.toISOString().split('T')[0];
            endDate.value = today.toISOString().split('T')[0];
            break;
        case 'last_year':
            const startOfLastYear = new Date(today.getFullYear() - 1, 0, 1);
            const endOfLastYear = new Date(today.getFullYear() - 1, 11, 31);
            startDate.value = startOfLastYear.toISOString().split('T')[0];
            endDate.value = endOfLastYear.toISOString().split('T')[0];
            break;
        case 'all':
            // 设置一个很早的日期作为开始日期
            startDate.value = '2000-01-01';
            endDate.value = today.toISOString().split('T')[0];
            break;
    }
    
    // 不自动提交，让用户确认后手动提交
}
</script>

<?php
require_once 'includes/footer.php';
?>
[file content end]