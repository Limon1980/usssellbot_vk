<?php
// ussselbot_vk/scripts/check_migration.php
// Проверка миграции БД для VK бота

require_once __DIR__ . '/../config.php';

echo "=== Проверка миграции VK бота ===\n\n";

try {
    $db = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    // Проверка таблиц
    $tables = [
        'vk_ads',
        'vk_user_states',
        'vk_ad_media',
        'vk_admin_messages',
        'vk_queue'
    ];

    echo "1. Проверка таблиц:\n";
    foreach ($tables as $table) {
        $result = $db->query("SHOW TABLES LIKE '{$table}'");
        if ($result->rowCount() > 0) {
            $count = $db->query("SELECT COUNT(*) as count FROM {$table}")->fetch();
            echo "   ✅ {$table}: " . $count['count'] . " записей\n";
        } else {
            echo "   ❌ {$table}: не существует\n";
        }
    }

    echo "\n";

    // Проверка структуры таблицы vk_ads
    echo "2. Структура таблицы vk_ads:\n";
    $columns = $db->query("SHOW COLUMNS FROM vk_ads")->fetchAll();
    foreach ($columns as $column) {
        echo "   - {$column['Field']}: {$column['Type']}\n";
    }

    echo "\n";

    // Проверка констант
    echo "3. Проверка констант:\n";
    echo "   VK_GROUP_ID: " . (defined('VK_GROUP_ID') ? VK_GROUP_ID : 'NOT DEFINED') . "\n";
    echo "   VK_ACCESS_TOKEN: " . (defined('VK_ACCESS_TOKEN') ? (strlen(VK_ACCESS_TOKEN) > 20 ? 'SET (' . strlen(VK_ACCESS_TOKEN) . ' chars)' : 'TOO SHORT') : 'NOT DEFINED') . "\n";
    echo "   VK_CONFIRMATION_CODE: " . (defined('VK_CONFIRMATION_CODE') ? VK_CONFIRMATION_CODE : 'NOT DEFINED') . "\n";
    echo "   VK_SECRET_KEY: " . (defined('VK_SECRET_KEY') ? VK_SECRET_KEY : 'NOT DEFINED') . "\n";
    echo "   VK_API_VERSION: " . (defined('VK_API_VERSION') ? VK_API_VERSION : 'NOT DEFINED') . "\n";
    echo "   VK_ADMIN_USER_ID: " . (defined('VK_ADMIN_USER_ID') ? VK_ADMIN_USER_ID : 'NOT DEFINED') . "\n";

    echo "\n";

    // Проверка файлов
    echo "4. Проверка файлов:\n";
    $files = [
        'core/StateManager.php',
        'core/MediaManager.php',
        'helpers/VKHelper.php',
        'webhooks/vk.php',
        'config.php'
    ];

    foreach ($files as $file) {
        $path = __DIR__ . '/../' . $file;
        if (file_exists($path)) {
            echo "   ✅ {$file}\n";
        } else {
            echo "   ❌ {$file} - не существует\n";
        }
    }

    echo "\n";
    echo "=== Проверка завершена ===\n";

} catch (PDOException $e) {
    echo "❌ Ошибка подключения к БД: " . $e->getMessage() . "\n";
    exit(1);
}
