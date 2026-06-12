<?php
// /home/psh/git/halloween/migrate.php
// Migration script to apply schema with table prefixes.

declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Security: Only allow running if a secret key is provided
$secret = $_GET['key'] ?? '';
if (php_sapi_name() !== 'cli' && $secret !== 'migrate_me_halloween') {
    die('Unauthorized access.');
}

$sqlFile = __DIR__ . '/db.sql';
if (!file_exists($sqlFile)) {
    die('db.sql not found.');
}

try {
    $pdo = createPDO($DB_CONFIG);
    $sql = file_get_contents($sqlFile);
    
    // Replace placeholders with configured prefix
    $prefix = $DB_CONFIG['prefix'] ?? '';
    $sql = str_replace('{{PREFIX}}', $prefix, $sql);
    
    // Enable multi-query support for schema application
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    $pdo->exec($sql);
    
    echo "Migration successful. Tables created with prefix: $prefix";
    
    // Cleanup for security
    if (isset($_GET['cleanup'])) {
        @unlink(__FILE__);
        @unlink($sqlFile);
        echo " - Cleanup performed.";
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo "Migration failed: " . $e->getMessage();
    exit(1);
} catch (Exception $e) {
    http_response_code(500);
    echo "Error: " . $e->getMessage();
    exit(1);
}