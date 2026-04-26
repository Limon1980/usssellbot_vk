<?php
// ussselbot_vk/scripts/run_migration.php
// Запуск миграции БД для VK бота

require_once __DIR__ . '/../config.php';

echo "=== Запуск миграции VK бота ===\n\n";

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

    // Читаем SQL файл
    $sqlFile = __DIR__ . '/../migrations/001_create_tables.sql';
    if (!file_exists($sqlFile)) {
        die("❌ SQL файл не найден: {$sqlFile}\n");
    }

    $sql = file_get_contents($sqlFile);

    // Разделяем SQL на отдельные запросы
    $statements = explode(';', $sql);

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement)) {
            continue;
        }

        try {
            $db->exec($statement);
            echo "✅ Выполнено: " . substr($statement, 0, 50) . "...\n";
        } catch (PDOException $e) {
            echo "❌ Ошибка: " . $e->getMessage() . "\n";
            echo "   SQL: " . substr($statement, 0, 100) . "...\n";
        }
    }

    echo "\n=== Миграция завершена ===\n";
    echo "\nЗапустите проверку: php ussselbot_vk/scripts/check_migration.php\n";

} catch (PDOException $e) {
    echo "❌ Ошибка подключения к БД: " . $e->getMessage() . "\n";
    exit(1);
}
