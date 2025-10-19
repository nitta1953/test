<?php
require_once 'config.php';

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_transaction'])) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $transaction_type = $_POST['transaction_type'] ?? '';
    $amount = intval($_POST['amount'] ?? 0);
    
    if (empty($username) || empty($password) || empty($transaction_type) || $amount <= 0) {
        $message = 'すべての項目を正しく入力してください。';
        $message_type = 'error';
    } else {
        try {
            $pdo = getDB();
            
            // ユーザー検証
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND password_sha512 = ?");
            $stmt->execute([$username, $password]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $message = 'ユーザーネームまたはパスワードが正しくありません。';
                $message_type = 'error';
            } else {
                // 出金の場合、残高チェック
                if ($transaction_type === 'withdraw' && $user['balance'] < $amount) {
                    $message = '残高不足です。現在の残高: ¥' . number_format($user['balance']);
                    $message_type = 'error';
                } else {
                    // 取引記録を作成
                    $stmt = $pdo->prepare("
                        INSERT INTO transactions (user_id, discord_id, username, password_sha512, type, amount, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    $stmt->execute([
                        $user['id'],
                        $user['discord_id'],
                        $user['username'],
                        $user['password_sha512'],
                        $transaction_type,
                        $amount
                    ]);
                    
                    $transaction_id = $pdo->lastInsertId();
                    
                    $message = '申請を受け付けました。管理者の承認をお待ちください。';
                    $message_type = 'success';
                    
                    // Discord DMで通知を送信
                    $type_label = ($transaction_type === 'deposit') ? '預金' : '出金';
                    $dm_message = "📝 **{$type_label}申請を受付ました**\n\n";
                    $dm_message .= "申請ID: `#{$transaction_id}`\n";
                    $dm_message .= "種別: **{$type_label}**\n";
                    $dm_message .= "金額: **¥" . number_format($amount) . "**\n";
                    $dm_message .= "ステータス: `承認待ち`\n\n";
                    $dm_message .= "管理者の承認をお待ちください。\n";
                    $dm_message .= "承認/却下された際に再度通知します。";
                    
                    sendDiscordDM($user['discord_id'], $dm_message);
                    
                    // 管理者への通知
                    $current_time = date('Y-m-d H:i:s');
                    $admin_notification = "申請が来たのだ!({$current_time})\n" . ADMIN_URL;
                    sendDiscordDM(ADMIN_DISCORD_ID, $admin_notification);
                }
            }
        } catch (PDOException $e) {
            $message = 'エラーが発生しました: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>預金・出金 - 大葉信用組合</title>
    <link rel="stylesheet" href="https://unpkg.com/98.css">
    <style>
        body {
            padding: 20px;
            background: teal;
        }
        .window {
            max-width: 600px;
            margin: 50px auto;
        }
        .window-body {
            padding: 20px;
        }
        .field-row {
            margin: 15px 0;
        }
        .field-row label {
            display: inline-block;
            width: 150px;
            font-weight: bold;
        }
        .field-row input,
        .field-row select {
            width: calc(100% - 160px);
        }
        .message {
            padding: 15px;
            margin: 15px 0;
            border: 2px solid;
        }
        .message.success {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }
        .message.error {
            background: #f8d7da;
            border-color: #dc3545;
            color: #721c24;
        }
        .button-container {
            text-align: center;
            margin-top: 20px;
        }
        .info-box {
            background: #fff;
            border: 2px solid #000;
            padding: 15px;
            margin: 15px 0;
        }
    </style>
</head>
<body>
    <div class="window">
        <div class="title-bar">
            <div class="title-bar-text">大葉信用組合 - 預金・出金</div>
            <div class="title-bar-controls">
                <button aria-label="Close"></button>
            </div>
        </div>
        <div class="window-body">
            <h2>💰 預金・出金申請</h2>
            
            <?php if ($message): ?>
                <div class="message <?php echo h($message_type); ?>">
                    <?php echo h($message); ?>
                    <?php if ($message_type === 'success'): ?>
                        <br><small>📧 DiscordのDMにも通知を送信しました。</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="info-box">
                <p><strong>ℹ️ 注意事項:</strong></p>
                <ul>
                    <li>申請後、管理者の承認が必要です</li>
                    <li>出金は現在の残高を超えることはできません</li>
                    <li>金額は整数で入力してください</li>
                    <li>パスワードは登録時に表示されたSHA512ハッシュ値です</li>
                    <li>承認/却下された際にDiscordのDMで通知が届きます</li>
                </ul>
            </div>
            
            <form method="POST" action="">
                <div class="field-row">
                    <label for="username">Discordユーザー名:</label>
                    <input type="text" id="username" name="username" required>
                </div>
                
                <div class="field-row">
                    <label for="password">パスワード:</label>
                    <input type="text" id="password" name="password" required placeholder="SHA512ハッシュ値">
                </div>
                
                <div class="field-row">
                    <label for="transaction_type">取引種別:</label>
                    <select id="transaction_type" name="transaction_type" required>
                        <option value="">選択してください</option>
                        <option value="deposit">預金</option>
                        <option value="withdraw">出金</option>
                    </select>
                </div>
                
                <div class="field-row">
                    <label for="amount">金額:</label>
                    <input type="number" id="amount" name="amount" min="1" step="1" required>
                </div>
                
                <div class="button-container">
                    <button type="submit" name="submit_transaction" style="width: 200px; height: 40px;">
                        申請する
                    </button>
                </div>
            </form>
            
            <hr style="margin: 30px 0;">
            
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
</body>
</html>