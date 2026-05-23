<?php
// 冰箱库存管理系统 · 安装向导
$lockFile = __DIR__ . '/install.lock';

if (file_exists($lockFile)) {
    $time = date('Y-m-d H:i:s', filemtime($lockFile));
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>安装向导 · 冰箱库存管理系统</title>
        <style>
            *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
            body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Noto Sans SC",sans-serif;background:#f1f5f9;color:#1e293b;display:flex;align-items:center;justify-content:center;min-height:100vh}
            .msg{background:#fff;border-radius:12px;padding:2.5rem;text-align:center;box-shadow:0 4px 12px rgba(0,0,0,.08);max-width:440px}
            .msg .icon{font-size:3rem;margin-bottom:1rem}
            .msg h1{font-size:1.3rem;margin-bottom:.5rem;color:#22c55e}
            .msg p{color:#64748b;font-size:.9rem;line-height:1.6}
            .msg .time{margin-top:1rem;font-size:.8rem;color:#94a3b8}
            .msg a{display:inline-block;margin-top:1.25rem;color:#3b82f6;text-decoration:none;font-weight:500}
            .msg a:hover{text-decoration:underline}
        </style>
    </head>
    <body>
        <div class="msg">
            <div class="icon">✅</div>
            <h1>系统已安装</h1>
            <p>冰箱库存管理系统已完成部署，安装向导已锁定。</p>
            <p class="time">安装时间：<?php echo $time; ?></p>
            <p>如需重新安装，请删除项目目录下的 <code>install.lock</code> 文件。</p>
            <a href="index.html">进入系统 →</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ========== 处理安装请求 ==========
$step = 1;
$error = '';
$success = '';
$hasExistingData = false;
$existingTables = [];

// 保存表单回填值
$formHost = 'localhost';
$formPort = '3306';
$formName = '';
$formUser = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['host'] ?? 'localhost');
    $port = trim($_POST['port'] ?? '3306');
    $name = trim($_POST['name'] ?? '');
    $user = trim($_POST['user'] ?? '');
    $pass = trim($_POST['pass'] ?? '');
    $createDb = !empty($_POST['create_db']);
    $action = $_POST['action'] ?? '';

    $formHost = $host;
    $formPort = $port;
    $formName = $name;
    $formUser = $user;

    if ($action === 'cancel') {
        $step = 1;
        $error = '';
    } elseif (!$name) {
        $error = '请填写数据库名称';
    } elseif (!$user) {
        $error = '请填写数据库用户名';
    } else {
        try {
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            if ($createDb) {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
            $pdo->exec("USE `{$name}`");

            // 检测已有数据表
            $existingTables = $pdo->query("SHOW TABLES LIKE 'fridges'")->fetchAll();
            $hasExistingData = !empty($existingTables);

            if ($action === 'keep') {
                // 保留现有数据，仅写入配置文件
                writeConfig($host, $port, $name, $user, $pass);
                file_put_contents($lockFile, date('Y-m-d H:i:s'));
                $success = '配置文件已更新，现有数据完整保留。';
                $step = 2;
            } elseif ($action === 'reinit') {
                // 删除旧表后重建
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $t) {
                    $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
                }
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                runInitSql($pdo);
                writeConfig($host, $port, $name, $user, $pass);
                file_put_contents($lockFile, date('Y-m-d H:i:s'));
                $success = '安装完成！旧数据已清除，数据库已重新初始化。';
                $step = 2;
            } elseif ($hasExistingData) {
                // 检测到已有数据，需要用户确认
                $step = 3; // 确认页
            } else {
                // 全新安装
                runInitSql($pdo);
                writeConfig($host, $port, $name, $user, $pass);
                file_put_contents($lockFile, date('Y-m-d H:i:s'));
                $success = '安装完成！数据库已初始化，配置文件已写入。';
                $step = 2;
            }
        } catch (PDOException $e) {
            $error = '数据库连接失败：' . $e->getMessage();
        } catch (\Throwable $e) {
            $error = '安装失败：' . $e->getMessage();
        }
    }
}

function runInitSql(PDO $pdo): void {
    $initSql = file_get_contents(__DIR__ . '/init.sql');
    $initSql = preg_replace('/^CREATE DATABASE.*?;$/im', '', $initSql);
    $initSql = preg_replace('/^USE `.*?`;$/im', '', $initSql);
    $initSql = trim($initSql);
    if ($initSql) {
        $statements = array_filter(
            array_map('trim', explode(';', $initSql)),
            fn($s) => !empty($s) && !str_starts_with($s, '--')
        );
        foreach ($statements as $stmt) {
            $pdo->exec($stmt);
        }
    }
}

function writeConfig(string $host, string $port, string $name, string $user, string $pass): void {
    $configTemplate = file_get_contents(__DIR__ . '/api/config.php');
    $replacements = [
        '{{DB_HOST}}' => $host,
        '{{DB_PORT}}' => $port,
        '{{DB_NAME}}' => $name,
        '{{DB_USER}}' => $user,
        '{{DB_PASS}}' => $pass,
    ];
    $newConfig = str_replace(array_keys($replacements), array_values($replacements), $configTemplate);
    file_put_contents(__DIR__ . '/api/config.php', $newConfig);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安装向导 · 冰箱库存管理系统</title>
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Noto Sans SC",sans-serif;background:#f1f5f9;color:#1e293b;display:flex;align-items:center;justify-content:center;min-height:100vh;line-height:1.6}
        .installer{background:#fff;border-radius:12px;padding:2.5rem;box-shadow:0 4px 24px rgba(0,0,0,.1);max-width:480px;width:90%}
        .installer .header{text-align:center;margin-bottom:1.5rem}
        .installer .header h1{font-size:1.3rem;margin-bottom:.25rem}
        .installer .header p{color:#64748b;font-size:.85rem}
        .step-badge{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#3b82f6;color:#fff;font-weight:700;font-size:.85rem;margin-bottom:.5rem}
        .form-group{margin-bottom:1rem}
        .form-group label{display:block;font-size:.85rem;font-weight:500;color:#64748b;margin-bottom:.25rem}
        .form-group input,.form-group select{width:100%;padding:.55rem .75rem;border:1px solid #e2e8f0;border-radius:8px;font-size:.9rem;transition:border-color .2s;background:#fff}
        .form-group input:focus{outline:none;border-color:#3b82f6}
        .form-check{display:flex;align-items:center;gap:.5rem;margin:1rem 0;font-size:.85rem;color:#64748b}
        .form-check input[type=checkbox]{width:16px;height:16px;accent-color:#3b82f6}
        .btn{display:inline-flex;align-items:center;justify-content:center;width:100%;padding:.65rem 1rem;border:none;border-radius:8px;font-size:.95rem;font-weight:600;cursor:pointer;transition:background .2s}
        .btn-primary{background:#3b82f6;color:#fff}
        .btn-primary:hover{background:#2563eb}
        .alert{padding:.75rem 1rem;border-radius:8px;font-size:.85rem;margin-bottom:1rem}
        .alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
        .alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
        .alert-warning{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
        .check-items{display:flex;flex-direction:column;gap:.25rem;font-size:.82rem;color:#64748b;margin:.5rem 0 0 .25rem}
        .check-items span::before{content:'✓ ';color:#22c55e;font-weight:700}
        .success-box{text-align:center}
        .success-box .icon{font-size:3rem;margin-bottom:.75rem}
        .success-box h2{font-size:1.2rem;margin-bottom:.5rem;color:#22c55e}
        .success-box p{color:#64748b;font-size:.9rem;margin-bottom:.5rem}
        .success-box a{display:inline-block;margin-top:.75rem;color:#3b82f6;text-decoration:none;font-weight:500}
        .success-box a:hover{text-decoration:underline}
        .action-buttons{display:flex;gap:.75rem;margin-top:1rem}
        .action-buttons .btn{flex:1}
        .btn-outline{background:#fff;color:#3b82f6;border:2px solid #3b82f6}
        .btn-outline:hover{background:#eff6ff}
        .btn-danger{background:#ef4444;color:#fff}
        .btn-danger:hover{background:#dc2626}
        .btn-ghost{background:#f1f5f9;color:#64748b}
        .btn-ghost:hover{background:#e2e8f0}
        code{background:#f1f5f9;padding:.15rem .4rem;border-radius:4px;font-size:.82rem;color:#e11d48}
    </style>
</head>
<body>
    <div class="installer">
        <?php if ($step === 2): ?>
            <div class="success-box">
                <div class="icon">🎉</div>
                <h2>安装完成</h2>
                <p><?php echo htmlspecialchars($success); ?></p>
                <div class="check-items">
                    <span>数据库连接成功</span>
                    <span>配置文件已写入 <code>api/config.php</code></span>
                    <span>安装锁 <code>install.lock</code> 已生成</span>
                </div>
                <a href="index.html">进入系统 →</a>
            </div>

        <?php elseif ($step === 3): ?>
            <div class="header">
                <div class="step-badge" style="background:#f59e0b;">!</div>
                <h1>⚠️ 检测到已有数据</h1>
                <p>目标数据库 <code><?php echo htmlspecialchars($formName); ?></code> 中已存在数据表，可能包含现有数据。</p>
            </div>

            <div class="alert alert-warning">
                请选择安装方式。保留数据仅更新配置文件不会影响现有数据；覆盖安装将<strong>永久删除</strong>所有旧数据后重建表结构。
            </div>

            <form method="POST">
                <input type="hidden" name="host" value="<?php echo htmlspecialchars($formHost); ?>">
                <input type="hidden" name="port" value="<?php echo htmlspecialchars($formPort); ?>">
                <input type="hidden" name="name" value="<?php echo htmlspecialchars($formName); ?>">
                <input type="hidden" name="user" value="<?php echo htmlspecialchars($formUser); ?>">
                <input type="hidden" name="pass" value="<?php echo htmlspecialchars($_POST['pass'] ?? ''); ?>">
                <div class="action-buttons">
                    <button type="submit" name="action" value="keep" class="btn btn-outline">保留现有数据<br><small>仅更新配置文件</small></button>
                    <button type="submit" name="action" value="reinit" class="btn btn-danger" onclick="return confirm('确定要删除所有旧数据并重建吗？此操作不可恢复！')">覆盖安装<br><small>删除数据后重建</small></button>
                </div>
                <button type="submit" name="action" value="cancel" class="btn btn-ghost" style="margin-top:.5rem;">取消，返回修改配置</button>
            </form>

        <?php else: ?>
            <div class="header">
                <div class="step-badge">1</div>
                <h1>🧊 冰箱库存管理系统 · 安装向导</h1>
                <p>配置 MySQL 数据库连接信息</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>数据库主机</label>
                    <input type="text" name="host" value="<?php echo htmlspecialchars($formHost); ?>" required>
                </div>
                <div class="form-group">
                    <label>端口</label>
                    <input type="text" name="port" value="<?php echo htmlspecialchars($formPort); ?>" required>
                </div>
                <div class="form-group">
                    <label>数据库名 <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($formName); ?>" placeholder="fridge_manager" required>
                </div>
                <div class="form-group">
                    <label>用户名 <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="user" value="<?php echo htmlspecialchars($formUser); ?>" placeholder="root" required>
                </div>
                <div class="form-group">
                    <label>密码</label>
                    <input type="password" name="pass" placeholder="数据库密码">
                </div>
                <label class="form-check">
                    <input type="checkbox" name="create_db" value="1" checked>
                    自动创建数据库（如不存在则新建）
                </label>
                <button type="submit" class="btn btn-primary">开始安装</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
