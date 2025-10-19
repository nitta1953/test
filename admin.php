<?php
require_once 'config.php';

// ログインチェック
if (!isset($_SESSION['admin_logged_in'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        $admin_id = $_POST['admin_id'] ?? '';
        $admin_password = $_POST['admin_password'] ?? '';
        
        if ($admin_id === ADMIN_ID && $admin_password === ADMIN_PASSWORD) {
            $_SESSION['admin_logged_in'] = true;
            header('Location: admin.php');
            exit;
        } else {
            $login_error = 'IDまたはパスワードが正しくありません。';
        }
    }
    
    // ログインフォーム表示
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>管理者ログイン - 大葉信用組合</title>
        <link rel="stylesheet" href="https://unpkg.com/98.css">
        <style>
            body { padding: 20px; background: teal; }
            .window { max-width: 400px; margin: 100px auto; }
            .window-body { padding: 20px; }
            .field-row { margin: 15px 0; }
            .field-row label { display: block; margin-bottom: 5px; font-weight: bold; }
            .field-row input { width: 100%; }
            .error { color: red; font-weight: bold; margin: 10px 0; }
            .button-container { text-align: center; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class="window">
            <div class="title-bar">
                <div class="title-bar-text">管理者ログイン</div>
            </div>
            <div class="window-body">
                <h2>🔒 管理者ログイン</h2>
                <?php if (isset($login_error)): ?>
                    <p class="error"><?php echo h($login_error); ?></p>
                <?php endif; ?>
                <form method="POST" action="">
                    <div class="field-row">
                        <label for="admin_id">管理者ID:</label>
                        <input type="text" id="admin_id" name="admin_id" required>
                    </div>
                    <div class="field-row">
                        <label for="admin_password">パスワード:</label>
                        <input type="password" id="admin_password" name="admin_password" required>
                    </div>
                    <div class="button-container">
                        <button type="submit" name="login" style="width: 150px;">ログイン</button>
                    </div>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ログアウト処理
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// 残高変更処理
$balance_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_balance'])) {
    $user_id = intval($_POST['user_id'] ?? 0);
    $balance_type = $_POST['balance_type'] ?? '';
    $change_amount = intval($_POST['change_amount'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    
    if ($user_id > 0 && in_array($balance_type, ['balance', 'loan_balance']) && !empty($reason)) {
        try {
            $pdo = getDB();
            
            // ユーザー情報取得
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            if ($user) {
                $old_balance = $user[$balance_type];
                $new_balance = $old_balance + $change_amount;
                
                // 負の値にならないようにチェック
                if ($new_balance < 0) {
                    $balance_message = 'エラー: 残高が負の値になります。';
                } else {
                    // 残高更新
                    $stmt = $pdo->prepare("UPDATE users SET {$balance_type} = ? WHERE id = ?");
                    $stmt->execute([$new_balance, $user_id]);
                    
                    $balance_message = '残高を変更しました。';
                    
                    // Discord DMで通知
                    $balance_label = ($balance_type === 'balance') ? '口座残高' : '借金残高';
                    $change_type = ($change_amount > 0) ? '増加' : '減少';
                    
                    $dm_message = "⚙️ **残高が変更されました**\n\n";
                    $dm_message .= "管理者により{$balance_label}が変更されました。\n\n";
                    $dm_message .= "**変更内容**\n";
                    $dm_message .= "変更前: ¥" . number_format($old_balance) . "\n";
                    $dm_message .= "変更額: " . ($change_amount > 0 ? '+' : '') . "¥" . number_format($change_amount) . "\n";
                    $dm_message .= "変更後: ¥" . number_format($new_balance) . "\n\n";
                    $dm_message .= "**理由**\n";
                    $dm_message .= $reason . "\n\n";
                    $dm_message .= "ご不明な点がございましたら管理者にお問い合わせください。";
                    
                    sendDiscordDM($user['discord_id'], $dm_message);
                }
            } else {
                $balance_message = 'ユーザーが見つかりません。';
            }
        } catch (PDOException $e) {
            $balance_message = 'エラー: ' . $e->getMessage();
        }
    } else {
        $balance_message = 'すべての項目を正しく入力してください。';
    }
}

// DM送信処理
$dm_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_dm'])) {
    $discord_id = trim($_POST['discord_id'] ?? '');
    $dm_content = trim($_POST['dm_content'] ?? '');
    
    if (!empty($discord_id) && !empty($dm_content)) {
        $result = sendDiscordDM($discord_id, $dm_content);
        if ($result) {
            $dm_message = '✅ DMを送信しました。';
        } else {
            $dm_message = '❌ DM送信に失敗しました。';
        }
    } else {
        $dm_message = '⚠️ Discord IDとメッセージを入力してください。';
    }
}

// 取引処理
$action_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $transaction_id = intval($_POST['transaction_id'] ?? 0);
    $action = $_POST['action'];
    $notes = trim($_POST['notes'] ?? '');
    
    if ($transaction_id > 0 && in_array($action, ['approve', 'reject'])) {
        try {
            $pdo = getDB();
            $pdo->beginTransaction();
            
            // 取引情報取得
            $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND status = 'pending'");
            $stmt->execute([$transaction_id]);
            $transaction = $stmt->fetch();
            
            if ($transaction) {
                $new_status = ($action === 'approve') ? 'approved' : 'rejected';
                
                // ステータス更新
                $stmt = $pdo->prepare("
                    UPDATE transactions 
                    SET status = ?, processed_at = NOW(), processed_by = ?, notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([$new_status, ADMIN_ID, $notes, $transaction_id]);
                
                // 承認の場合、残高更新
                if ($action === 'approve') {
                    $user_id = $transaction['user_id'];
                    $amount = $transaction['amount'];
                    $type = $transaction['type'];
                    
                    switch ($type) {
                        case 'deposit':
                            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                            $stmt->execute([$amount, $user_id]);
                            break;
                        case 'withdraw':
                            $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
                            $stmt->execute([$amount, $user_id]);
                            break;
                        case 'borrow':
                            $stmt = $pdo->prepare("UPDATE users SET loan_balance = loan_balance + ? WHERE id = ?");
                            $stmt->execute([$amount, $user_id]);
                            break;
                        case 'repay':
                            $stmt = $pdo->prepare("UPDATE users SET loan_balance = loan_balance - ? WHERE id = ?");
                            $stmt->execute([$amount, $user_id]);
                            break;
                    }
                }
                
                // 更新後の残高を取得
                $stmt = $pdo->prepare("SELECT balance, loan_balance FROM users WHERE id = ?");
                $stmt->execute([$transaction['user_id']]);
                $updated_user = $stmt->fetch();
                
                $pdo->commit();
                $action_message = ($action === 'approve') ? '承認しました。' : '却下しました。';
                
                // Discord DMで通知を送信
                $type_labels = [
                    'deposit' => '預金',
                    'withdraw' => '出金',
                    'borrow' => '借入',
                    'repay' => '返済'
                ];
                $type_label = $type_labels[$transaction['type']];
                
                if ($action === 'approve') {
                    // 承認通知
                    $dm_message_content = "✅ **{$type_label}申請が承認されました**\n\n";
                    $dm_message_content .= "申請ID: `#{$transaction_id}`\n";
                    $dm_message_content .= "種別: **{$type_label}**\n";
                    $dm_message_content .= "金額: **¥" . number_format($transaction['amount']) . "**\n";
                    if (!empty($notes)) {
                        $dm_message_content .= "備考: {$notes}\n";
                    }
                    $dm_message_content .= "\n**更新後の残高**\n";
                    $dm_message_content .= "口座残高: ¥" . number_format($updated_user['balance']) . "\n";
                    $dm_message_content .= "借金残高: ¥" . number_format($updated_user['loan_balance']);
                    
                    sendDiscordDM($transaction['discord_id'], $dm_message_content);
                } else {
                    // 却下通知
                    $dm_message_content = "❌ **{$type_label}申請が却下されました**\n\n";
                    $dm_message_content .= "申請ID: `#{$transaction_id}`\n";
                    $dm_message_content .= "種別: **{$type_label}**\n";
                    $dm_message_content .= "金額: **¥" . number_format($transaction['amount']) . "**\n";
                    if (!empty($notes)) {
                        $dm_message_content .= "理由: {$notes}\n";
                    }
                    $dm_message_content .= "\nご不明な点がございましたら管理者にお問い合わせください。";
                    
                    sendDiscordDM($transaction['discord_id'], $dm_message_content);
                }
            } else {
                $action_message = '取引が見つからないか、既に処理済みです。';
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $action_message = 'エラー: ' . $e->getMessage();
        }
    }
}

// パスワード検索
$search_result = null;
if (isset($_GET['search_password'])) {
    $search_password = trim($_GET['password'] ?? '');
    if (!empty($search_password)) {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE password_sha512 = ?");
            $stmt->execute([$search_password]);
            $search_result = $stmt->fetch();
        } catch (PDOException $e) {
            $search_result = false;
        }
    }
}

// データ取得
try {
    $pdo = getDB();
    
    // ユーザー一覧
    $stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
    $users = $stmt->fetchAll();
    
    // 申請中の取引
    $stmt = $pdo->query("SELECT * FROM transactions WHERE status = 'pending' ORDER BY created_at DESC");
    $pending_transactions = $stmt->fetchAll();
    
    // 承認済み取引(預金・出金)
    $stmt = $pdo->query("SELECT * FROM transactions WHERE status = 'approved' AND type IN ('deposit', 'withdraw') ORDER BY processed_at DESC LIMIT 50");
    $approved_yokin = $stmt->fetchAll();
    
    // 却下済み取引(預金・出金)
    $stmt = $pdo->query("SELECT * FROM transactions WHERE status = 'rejected' AND type IN ('deposit', 'withdraw') ORDER BY processed_at DESC LIMIT 50");
    $rejected_yokin = $stmt->fetchAll();
    
    // 承認済み取引(借入・返済)
    $stmt = $pdo->query("SELECT * FROM transactions WHERE status = 'approved' AND type IN ('borrow', 'repay') ORDER BY processed_at DESC LIMIT 50");
    $approved_syakkin = $stmt->fetchAll();
    
    // 却下済み取引(借入・返済)
    $stmt = $pdo->query("SELECT * FROM transactions WHERE status = 'rejected' AND type IN ('borrow', 'repay') ORDER BY processed_at DESC LIMIT 50");
    $rejected_syakkin = $stmt->fetchAll();
    
} catch (PDOException $e) {
    die('データベースエラー: ' . $e->getMessage());
}

$type_labels = [
    'deposit' => '預金',
    'withdraw' => '出金',
    'borrow' => '借入',
    'repay' => '返済'
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理者ページ - 大葉信用組合</title>
    <link rel="stylesheet" href="https://unpkg.com/98.css">
    <style>
        body { padding: 20px; background: teal; }
        .container { max-width: 1400px; margin: 0 auto; }
        .window { margin-bottom: 20px; }
        .window-body { padding: 15px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        table th, table td { padding: 8px; border: 1px solid #000; text-align: left; }
        table th { background: #c0c0c0; font-weight: bold; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .button-small { padding: 2px 8px; margin: 2px; }
        .search-box { background: #fff; padding: 15px; border: 2px solid #000; margin: 15px 0; }
        .logout-btn { float: right; }
        .tabs { margin: 10px 0; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .status-pending { background: #fff3cd; }
        .status-approved { background: #d4edda; }
        .status-rejected { background: #f8d7da; }
        .dm-form { background: #e0e0e0; padding: 15px; border: 2px solid #000; margin: 15px 0; }
        .dm-form textarea { width: 100%; height: 100px; margin: 10px 0; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: #c0c0c0; margin: 5% auto; padding: 20px; border: 3px solid #000; width: 500px; }
        .modal-header { background: #000080; color: white; padding: 5px 10px; margin: -20px -20px 15px -20px; }
        .close { color: white; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
    </style>
</head>
<body>
    <div class="container">
        <div class="window">
            <div class="title-bar">
                <div class="title-bar-text">大葉信用組合 - 管理者ページ</div>
                <div class="title-bar-controls">
                    <a href="?logout=1" class="logout-btn"><button>ログアウト</button></a>
                </div>
            </div>
            <div class="window-body">
                <h2>🔧 管理者ダッシュボード</h2>
                <?php if ($action_message): ?>
                    <p class="success">✅ <?php echo h($action_message); ?> (Discord DMで通知を送信しました)</p>
                <?php endif; ?>
                <?php if ($dm_message): ?>
                    <p class="<?php echo strpos($dm_message, '✅') !== false ? 'success' : 'error'; ?>"><?php echo h($dm_message); ?></p>
                <?php endif; ?>
                <?php if ($balance_message): ?>
                    <p class="<?php echo strpos($balance_message, 'エラー') === false ? 'success' : 'error'; ?>"><?php echo h($balance_message); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- DM送信フォーム -->
        <div class="window">
            <div class="title-bar">
                <div class="title-bar-text">💬 DM送信</div>
            </div>
            <div class="window-body">
                <div class="dm-form">
                    <form method="POST" action="">
                        <div class="field-row">
                            <label for="discord_id"><strong>Discord ID:</strong></label>
                            <input type="text" id="discord_id" name="discord_id" placeholder="例: 123456789012345678" required style="width: 300px;">
                        </div>
                        <div class="field-row">
                            <label for="dm_content"><strong>メッセージ内容:</strong></label>
                            <textarea id="dm_content" name="dm_content" placeholder="送信するメッセージを入力してください" required></textarea>
                        </div>
                        <div class="field-row">
                            <button type="submit" name="send_dm" style="width: 150px;">📧 DM送信</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- パスワード検索 -->
        <div class="window">
            <div class="title-bar">
                <div class="title-bar-text">🔍 パスワード検索</div>
            </div>
            <div class="window-body">
                <form method="GET" action="">
                    <div class="field-row">
                        <label>パスワード(SHA512ハッシュ):</label>
                        <input type="text" name="password" style="width: 70%;" value="<?php echo h($_GET['password'] ?? ''); ?>">
                        <button type="submit" name="search_password">検索</button>
                    </div>
                </form>
                
                <?php if (isset($_GET['search_password'])): ?>
                    <?php if ($search_result): ?>
                        <div class="search-box">
                            <h3>検索結果:</h3>
                            <p><strong>Discord ID:</strong> <?php echo h($search_result['discord_id']); ?></p>
                            <p><strong>ユーザー名:</strong> <?php echo h($search_result['username']); ?></p>
                            <p><strong>残高:</strong> ¥<?php echo number_format($search_result['balance']); ?></p>
                            <p><strong>借金残高:</strong> ¥<?php echo number_format($search_result['loan_balance']); ?></p>
                            <p><strong>登録日:</strong> <?php echo h($search_result['created_at']); ?></p>
                        </div>
                    <?php elseif ($search_result === false): ?>
                        <p class="error">該当するユーザーが見つかりませんでした。</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ユーザー一覧 -->
        <div class="window">
            <div class="title-bar">
                <div class="title-bar-text">👥 登録ユーザー一覧 (<?php echo count($users); ?>人)</div>
            </div>
            <div class="window-body" style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Discord ID</th>
                            <th>ユーザー名</th>
                            <th>残高</th>
                            <th>借金残高</th>
                            <th>登録日</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo h($user['id']); ?></td>
                                <td><?php echo h($user['discord_id']); ?></td>
                                <td><?php echo h($user['username']); ?></td>
                                <td>¥<?php echo number_format($user['balance']); ?></td>
                                <td>¥<?php echo number_format($user['loan_balance']); ?></td>
                                <td><?php echo h($user['created_at']); ?></td>
                                <td>
                                    <button class="button-small" onclick="openBalanceModal(<?php echo $user['id']; ?>, '<?php echo h($user['username']); ?>', <?php echo $user['balance']; ?>, <?php echo $user['loan_balance']; ?>)">残高変更</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 残高変更モーダル -->
        <div id="balanceModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <span class="close" onclick="closeBalanceModal()">&times;</span>
                    <strong>💰 残高変更</strong>
                </div>
                <form method="POST" action="">
                    <input type="hidden" id="modal_user_id" name="user_id">
                    <div class="field-row">
                        <strong>ユーザー:</strong> <span id="modal_username"></span>
                    </div>
                    <div class="field-row">
                        <strong>現在の口座残高:</strong> ¥<span id="modal_balance"></span>
                    </div>
                    <div class="field-row">
                        <strong>現在の借金残高:</strong> ¥<span id="modal_loan_balance"></span>
                    </div>
                    <hr>
                    <div class="field-row">
                        <label for="balance_type">変更する残高:</label>
                        <select id="balance_type" name="balance_type" required>
                            <option value="balance">口座残高</option>
                            <option value="loan_balance">借金残高</option>
                        </select>
                    </div>
                    <div class="field-row">
                        <label for="change_amount">変更額(+増加 / -減少):</label>
                        <input type="number" id="change_amount" name="change_amount" required placeholder="例: +1000 または -500">
                    </div>
                    <div class="field-row">
                        <label for="reason">理由(必須):</label>
                        <textarea id="reason" name="reason" required placeholder="残高を変更する理由を入力してください" style="width: 100%; height: 80px;"></textarea>
                    </div>
                    <div class="field-row" style="text-align: center;">
                        <button type="submit" name="change_balance" style="width: 150px;">変更実行</button>
                        <button type="button" onclick="closeBalanceModal()" style="width: 150px;">キャンセル</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 申請中の取引 -->
        <div class="window">
            <div class="title-bar">
                <div class="title-bar-text">⏳ 承認待ち取引 (<?php echo count($pending_transactions); ?>件)</div>
            </div>
            <div class="window-body" style="overflow-x: auto;">
                <?php if (empty($pending_transactions)): ?>
                    <p>承認待ちの取引はありません。</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>ユーザー名</th>
                                <th>種別</th>
                                <th>金額</th>
                                <th>申請日時</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_transactions as $trans): ?>
                                <tr class="status-pending">
                                    <td><?php echo h($trans['id']); ?></td>
                                    <td><?php echo h($trans['username']); ?></td>
                                    <td><?php echo h($type_labels[$trans['type']]); ?></td>
                                    <td>¥<?php echo number_format($trans['amount']); ?></td>
                                    <td><?php echo h($trans['created_at']); ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="transaction_id" value="<?php echo $trans['id']; ?>">
                                            <input type="text" name="notes" placeholder="備考" style="width: 100px;">
                                            <button type="submit" name="action" value="approve" class="button-small">承認</button>
                                            <button type="submit" name="action" value="reject" class="button-small">却下</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- タブメニュー -->
        <div class="window">
            <div class="title-bar">
                <div class="title-bar-text">📊 取引履歴</div>
            </div>
            <div class="window-body">
                <div class="tabs">
                    <button onclick="showTab('approved-yokin')">預金・出金(承認済み)</button>
                    <button onclick="showTab('rejected-yokin')">預金・出金(却下済み)</button>
                    <button onclick="showTab('approved-syakkin')">借入・返済(承認済み)</button>
                    <button onclick="showTab('rejected-syakkin')">借入・返済(却下済み)</button>
                </div>

                <!-- 預金・出金(承認済み) -->
                <div id="approved-yokin" class="tab-content active">
                    <h3>預金・出金(承認済み) - <?php echo count($approved_yokin); ?>件</h3>
                    <table>
                        <thead>
                            <tr><th>ID</th><th>ユーザー名</th><th>種別</th><th>金額</th><th>申請日時</th><th>処理日時</th><th>備考</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($approved_yokin as $trans): ?>
                                <tr class="status-approved">
                                    <td><?php echo h($trans['id']); ?></td>
                                    <td><?php echo h($trans['username']); ?></td>
                                    <td><?php echo h($type_labels[$trans['type']]); ?></td>
                                    <td>¥<?php echo number_format($trans['amount']); ?></td>
                                    <td><?php echo h($trans['created_at']); ?></td>
                                    <td><?php echo h($trans['processed_at']); ?></td>
                                    <td><?php echo h($trans['notes']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 預金・出金(却下済み) -->
                <div id="rejected-yokin" class="tab-content">
                    <h3>預金・出金(却下済み) - <?php echo count($rejected_yokin); ?>件</h3>
                    <table>
                        <thead>
                            <tr><th>ID</th><th>ユーザー名</th><th>種別</th><th>金額</th><th>申請日時</th><th>処理日時</th><th>備考</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rejected_yokin as $trans): ?>
                                <tr class="status-rejected">
                                    <td><?php echo h($trans['id']); ?></td>
                                    <td><?php echo h($trans['username']); ?></td>
                                    <td><?php echo h($type_labels[$trans['type']]); ?></td>
                                    <td>¥<?php echo number_format($trans['amount']); ?></td>
                                    <td><?php echo h($trans['created_at']); ?></td>
                                    <td><?php echo h($trans['processed_at']); ?></td>
                                    <td><?php echo h($trans['notes']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 借入・返済(承認済み) -->
                <div id="approved-syakkin" class="tab-content">
                    <h3>借入・返済(承認済み) - <?php echo count($approved_syakkin); ?>件</h3>
                    <table>
                        <thead>
                            <tr><th>ID</th><th>ユーザー名</th><th>種別</th><th>金額</th><th>申請日時</th><th>処理日時</th><th>備考</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($approved_syakkin as $trans): ?>
                                <tr class="status-approved">
                                    <td><?php echo h($trans['id']); ?></td>
                                    <td><?php echo h($trans['username']); ?></td>
                                    <td><?php echo h($type_labels[$trans['type']]); ?></td>
                                    <td>¥<?php echo number_format($trans['amount']); ?></td>
                                    <td><?php echo h($trans['created_at']); ?></td>
                                    <td><?php echo h($trans['processed_at']); ?></td>
                                    <td><?php echo h($trans['notes']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 借入・返済(却下済み) -->
                <div id="rejected-syakkin" class="tab-content">
                    <h3>借入・返済(却下済み) - <?php echo count($rejected_syakkin); ?>件</h3>
                    <table>
                        <thead>
                            <tr><th>ID</th><th>ユーザー名</th><th>種別</th><th>金額</th><th>申請日時</th><th>処理日時</th><th>備考</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rejected_syakkin as $trans): ?>
                                <tr class="status-rejected">
                                    <td><?php echo h($trans['id']); ?></td>
                                    <td><?php echo h($trans['username']); ?></td>
                                    <td><?php echo h($type_labels[$trans['type']]); ?></td>
                                    <td>¥<?php echo number_format($trans['amount']); ?></td>
                                    <td><?php echo h($trans['created_at']); ?></td>
                                    <td><?php echo h($trans['processed_at']); ?></td>
                                    <td><?php echo h($trans['notes']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
                <!-- クイックリンク -->
                <hr style="margin: 30px 0;">
                <div class="button-container">
                    <h3>📱 クイックリンク</h3>
                    <a href="signup.php"><button style="margin: 5px;">👛 新規登録</button></a>
                    <a href="yokin.php"><button style="margin: 5px;">💰 預金・出金</button></a>
                    <a href="syakkin.php"><button style="margin: 5px;">💳 借入・返済</button></a>
                    <a href="mypage.php"><button style="margin: 5px;">☎️ マイページ</button></a>
                    <a href="index.html"><button style="margin: 5px;">🏠 トップページ</button></a>
                </div>
        </div>
    </div>

    <script>
        function showTab(tabId) {
            const tabs = document.querySelectorAll('.tab-content');
            tabs.forEach(tab => tab.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
        }

        function openBalanceModal(userId, username, balance, loanBalance) {
            document.getElementById('modal_user_id').value = userId;
            document.getElementById('modal_username').textContent = username;
            document.getElementById('modal_balance').textContent = balance.toLocaleString();
            document.getElementById('modal_loan_balance').textContent = loanBalance.toLocaleString();
            document.getElementById('balanceModal').style.display = 'block';
        }

        function closeBalanceModal() {
            document.getElementById('balanceModal').style.display = 'none';
            document.getElementById('change_amount').value = '';
            document.getElementById('reason').value = '';
        }

        // モーダル外クリックで閉じる
        window.onclick = function(event) {
            const modal = document.getElementById('balanceModal');
            if (event.target == modal) {
                closeBalanceModal();
            }
        }
    </script>
</body>
</html>