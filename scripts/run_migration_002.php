<?php
// ussselbot_vk/scripts/run_migration_002.php
// Запуск миграции 002 - добавление поля photo_url

require_once __DIR__ . '/../config.php';

echo "=== Запуск миграции 002 - добавление поля photo_url ===\n\n";

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

    // Проверяем, существует ли поле photo_url
    $check = $db->query("SHOW COLUMNS FROM vk_ad_media LIKE 'photo_url'");
    if ($check->rowCount() > 0) {
        echo "✅ Поле photo_url уже существует\n";
    } else {
        // Добавляем поле
        $db->exec("ALTER TABLE vk_ad_media ADD COLUMN photo_url TEXT NULL COMMENT 'URL фото для перезаливки' AFTER media_id");
        echo "✅ Поле photo_url добавлено\n";
    }

    echo "\n=== Миграция 002 завершена ===\n";

} catch (PDOException $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
