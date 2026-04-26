<?php
// ussselbot_vk/scripts/get_channel_peer_id.php
// Скрипт для получения peer_id канала из логов webhook

require_once __DIR__ . '/../config.php';

echo "=== Получение VK_CHANNEL_PEER_ID ===\n\n";

$logFile = __DIR__ . '/../logs/vk_webhook.log';

if (!file_exists($logFile)) {
    echo "❌ Лог-файл не найден: {$logFile}\n";
    echo "   Сначала напишите сообщение в канал и проверьте логи.\n";
    exit(1);
}

echo "📂 Чтение лог-файла: {$logFile}\n\n";

// Читаем последние 100 строк из лога
$lines = array_slice(file($logFile), -100);

$peerIds = [];

foreach ($lines as $line) {
    $data = json_decode($line, true);
    if (!$data) {
        continue;
    }

    // Ищем peer_id в сообщениях
    if (isset($data['object']['message']['peer_id'])) {
        $peerId = $data['object']['message']['peer_id'];
        $fromId = $data['object']['message']['from_id'] ?? '';
        $text = $data['object']['message']['text'] ?? '';

        // Сохраняем только уникальные peer_id
        if (!in_array($peerId, $peerIds)) {
            $peerIds[] = [
                'peer_id' => $peerId,
                'from_id' => $fromId,
                'text' => substr($text, 0, 50)
            ];
        }
    }
}

if (empty($peerIds)) {
    echo "❌ Не найдено peer_id в логах.\n";
    echo "   Сначала напишите сообщение в канал и проверьте логи.\n";
    exit(1);
}

echo "📋 Найденные peer_id:\n\n";

foreach ($peerIds as $item) {
    echo "   peer_id: {$item['peer_id']}\n";
    echo "   from_id: {$item['from_id']}\n";
    echo "   text: {$item['text']}\n";
    echo "\n";
}

echo "🔧 Обновите config.php:\n\n";
echo "   const VK_CHANNEL_PEER_ID = '{$peerIds[0]['peer_id']}';\n\n";

echo "✅ Готово!\n";
