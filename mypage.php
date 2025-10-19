<?php
require_once 'config.php';

$error_message = '';
$user_info = null;

// OAuth2認証URLを生成
$params = [
    'client_id' => DISCORD_CLIENT_ID,
    'redirect_uri' => DISCORD_MYPAGE_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => 'identify'
];
$auth_url = 'https://discord.com/api/oauth2/authorize?' . http_build_query($params);

// セッションにユーザー情報がある場合
if (isset($_SESSION['mypage_user'])) {
    $user_info = $_SESSION['mypage_user'];
}

// ログアウト処理
if (isset($_GET['logout'])) {
    unset($_SESSION['mypage_user']);
    header('Location: mypage.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>マイページ - 大葉信用組合</title>
    <link rel="stylesheet" href="https://unpkg.com/xp.css">
    <style>
        body {
            padding: 20px;
            background: teal;
        }
        .window {
            max-width: 700px;
            margin: 50px auto;
        }
        .window-body {
            padding: 20px;
        }
        .info-box {
            background: #fff;
            border: 2px solid #000;
            padding: 15px;
            margin: 15px 0;
        }
        .info-row {
            display: flex;
            margin: 10px 0;
            padding: 10px;
            background: #e0e0e0;
            border: 1px solid #000;
        }
        .info-label {
            font-weight: bold;
            width: 150px;
        }
        .info-value {
            flex: 1;
            word-break: break-all;
        }
        .button-container {
            text-align: center;
            margin-top: 20px;
        }
        .logout-btn {
            float: right;
        }
        .balance-display {
            font-size: 24px;
            font-weight: bold;
            color: #000080;
        }
    </style>
</head>
<body>
    <?php if (!$user_info): ?>
        <!-- ログイン前の画面 -->
        <div class="window">
            <div class="title-bar">
                <div class="title-bar-text">大葉信用組合 - マイページ</div>
                <div class="title-bar-controls">
                    <button aria-label="Close"></button>
                </div>
            </div>
            <div class="window-body">
                <h2>🔐 マイページログイン</h2>
                
                <div class="info-box">
                    <p><strong>できること:</strong></p>
                    <ul>
                        <li>残高・借金残高の確認</li>
                        <li>アカウント情報の確認</li>
                    </ul>
                </div>

                <div class="button-container">
                    <a href="<?php echo h($auth_url); ?>" style="text-decoration: none;">
                        <button style="width: 250px; height: 50px; font-size: 18px;">
                            🔑 Discordでログイン
                        </button>
                    </a>
                </div>

                <hr style="margin: 30px 0;">

                <div class="button-container">
                    <p>アカウントをお持ちでない方:</p>
                    <a href="signup.php"><button>新規登録</button></a>
                </div>
                
                <div class="button-container" style="margin-top: 20px;">
                    <p>パスワードを忘れた方:</p>
                    <a href="signup.php"><button style="background: #ffc107;">パスワード再発行</button></a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- ログイン後の画面 -->
        <div class="window">
            <div class="title-bar">
                <div class="title-bar-text">大葉信用組合 - マイページ</div>
                <div class="title-bar-controls">
                    <a href="?logout=1" class="logout-btn"><button>ログアウト</button></a>
                </div>
            </div>
            <div class="window-body">
                <h2>👤 マイページ</h2>
                <p>ようこそ、<strong><?php echo h($user_info['username']); ?></strong> さん！</p>

                <!-- アカウント情報 -->
                <div class="info-box">
                    <h3>💳 アカウント情報</h3>
                    
                    <div class="info-row">
                        <div class="info-label">Discord ID:</div>
                        <div class="info-value"><?php echo h($user_info['discord_id']); ?></div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">ユーザー名:</div>
                        <div class="info-value"><?php echo h($user_info['username']); ?></div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">登録日:</div>
                        <div class="info-value"><?php echo h($user_info['created_at']); ?></div>
                    </div>
                </div>

                <!-- 残高情報 -->
                <div class="info-box">
                    <h3>💰 残高情報</h3>
                    
                    <div class="info-row">
                        <div class="info-label">口座残高:</div>
                        <div class="info-value balance-display">
                            ¥<?php echo number_format($user_info['balance']); ?>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">借金残高:</div>
                        <div class="info-value balance-display" style="color: <?php echo $user_info['loan_balance'] > 0 ? 'red' : 'green'; ?>;">
                            ¥<?php echo number_format($user_info['loan_balance']); ?>
                        </div>
                    </div>
                </div>

                <!-- パスワード再発行案内 -->
                <div class="info-box" style="background: #fff3cd; border-color: #ffc107;">
                    <h3>🔄 パスワードを忘れた場合</h3>
                    <p>パスワードを忘れた場合は、以下のページから再発行できます。</p>
                    <div class="button-container">
                        <a href="signup.php"><button style="background: #ffc107; width: 200px;">パスワード再発行ページへ</button></a>
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
    <?php endif; ?>
</body>
</html>