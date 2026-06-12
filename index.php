<?php
/**
 * index.php - Setup Wizard and Router
 */
declare(strict_types=1);

$credsFile = __DIR__ . '/db_credentials.php';
$htaccessFile = __DIR__ . '/.htaccess';

// If already configured, go to the lobby
if (file_exists($credsFile)) {
    header('Location: lobby.php');
    exit;
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = $_POST['db_host'] ?? 'localhost';
    $name = $_POST['db_name'] ?? '';
    $user = $_POST['db_user'] ?? '';
    $pass = $_POST['db_pass'] ?? '';
    $prefix = $_POST['db_prefix'] ?? 'sp_';

    if (!$name || !$user) {
        $error = 'Database name and user are required.';
    } else {
        try {
            // Test the connection
            $dsn = "mysql:host=$host;dbname=$name;charset=utf8mb4";
            new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            // 1. Generate db_credentials.php
            $credsContent = "<?php\n"
                . "// Security: Prevent direct access\n"
                . "if (basename(\$_SERVER['PHP_SELF']) === basename(__FILE__)) {\n"
                . "    header('HTTP/1.0 403 Forbidden');\n"
                . "    exit('Direct access not allowed.');\n"
                . "}\n\n"
                . "return [\n"
                . "    'host'   => " . var_export($host, true) . ",\n"
                . "    'name'   => " . var_export($name, true) . ",\n"
                . "    'user'   => " . var_export($user, true) . ",\n"
                . "    'pass'   => " . var_export($pass, true) . ",\n"
                . "    'prefix' => " . var_export($prefix, true) . ",\n"
                . "];\n";

            if (file_put_contents($credsFile, $credsContent) === false) {
                throw new Exception('Failed to write db_credentials.php. Check folder permissions.');
            }

            // 2. Generate .htaccess
            $htaccessContent = "Options -Indexes\n"
                . "<FilesMatch \"^(db_credentials\\.php|db\\.sql|deploy\\.conf|config\\.php)$\">\n"
                . "    Require all denied\n"
                . "</FilesMatch>\n";

            file_put_contents($htaccessFile, $htaccessContent);

            $success = true;
        } catch (Exception $e) {
            $error = 'Configuration failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>The Scare Path - Setup</title>
    <style>
        body { margin: 0; font-family: Inter, system-ui, sans-serif; background: #090913; color: #f5f5f7; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .setup-card { background: rgba(16, 18, 32, .94); border: 1px solid #2a2d46; border-radius: 18px; padding: 30px; width: 100%; max-width: 400px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        h1 { margin-top: 0; color: #7c3aed; }
        .field { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-size: 0.9rem; color: #a1a1aa; }
        input { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #3b3f5d; background: #13162a; color: #fff; box-sizing: border-box; }
        button { width: 100%; padding: 12px; border: none; border-radius: 8px; background: #7c3aed; color: #fff; font-weight: bold; cursor: pointer; margin-top: 10px; }
        button:hover { background: #5b21b6; }
        .error { color: #ef4444; background: rgba(239, 68, 68, 0.1); padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 0.85rem; }
        .success { color: #10b981; text-align: center; }
    </style>
</head>
<body>
    <div class="setup-card">
        <h1>Database Setup</h1>
        <?php if ($success): ?>
            <div class="success">
                <p>Configuration saved successfully!</p>
                <p>Don't forget to run the migration.</p>
                <a href="migrate.php?key=migrate_me_halloween"><button>Run Migration & Start Game</button></a>
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="field">
                    <label>DB Host</label>
                    <input type="text" name="db_host" value="localhost">
                </div>
                <div class="field">
                    <label>DB Name</label>
                    <input type="text" name="db_name" required>
                </div>
                <div class="field">
                    <label>DB User</label>
                    <input type="text" name="db_user" required>
                </div>
                <div class="field">
                    <label>DB Password</label>
                    <input type="password" name="db_pass">
                </div>
                <div class="field">
                    <label>Table Prefix</label>
                    <input type="text" name="db_prefix" value="sp_">
                </div>
                <button type="submit">Save Configuration</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>