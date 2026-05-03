<?php
// ussselbot_vk/webhooks/vk.php
// Webhook для VK Callback API

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/StateManager.php';
require_once __DIR__ . '/../core/MediaManager.php';
require_once __DIR__ . '/../core/AdManager.php';
require_once __DIR__ . '/../core/UserManager.php';
require_once __DIR__ . '/../core/AdminManager.php';
require_once __DIR__ . '/../helpers/VKHelper.php';

// Создаём папку для логов если нет
if (!defined('LOG_DIR')) {
    define('LOG_DIR', __DIR__ . '/../logs');
}
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

// Логирование входящих запросов
$logFile  = LOG_DIR . '/vk_webhook.log';
$logEntry = date('Y-m-d H:i:s') . " | " . file_get_contents('php://input') . "\n";
file_put_contents($logFile, $logEntry, FILE_APPEND);

// Получаем данные от VK
$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    exit('Invalid JSON');
}

// Проверяем секретный ключ
if (defined('VK_SECRET_KEY') && isset($data['secret'])) {
    if ($data['secret'] !== VK_SECRET_KEY) {
        http_response_code(403);
        exit('Invalid secret');
    }
}

$type   = $data['type'] ?? '';
$object = $data['object'] ?? [];

switch ($type) {
    case 'confirmation':
        echo VK_CONFIRMATION_CODE;
        exit;

    case 'message_new':
        try {
            handleNewMessage($object);
        } catch (Exception $e) {
            error_log("[VK Webhook] Error: " . $e->getMessage());
        }
        break;

    default:
        break;
}

echo 'ok';

/**
 * Обработка нового сообщения
 */
function handleNewMessage($object) {
    $message     = $object['message'];
    $userId      = $message['from_id'];
    $peerId      = $message['peer_id'];
    $text        = $message['text'] ?? '';
    $attachments = $message['attachments'] ?? [];

    $vkHelper     = new VKHelper();
    $stateManager = new StateManager();
    $mediaManager = new MediaManager();
    $adManager    = new AdManager();
    $userManager  = new UserManager();
    $adminManager = new AdminManager();

    // Обработка команд админа
    if ($userId == VK_ADMIN_USER_ID) {
        if (preg_match('/\/(post|delete)(\d+)$/', $text, $matches)) {
            $adminManager->handleAdminCommand($text, $vkHelper);
            return;
        }
    }

    // Игнорируем сообщения не из личного чата
    if ($peerId != $userId) {
        error_log("[VK Webhook] Ignoring community message: userId={$userId}, peerId={$peerId}");
        return;
    }

    // Информация о пользователе
    $userInfo  = $vkHelper->getUserInfo($userId);
    $username  = $userInfo[0]['screen_name'] ?? '';
    $firstname = $userInfo[0]['first_name'] ?? '';
    $lastname  = $userInfo[0]['last_name'] ?? '';

    $state = $stateManager->getState($userId);

    error_log("[VK Webhook] User: {$userId}, State: {$state['state']}, Text: '{$text}', Attachments: " . count($attachments));

    switch ($state['state']) {
        case StateManager::STATE_IDLE:
            handleIdleState($userId, $text, $vkHelper, $stateManager, $userManager);
            break;

        case StateManager::STATE_CREATING_TEXT:
            handleCreatingTextState($userId, $text, $state, $stateManager, $adManager, $userManager, $vkHelper, $username, $firstname, $lastname);
            break;

        case StateManager::STATE_ADDING_PHOTO:
            handleAddingPhotoState($userId, $text, $attachments, $state, $stateManager, $adManager, $mediaManager, $vkHelper);
            break;

        case StateManager::STATE_PREVIEW:
            handlePreviewState($userId, $text, $state, $stateManager, $adManager, $mediaManager, $vkHelper);
            break;

        case StateManager::STATE_WAITING_PAYMENT:
            handleWaitingPaymentState($userId, $text, $state, $stateManager, $adManager, $vkHelper);
            break;

        case StateManager::STATE_CHATTING_WITH_ADMIN:
            handleChattingWithAdminState($userId, $text, $state, $stateManager, $vkHelper);
            break;

        default:
            $stateManager->reset($userId);
            $vkHelper->sendMessage($userId, "Что-то пошло не так. Напишите /start", [
                'keyboard' => $vkHelper->getStartKeyboard()
            ]);
            break;
    }
}

/**
 * Состояние IDLE
 */
function handleIdleState($userId, $text, $vkHelper, $stateManager, $userManager) {
    if ($text === '/start' || $text === 'Начать') {
        $vkHelper->sendMessage($userId, "Добро пожаловать в барахолку Уссурийска!\n\nЗдесь вы можете подать объявление или написать сообщение админу.\n\nВыберите действие:", [
            'keyboard' => $vkHelper->getStartKeyboard()
        ]);
        return;
    }

    if ($text === 'Предложить объявление') {
        if (!$userManager->checkDailyLimit($userId)) {
            $todayCount = $userManager->getTodayAdsCount($userId);
            $vkHelper->sendMessage($userId, "Вы уже отправили {$todayCount} объявления сегодня. Лимит — 2 объявления в день.\n\nПопробуйте завтра.", [
                'keyboard' => $vkHelper->getStartKeyboard()
            ]);
            return;
        }

        if (!$vkHelper->isGroupMember($userId)) {
            $vkHelper->sendMessage($userId, "Для публикации необходимо подписаться на группу.");
            return;
        }

        $stateManager->transition($userId, StateManager::STATE_CREATING_TEXT);

        $vkHelper->sendMessage($userId, "Шаг 1: Напишите текст объявления.\n\nПример:\nПродается кровать деревянная\nЦена 1000 руб\nТел 89991234567");
        return;
    }

    if ($text === 'Сообщение админу') {
        $stateManager->transition($userId, StateManager::STATE_CHATTING_WITH_ADMIN);
        $vkHelper->sendMessage($userId, "Напишите ваше сообщение админу.\n\nАдмин ответит вам в ближайшее время.");
        return;
    }

    $vkHelper->sendMessage($userId, "Напишите /start для начала работы.", [
        'keyboard' => $vkHelper->getStartKeyboard()
    ]);
}

/**
 * Состояние CREATING_TEXT
 */
function handleCreatingTextState($userId, $text, $state, $stateManager, $adManager, $userManager, $vkHelper, $username, $firstname, $lastname) {
    if ($text === 'Удалить объявление') {
        $stateManager->reset($userId);
        $vkHelper->sendMessage($userId, "Объявление удалено. Напишите /start", [
            'keyboard' => $vkHelper->getStartKeyboard()
        ]);
        return;
    }

    if (empty(trim($text))) {
        $vkHelper->sendMessage($userId, "Текст объявления не может быть пустым. Напишите текст.");
        return;
    }

    $phone = extractPhone($text);
    $adId  = $adManager->createAd($userId, $username, $firstname, $lastname, $phone, $text);

    if (!$adId) {
        $vkHelper->sendMessage($userId, "Ошибка при создании объявления. Попробуйте снова.");
        return;
    }

    // Переходим в ADDING_PHOTO и явно просим фото
    $stateManager->transition($userId, StateManager::STATE_ADDING_PHOTO, $adId);

    $vkHelper->sendMessage(
        $userId,
        "✅ Текст сохранён!\n\nШаг 2: Прикрепите фото к объявлению (можно несколько).\n\nКогда добавите все фото — нажмите «Далее без фото» или «Готово».",
        ['keyboard' => $vkHelper->getAddPhotoKeyboard()]
    );
}

/**
 * Состояние ADDING_PHOTO
 */
function handleAddingPhotoState($userId, $text, $attachments, $state, $stateManager, $adManager, $mediaManager, $vkHelper) {
    $adId = $state['draft_id'];

    // 1. Обработка текстовых команд
    if ($text === 'Удалить объявление') {
        $adManager->deleteAd($adId);
        $stateManager->reset($userId);
        $vkHelper->sendMessage($userId, "Объявление удалено. Напишите /start", [
            'keyboard' => $vkHelper->getStartKeyboard()
        ]);
        return;
    }

    if ($text === 'Посмотреть' || $text === 'Далее без фото' || $text === 'Готово') {
        $stateManager->setState($userId, StateManager::STATE_PREVIEW, $adId);
        showPreview($userId, $adId, $adManager, $mediaManager, $vkHelper);
        return;
    }

    if ($text === 'Добавить фото') {
        $vkHelper->sendMessage($userId, "Прикрепите фото через 📎 (скрепку). Можно несколько сразу.");
        return;
    }

    // 2. Обработка присланных вложений (ФОТО)
    if (!empty($attachments)) {
        $photoCount = 0;
        
        // ID альбома берем из конфига (мы его там прописали на Шаге 1)
        $albumId = defined('VK_ALBUM_ID') ? VK_ALBUM_ID : null;

        if (!$albumId) {
            error_log("[VK Webhook] ERROR: VK_ALBUM_ID not defined in config.php");
            $vkHelper->sendMessage($userId, "Ошибка настройки сервера (не указан ID альбома). Свяжитесь с админом.");
            return;
        }

        foreach ($attachments as $attachment) {
            if ($attachment['type'] !== 'photo') continue;

            $photo = $attachment['photo'];
            $largestSize = null;
            
            // Ищем максимальное разрешение
            $sizeOrder = ['w', 'z', 'y', 'x', 'r', 'q', 'p', 'o', 'm', 's'];
            foreach ($sizeOrder as $sizeType) {
                foreach ($photo['sizes'] as $size) {
                    if ($size['type'] === $sizeType) {
                        $largestSize = $size;
                        break 2;
                    }
                }
            }
            if (!$largestSize) $largestSize = end($photo['sizes']);

            $photoUrl = $largestSize['url'];

            // ВАЖНО: Вызываем новую версию метода с двумя параметрами
            // $photoUrl и $albumId
            $attachmentId = $mediaManager->uploadPhotoToGroup($photoUrl, $albumId);

            if ($attachmentId) {
                $mediaManager->saveMedia($adId, $attachmentId, 'photo', $photoUrl);
                $photoCount++;
            }
        }

        if ($photoCount > 0) {
            $totalPhotos = $mediaManager->getMediaCount($adId);
            $vkHelper->sendMessage(
                $userId,
                "✅ Загружено фото: {$photoCount} шт. (Всего: {$totalPhotos})\n\nМожете прислать ещё или нажмите «Посмотреть».",
                ['keyboard' => $vkHelper->getAddPhotoKeyboard()]
            );
        } else {
            $vkHelper->sendMessage($userId, "❌ Не удалось загрузить фото. Убедитесь, что это изображение.");
        }
        return;
    }

    // Если просто текст
    $vkHelper->sendMessage(
        $userId,
        "Прикрепите фото через 📎 или нажмите «Далее без фото».",
        ['keyboard' => $vkHelper->getAddPhotoKeyboard()]
    );
}

/**
 * Показ превью объявления (вынесен в отдельную функцию)
 */
function showPreview($userId, $adId, $adManager, $mediaManager, $vkHelper) {
    $ad = $adManager->getAd($adId);
    if (!$ad) {
        $vkHelper->sendMessage($userId, "Объявление не найдено.");
        return;
    }

    $adText = $ad['text'];
    if (!empty($ad['username']) && strpos($ad['text'], '@') === false) {
        $adText .= "\n\n@" . $ad['username'];
    }

    $mediaAttachments = $mediaManager->getMediaAttachments($adId);

    if (!empty($mediaAttachments)) {
        $vkHelper->sendMessage($userId, $adText, [
            'attachment' => implode(',', $mediaAttachments)
        ]);
    } else {
        $vkHelper->sendMessage($userId, $adText);
    }

    $vkHelper->sendMessage(
        $userId,
        "Шаг 3: Проверьте объявление выше.\n\nНажмите «Опубликовать» чтобы отправить на модерацию.",
        ['keyboard' => $vkHelper->getPublishKeyboard()]
    );
}

/**
 * Состояние PREVIEW
 */
function handlePreviewState($userId, $text, $state, $stateManager, $adManager, $mediaManager, $vkHelper) {
    $adId = $state['draft_id'];

    // Показать превью ещё раз
    if ($text === 'Посмотреть') {
        showPreview($userId, $adId, $adManager, $mediaManager, $vkHelper);
        return;
    }

    // Удаление
    if ($text === 'Удалить объявление') {
        $adManager->deleteAd($adId);
        $stateManager->reset($userId);
        $vkHelper->sendMessage($userId, "Объявление удалено. Напишите /start", [
            'keyboard' => $vkHelper->getStartKeyboard()
        ]);
        return;
    }

    // Вернуться к добавлению фото
    if ($text === 'Добавить фото') {
        $stateManager->setState($userId, StateManager::STATE_ADDING_PHOTO, $adId);
        $vkHelper->sendMessage($userId, "Прикрепите фото через 📎 (скрепку).", [
            'keyboard' => $vkHelper->getAddPhotoKeyboard()
        ]);
        return;
    }

    // Публикация
    if ($text === 'Опубликовать') {
        $validation = $adManager->validateAd($adId);
        if (!$validation['valid']) {
            $vkHelper->sendMessage($userId, "❌ " . $validation['error']);
            return;
        }

        $adManager->approveAd($adId);

        $adminManager = new AdminManager();
        $adminManager->sendAdToModeration($adId, $vkHelper);

        $stateManager->reset($userId);

        $vkHelper->sendMessage(
            $userId,
            "✅ Объявление отправлено на модерацию!\n\nОно будет опубликовано в ближайшее время.\n\nНапишите /start чтобы подать ещё одно.",
            ['keyboard' => $vkHelper->getStartKeyboard()]
        );
        return;
    }

    // Неизвестная команда в preview
    showPreview($userId, $adId, $adManager, $mediaManager, $vkHelper);
}

/**
 * Состояние WAITING_PAYMENT
 */
function handleWaitingPaymentState($userId, $text, $state, $stateManager, $adManager, $vkHelper) {
    $adId = $state['draft_id'];

    if ($text === 'Удалить объявление') {
        $adManager->deleteAd($adId);
        $stateManager->reset($userId);
        $vkHelper->sendMessage($userId, "Объявление удалено. Напишите /start", [
            'keyboard' => $vkHelper->getStartKeyboard()
        ]);
        return;
    }

    $ad = $adManager->getAd($adId);
    if ($ad && $ad['pay_status'] == 1) {
        $adManager->approveAd($adId);
        $stateManager->reset($userId);
        $vkHelper->sendMessage($userId, "Оплата прошла успешно! Объявление отправлено на модерацию.", [
            'keyboard' => $vkHelper->getStartKeyboard()
        ]);
        return;
    }

    $vkHelper->sendMessage($userId, "Ожидание оплаты...");
}

/**
 * Состояние CHATTING_WITH_ADMIN
 */
function handleChattingWithAdminState($userId, $text, $state, $stateManager, $vkHelper) {
    if ($text === 'Отмена' || $text === '/stop') {
        $stateManager->reset($userId);
        $vkHelper->sendMessage($userId, "Диалог завершён. Напишите /start", [
            'keyboard' => $vkHelper->getStartKeyboard()
        ]);
        return;
    }

    $vkHelper->sendMessage($userId, "Сообщение отправлено админу! (TODO)");
}

/**
 * Извлечение телефона из текста
 */
function extractPhone($text) {
    $pattern = '/((8|\+7|Тел:|Тел: |Тел\.:|Тел\.: |Тел\.|Тел\. |Тел|тел|тел\.|тел\:|тел\.: |т\.|т\. )[^Цена]?[0-9\-\ \)\(]{9,18})/is';
    preg_match($pattern, $text, $matches);

    if (isset($matches[0])) {
        $phone = preg_replace('/[^0-9]/', '', $matches[0]);
        if (strlen($phone) == 10) {
            $phone = '+7' . $phone;
        }
        return $phone;
    }

    return null;
}
