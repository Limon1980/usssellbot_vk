<?php
// ussselbot_vk/core/AdminManager.php
// Менеджер для работы с админом

require_once __DIR__ . '/../config.php';

class AdminManager {
    private $db;
    private $adminUserId;

    public function __construct() {
        try {
            $this->db = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
            $this->adminUserId = VK_ADMIN_USER_ID;
        } catch (PDOException $e) {
            error_log("[AdminManager] DB Connection Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Отправка объявления админу для модерации
     */
    public function sendAdToModeration($adId, $vkHelper) {
        $ad = $this->getAdWithMedia($adId);
        if (!$ad) {
            return false;
        }

        $text = $ad['text'];
        $username = $ad['username'] ? '@' . $ad['username'] : '';
        $userId = $ad['user_id'];

        // Формируем текст объявления
        $adText = $text;
        if (!empty($username) && strpos($text, '@') === false) {
            $adText .= "\n\n" . $username;
        }

        // Получаем медиа
        $mediaAttachments = [];
        if (!empty($ad['media'])) {
            foreach ($ad['media'] as $media) {
                $mediaAttachments[] = $media['media_id'];
            }
        }

        // Отправляем объявление админу
        if (!empty($mediaAttachments)) {
            $vkHelper->sendMessage($this->adminUserId, $adText, [
                'attachment' => implode(',', $mediaAttachments)
            ]);
        } else {
            $vkHelper->sendMessage($this->adminUserId, $adText);
        }

        // Отправляем информацию о пользователе
        $userInfo = "Объявление от пользователя ID: {$userId}";
        if (!empty($username)) {
            $userInfo .= " ({$username})";
        }
        $vkHelper->sendMessage($this->adminUserId, $userInfo);

        // Отправляем кнопки модерации
        $this->sendModerationButtons($adId, $vkHelper);

        return true;
    }

    /**
     * Отправка кнопок модерации
     */
    public function sendModerationButtons($adId, $vkHelper) {
        $buttons = [
            [
                [
                    'action' => [
                        'type' => 'text',
                        'label' => "Опубликовать /post{$adId}"
                    ],
                    'color' => 'positive'
                ]
            ],
            [
                [
                    'action' => [
                        'type' => 'text',
                        'label' => "Удалить /delete{$adId}"
                    ],
                    'color' => 'negative'
                ]
            ]
        ];

        $vkHelper->sendMessage($this->adminUserId, "Выберите действие:", [
            'keyboard' => [
                'one_time' => false,
                'buttons' => $buttons
            ]
        ]);
    }

    /**
     * Публикация объявления в канал
     */
    public function publishToChannel($adId, $vkHelper, $channelPeerId) {
        $ad = $this->getAdWithMedia($adId);
        if (!$ad) {
            return false;
        }

        $text = $ad['text'];
        $username = $ad['username'] ? '@' . $ad['username'] : '';

        // Формируем текст объявления
        $adText = $text;
        if (!empty($username) && strpos($text, '@') === false) {
            $adText .= "\n\n" . $username;
        }

        // Добавляем метку рекламы если платное
        if ($ad['pay_type'] === 'paid') {
            $adText .= "\n\n<i>Реклама</i>";
        }

        // Получаем медиа
        $mediaAttachments = [];
        if (!empty($ad['media'])) {
            foreach ($ad['media'] as $media) {
                $mediaAttachments[] = $media['media_id'];
            }
        }

        // Отправляем в канал
        if (!empty($mediaAttachments)) {
            $vkHelper->sendMessage($channelPeerId, $adText, [
                'attachment' => implode(',', $mediaAttachments)
            ]);
        } else {
            $vkHelper->sendMessage($channelPeerId, $adText);
        }

        // Обновляем статус объявления
        $this->markAsPublished($adId);

        // Уведомляем пользователя
        $vkHelper->sendMessage($ad['user_id'], "Ваше объявление опубликовано!");

        return true;
    }

    /**
     * Удаление объявления админом
     */
    public function deleteAd($adId, $vkHelper) {
        $ad = $this->getAd($adId);
        if (!$ad) {
            return false;
        }

        // Удаляем объявление
        $this->deleteAdFromDB($adId);

        // Уведомляем пользователя
        $vkHelper->sendMessage($ad['user_id'], "Ваше объявление удалено администратором.");

        // Уведомляем админа
        $vkHelper->sendMessage($this->adminUserId, "Объявление #{$adId} удалено.");

        return true;
    }

    /**
     * Получение объявления с медиа
     */
    private function getAdWithMedia($adId) {
        try {
            $stmt = $this->db->prepare(
                "SELECT a.*, GROUP_CONCAT(m.media_id) as media_ids
                 FROM vk_ads a
                 LEFT JOIN vk_ad_media m ON a.id = m.ad_id
                 WHERE a.id = :id
                 GROUP BY a.id"
            );
            $stmt->execute([':id' => $adId]);
            $result = $stmt->fetch();

            if (!$result) {
                return null;
            }

            // Получаем медиа отдельно
            $stmt = $this->db->prepare(
                "SELECT * FROM vk_ad_media WHERE ad_id = :ad_id ORDER BY id"
            );
            $stmt->execute([':ad_id' => $adId]);
            $media = $stmt->fetchAll();

            $result['media'] = $media;

            return $result;
        } catch (PDOException $e) {
            error_log("[AdminManager] getAdWithMedia Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Получение объявления
     */
    private function getAd($adId) {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM vk_ads WHERE id = :id"
            );
            $stmt->execute([':id' => $adId]);
            $result = $stmt->fetch();
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("[AdminManager] getAd Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Пометка как опубликованное
     */
    private function markAsPublished($adId) {
        try {
            $stmt = $this->db->prepare(
                "UPDATE vk_ads SET post = 1 WHERE id = :id"
            );
            $stmt->execute([':id' => $adId]);
            return true;
        } catch (PDOException $e) {
            error_log("[AdminManager] markAsPublished Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Удаление объявления из БД
     */
    private function deleteAdFromDB($adId) {
        try {
            // Удаляем медиа
            $stmt = $this->db->prepare(
                "DELETE FROM vk_ad_media WHERE ad_id = :ad_id"
            );
            $stmt->execute([':ad_id' => $adId]);

            // Удаляем из очереди
            $stmt = $this->db->prepare(
                "DELETE FROM vk_queue WHERE ad_id = :ad_id"
            );
            $stmt->execute([':ad_id' => $adId]);

            // Удаляем сообщения админу
            $stmt = $this->db->prepare(
                "DELETE FROM vk_admin_messages WHERE ad_id = :ad_id"
            );
            $stmt->execute([':ad_id' => $adId]);

            // Удаляем объявление
            $stmt = $this->db->prepare(
                "DELETE FROM vk_ads WHERE id = :id"
            );
            $stmt->execute([':id' => $adId]);

            return true;
        } catch (PDOException $e) {
            error_log("[AdminManager] deleteAdFromDB Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Обработка админских команд
     */
    public function handleAdminCommand($text, $vkHelper) {
        // Команда /post{id}
        if (preg_match('/^\/post(\d+)$/', $text, $matches)) {
            $adId = $matches[1];
            return $this->publishToChannel($adId, $vkHelper, VK_CHANNEL_PEER_ID);
        }

        // Команда /delete{id}
        if (preg_match('/^\/delete(\d+)$/', $text, $matches)) {
            $adId = $matches[1];
            return $this->deleteAd($adId, $vkHelper);
        }

        return false;
    }
}
