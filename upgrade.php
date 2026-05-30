<?php
// 冰箱库存管理系统 · 数据库升级向导
$step = 1;
$error = '';
$success = '';
$upToDate = false;

// 尝试加载配置
$configFile = __DIR__ . '/api/config.php';
if (!file_exists($configFile)) {
    $error = '配置文件 api/config.php 不存在，请先完成安装。';
    $step = 0;
} else {
    require_once $configFile;

    // 检查 install.lock，防止在未安装的系统上运行
    if (!file_exists(__DIR__ . '/install.lock')) {
        $error = '系统尚未安装，请先运行 <a href="install.php">安装向导</a>。';
        $step = 0;
    }
}

if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = getDB();

        // 检测 quantity 字段类型
        $col = $db->query("SHOW COLUMNS FROM `items` LIKE 'quantity'")->fetch();
        $currentType = $col['Type'] ?? '';
        $needsUpgrade = stripos($currentType, 'decimal') === false;

        if ($needsUpgrade) {
            $db->exec("ALTER TABLE `items` MODIFY COLUMN `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1");
            $db->exec("ALTER TABLE `transfer_logs` MODIFY COLUMN `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1");
            $success = '升级完成！quantity 字段已从 ' . $currentType . ' 改为 DECIMAL(10,2)。';
            $step = 2;
        } else {
            $upToDate = true;
            $success = '数据库已是最新版本，无需升级。';
            $step = 2;
        }
    } catch (\Throwable $e) {
        $error = '升级失败：' . $e->getMessage();
    }
}

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据库升级 · 冰箱库存管理系统</title>
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Noto Sans SC",sans-serif;background:#f1f5f9;color:#1e293b;display:flex;align-items:center;justify-content:center;min-height:100vh;line-height:1.6}
        .box{background:#fff;border-radius:12px;padding:2.5rem;box-shadow:0 4px 12px rgba(0,0,0,.08);max-width:460px;width:90%;text-align:center}
        .box h1{font-size:1.1rem;margin-bottom:.75rem}
        .box p{color:#64748b;font-size:.9rem;margin-bottom:.75rem}
        .alert{padding:.75rem 1rem;border-radius:8px;font-size:.85rem;margin-bottom:1rem}
        .alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
        .alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
        .btn{display:inline-flex;align-items:center;justify-content:center;width:100%;padding:.65rem 1rem;border:none;border-radius:8px;font-size:.95rem;font-weight:600;cursor:pointer;transition:background .2s}
        .btn-primary{background:#3b82f6;color:#fff}
        .btn-primary:hover{background:#2563eb}
        .btn-ghost{background:#f1f5f9;color:#64748b;margin-top:.5rem}
        .btn-ghost:hover{background:#e2e8f0}
        code{background:#f1f5f9;padding:.15rem .4rem;border-radius:4px;font-size:.85rem}
        a{color:#3b82f6;text-decoration:none}
        a:hover{text-decoration:underline}
        .changelog{text-align:left;font-size:.82rem;color:#64748b;margin:.75rem 0;padding:.75rem;background:#f8fafc;border-radius:8px;line-height:1.8}
    </style>
</head>
<body>
    <div class="box">
        <?php if ($step === 0): ?>
            <h1>⚠️ 无法升级</h1>
            <div class="alert alert-error"><?php echo $error; ?></div>

        <?php elseif ($step === 2): ?>
            <h1><?php echo $upToDate ? '✅ 已是最新' : '✅ 升级完成'; ?></h1>
            <div class="alert alert-success"><?php echo $success; ?></div>
            <?php if (!$upToDate): ?>
            <div class="changelog">
                <strong>本次升级内容：</strong><br>
                · <code>items.quantity</code> INT → DECIMAL(10,2)<br>
                · <code>transfer_logs.quantity</code> INT → DECIMAL(10,2)<br>
                支持小数重量（如 1.5 斤、0.8 公斤）<br>
                已有数据不受影响（3 → 3.00）
            </div>
            <?php endif; ?>
            <a class="btn btn-primary" href="index.html">进入系统 →</a>

        <?php else: ?>
            <h1>🔄 数据库升级</h1>
            <p>检测到您的数据库可能有结构更新。点击下方按钮自动检测并升级。</p>
            <div class="changelog">
                <strong>即将检查：</strong><br>
                · quantity 字段是否已升级为 DECIMAL
            </div>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo esc($error); ?></div>
            <?php endif; ?>
            <form method="POST">
                <button type="submit" class="btn btn-primary">开始升级</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
