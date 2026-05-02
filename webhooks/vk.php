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
$logFile = LOG_DIR . '/vk_webhook.log';
$logEntry = date('Y-m-d H:i:s') . " | " . file_get_contents('php://input') . "\n";
file_put_contents($logFile, $logEntry, FILE_APPEND);

// Получаем данные от VK
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    exit('Invalid JSON');
}

// Проверяем секретный ключ (если настроен)
if (defined('VK_SECRET_KEY') && isset($data['secret'])) {
    if ($data['secret'] !== VK_SECRET_KEY) {
        http_response_code(403);
        exit('Invalid secret');
    }
}

// Тип события
$type = $data['type'] ?? '';
$object = $data['object'] ?? [];

// Обработка событий
switch ($type) {
    case 'confirmation':
        // Подтверждение webhook
        echo VK_CONFIRMATION_CODE;
        exit;

    case 'message_new':
        // Новое сообщение
        try {
            handleNewMessage($object);
        } catch (Exception $e) {
            error_log("[VK Webhook] Error: " . $e->getMessage());
        }
        break;

    default:
        // Другие события игнорируем
        break;
}

// Отвечаем OK
echo 'ok';

/**
 * Обработка нового сообщения
 */
function handleNewMessage($object) {
    $message = $object['message'];
    $userId = $message['from_id'];
    $peerId = $message['peer_id'];
    $text = $message['text'] ?? '';
    $attachments = $message['attachments'] ?? [];

    $vkHelper = new VKHelper();
    $stateManager = new StateManager();
    $mediaManager = new MediaManager();
    $adManager = new AdManager();
    $userManager = new UserManager();
    $adminManager = new AdminManager();

    // Проверка, что это сообщение админу
    if ($userId == VK_ADMIN_USER_ID) {
        // Обработка админских команд
        if (preg_match('/\/(post|delete)(\d+)$/', $text, $matches)) {
            $adminManager->handleAdminCommand($text, $vkHelper);
            return;
        }
    }

    // Проверка, что это не уведомление о новом сообщении в сообществе
    // Если peer_id != userId, значит это сообщение в сообществе, а не личное сообщение
    if ($peerId != $userId) {
        // Это сообщение в сообществе - игнорируем уведомления
        error_log("[VK Webhook] Ignoring community message notification: userId={$userId}, peerId={$peerId}");
        return;
    }

    // Получаем информацию о пользователе
    $userInfo = $vkHelper->getUserInfo($userId);
    $username = $userInfo[0]['screen_name'] ?? '';
    $firstname = $userInfo[0]['first_name'] ?? '';
    $lastname = $userInfo[0]['last_name'] ?? '';

    // Получаем текущее состояние
    $state = $stateManager->getState($userId);

    // Логирование
    error_log("[VK Webhook] User: {$userId}, Peer: {$peerId}, State: {$state['state']}, Text: {$text}");

    // Обработка в зависимости от состояния
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
            // Неизвестное состояние - сбрасываем в idle
            $stateManager->reset($userId);
            $vkHelper->sendMessage($userId, "Что-то пошло не так. Напишите /start", [
                'keyboard' => $vkHelper->getStartKeyboard()
            ]);
            break;
    }
}

/**
 * Обработка состояния IDLE
 */
function handleIdleState($userId, $text, $vkHelper, $stateManager, $userManager) {
    // Команда /start
    if ($text === '/start' || $text === 'Начать') {
        $vkHelper->sendMessage($userId, "Добро пожаловать в барахолку Уссурийска!\n\nЗдесь вы можете подать объявление или написать сообщение админу.\n\nВыберите действие:", [
            'keyboard' => $vkHelper->getStartKeyboard()
        ]);
        return;
    }

    // Кнопка "Предложить объявление"
    if ($text === 'Предложить объявление') {
        // Проверка дневного лимита
        if (!$userManager->checkDailyLimit($userId)) {
            $todayCount = $userManager->getTodayAdsCount($userId);
            $vkHelper->sendMessage($userId, "Вы уже отправили {$todayCount} объявления сегодня. Лимит - 2 объявления в день.\n\nПопробуйте завтра.", [
                'keyboard' => $vkHelper->getStartKeyboard()
            ]);
            return;
        }

        // Проверка подписки на группу
        if (!$vkHelper->isGroupMember($userId)) {
            $vkHelper->sendMessage($userId, "Для публикации необходимо подписаться на группу.");
            return;
        }

        // Переход в состояние создания текста
        $stateManager->transition($userId, StateManager::STATE_CREATING_TEXT);

        $vkHelper->sendMessage($userId, "Шаг 2: Напишите текст объявления.\n\nПример:\nПродается кровать деревянная\nЦена 1000 руб\nТел 89991234567");
        return;
    }

    // Кнопка "Сообщение админу"
    if ($text === 'Сообщение админу') {
        // Переход в состояние чата с админом
        $stateManager->transition($userId, StateManager::STATE_CHATTING_WITH_ADMIN);

        $vkHelper->sendMessage($userId, "Напишите ваше сообщение админу.\n\nАдмин ответит вам в ближайшее время.");
        return;
    }

    // Неизвестная команда
    $vkHelper->sendMessage($userId, "Напишите /start для начала работы.", [
        'keyboard' => $vkHelper->getStartKeyboard()
    ]);
}

/**
 * Обработка состояния CREATING_TEXT
 */
function handleCreatingTextState($userId, $text, $state, $stateManager, $adManager, $userManager, $vkHelper, $username, $firstname, $lastname) {
    // Кнопка "Удалить"
    if ($text === 'Удалить объявление') {
        $stateManager->reset($userId);
        $vkHelper->sendMessage($userId, "Объявление удалено. Напишите /start", [
            'keyboard' => $vkHelper->getStartKeyboard()
        ]);
        return;
    }

    // Извлекаем телефон из текста
    $phone = extractPhone($text);

    // Создаём объявление
    $adId = $adManager->createAd($userId, $username, $firstname, $lastname, $phone, $text);

    if (!$adId) {
        $vkHelper->sendMessage($userId, "Ошибка при создании объявления. Попробуйте снова.");
        return;
    }

    // Переход в состояние добавления фото
    $stateManager->transition($userId, StateManager::STATE_ADDING_PHOTO, $adId);

    $vkHelper->sendMessage($userId, "Текст сохранен!\n\nТеперь добавьте фото или нажмите 'Опубликовать'", [
        'keyboard' => $vkHelper->getAddPhotoKeyboard()
    ]);
}

/**
 * Обработка состояния ADDING_PHOTO
 */
function handleAddingPhotoState($userId, $text, $attachments, $state, $stateManager, $adManager, $mediaManager, $vkHelper) {
    $adId = $state['draft_id'];

    // Кнопка "Добавить фото"
    if ($text === 'Добавить фото') {
        $vkHelper->sendMessage($userId, "Прикрепите фото через скрепку (можно несколько сразу).");
        return;
    }

    // Кнопки управления (без фото)
    if (empty($attachments)) {
        if (in_array($text, ['Опубликовать', 'Посмотреть', 'Удалить объявление'])) {
            handlePreviewState($userId, $text, $state, $stateManager, $adManager, $mediaManager, $vkHelper);
            return;
        }

        $vkHelper->sendMessage($userId, "Прикрепите фото или нажмите кнопку.");
        return;
    }

    // Загрузка фото (обрабатываем все фото сразу)
    $photoCount = 0;
    foreach ($attachments as $attachment) {
        if ($attachment['type'] === 'photo') {
            $photo = $attachment['photo'];
            $sizes = $photo['sizes'];
            $largestPhoto = end($sizes);
            $photoUrl = $largestPhoto['url'];

            error_log("[VK Webhook] Processing photo: url=" . substr($photoUrl, 0, 100));

            // Перезаливаем фото в группу
            $attachmentId = $mediaManager->uploadPhotoToGroup($photoUrl);

            if ($attachmentId) {
                // Сохраняем в БД с URL
                $mediaManager->saveMedia($adId, $attachmentId, 'photo', $photoUrl);
                $photoCount++;
                error_log("[VK Webhook] Photo uploaded successfully: " . $attachmentId);
            } else {
                error_log("[VK Webhook] ERROR: Failed to upload photo to group");
            }
        }
    }

    if ($photoCount > 0) {
        $totalPhotos = $mediaManager->getMediaCount($adId);
        $vkHelper->sendMessage($userId, "Фото добавлено! (всего {$totalPhotos} шт.)\n\nДобавьте еще или нажмите 'Опубликовать'", [
            'keyboard' => $vkHelper->getAddPhotoKeyboard()
        ]);
    } else {
        $vkHelper->sendMessage($userId, "Не удалось загрузить фото. Попробуйте снова.");
    }
}

/**
 * Обработка состояния PREVIEW
 */
function handlePreviewState($userId, $text, $state, $stateManager, $adManager, $mediaManager, $vkHelper) {
    $adId = $state['draft_id'];

    // Кнопка "Посмотреть"
    if ($text === 'Посмотреть') {
        $ad = $adManager->getAd($adId);
        if (!$ad) {
            $vkHelper->sendMessage($userId, "Объявление не найдено.");
            return;
        }

        $mediaAttachments = $mediaManager->getMediaAttachments($adId);
        $adText = $ad['text'];
        if (!empty($ad['username']) && strpos($ad['text'], '@') === false) {
            $adText .= "\n\n@" . $ad['username'];
        }

        if (!empty($mediaAttachments)) {
            $vkHelper->sendMessage($userId, $adText, [
                'attachment' => implode(',', $mediaAttachments)
            ]);
        } else {
            $vkHelper->sendMessage($userId, $adText);
        }

        $vkHelper->sendMessage($userId, "6 шаг: нажмите кнопку 'Опубликовать' для отправки объявления на модерацию", [
            'keyboard' => $vkHelper->getAddPhotoKeyboard()
        ]);
        return;
    }

    // Кнопка "Удалить"
    if ($text === 'Удалить объявление') {
        $adManager->deleteAd($adId);
        $stateManager->reset($userId);
        $vkHelper->sendMessage($userId, "Объявление удалено. Напишите /start", [
            'keyboard' => $vkHelper->getStartKeyboard()
        ]);
        return;
    }

    // Кнопка "Опубликовать"
    if ($text === 'Опубликовать') {
        // Валидация
        $validation = $adManager->validateAd($adId);
        if (!$validation['valid']) {
            $vkHelper->sendMessage($userId, $validation['error']);
            return;
        }

        // Проверка оплаты
        $ad = $adManager->getAd($adId);
        if ($ad['pay_type'] === 'paid' && $ad['pay_status'] == 0) {
            // TODO: Интегрировать с PaymentManager
            $vkHelper->sendMessage($userId, "Для публикации необходимо оплатить. (TODO)");
            return;
        }

        // Утверждение объявления
        $adManager->approveAd($adId);

        // Отправка админу на модерацию
        $adminManager = new AdminManager();
        $adminManager->sendAdToModeration($adId, $vkHelper);

        // Сброс состояния
        $stateManager->reset($userId);

        $vkHelper->sendMessage($userId, "Объявление отправлено на модерацию и скоро будет опубликовано.\n\nЗапустите бота вновь командой /start", [
            'keyboard' => $vkHelper->getStartKeyboard()
        ]);
        return;
    }
}

/**
 * Обработка состояния WAITING_PAYMENT
 */
function handleWaitingPaymentState($userId, $text, $state, $stateManager, $adManager, $vkHelper) {
    $adId = $state['draft_id'];

    // Кнопка "Удалить"
    if ($text === 'Удалить объявление') {
        $adManager->deleteAd($adId);
        $stateManager->reset($userId);
        $vkHelper->sendMessage($userId, "Объявление удалено. Напишите /start", [
            'keyboard' => $vkHelper->getStartKeyboard()
        ]);
        return;
    }

    // Проверка оплаты
    $ad = $adManager->getAd($adId);
    if ($ad['pay_status'] == 1) {
        // Оплата прошла - публикуем
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
 * Обработка состояния CHATTING_WITH_ADMIN
 */
function handleChattingWithAdminState($userId, $text, $state, $stateManager, $vkHelper) {
    // Кнопка "Отмена"
    if ($text === 'Отмена' || $text === '/stop') {
        $stateManager->reset($userId);
        $vkHelper->sendMessage($userId, "Диалог завершён. Напишите /start", [
            'keyboard' => $vkHelper->getStartKeyboard()
        ]);
        return;
    }

    // Отправка сообщения админу (TODO)
    // TODO: Интегрировать с AdminManager
    $vkHelper->sendMessage($userId, "Сообщение отправлено админу! (TODO)");
}

/**
 * Извлечение телефона из текста
 */
function extractPhone($text) {
    // Регулярное выражение для поиска номеров телефонов
    $pattern = '/((8|\+7|Тел:|Тел: |Тел\.:|Тел\.: |Тел\.|Тел\. |Тел|Тел|тел|тел\.|тел\:|тел\.: |т|т |т\.|т\. )[^Цена]?[0-9\-\ \)\(]{9,18})/is';
    preg_match($pattern, $text, $matches);

    if (isset($matches[0])) {
        $phone = $matches[0];
        // Удаляем лишние символы
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Добавляем +7 если нужно
        if (strlen($phone) == 10) {
            $phone = '+7' . $phone;
        }

        return $phone;
    }

    return null;
}
