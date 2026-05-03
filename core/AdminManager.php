<?php
// ussselbot_vk/core/AdminManager.php

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

    public function sendAdToModeration($adId, $vkHelper) {
        $ad = $this->getAdWithMedia($adId);
        if (!$ad) return false;

        $adText = $ad['text'];
        if (!empty($ad['username']) && strpos($adText, '@') === false) {
            $adText .= "\n\n@" . $ad['username'];
        }

        $mediaAttachments = [];
        if (!empty($ad['media'])) {
            foreach ($ad['media'] as $media) {
                $mediaAttachments[] = $media['media_id'];
            }
        }

        if (!empty($mediaAttachments)) {
            $vkHelper->sendMessage($this->adminUserId, $adText, [
                'attachment' => implode(',', $mediaAttachments)
            ]);
        } else {
            $vkHelper->sendMessage($this->adminUserId, $adText);
        }

        $userInfo = "Объявление #{$adId} от пользователя ID: {$ad['user_id']}";
        if (!empty($ad['username'])) {
            $userInfo .= " (@{$ad['username']})";
        }
        $vkHelper->sendMessage($this->adminUserId, $userInfo);

        $this->sendModerationButtons($adId, $vkHelper);
        return true;
    }

    public function sendModerationButtons($adId, $vkHelper) {
        $buttons = [
            [
                [
                    'action' => ['type' => 'text', 'label' => "Опубликовать /post{$adId}"],
                    'color'  => 'positive'
                ]
            ],
            [
                [
                    'action' => ['type' => 'text', 'label' => "Удалить /delete{$adId}"],
                    'color'  => 'negative'
                ]
            ]
        ];

        $vkHelper->sendMessage($this->adminUserId, "Выберите действие:", [
            'keyboard' => ['one_time' => false, 'buttons' => $buttons]
        ]);
    }

    public function publishToWall($adId, $vkHelper, $groupId) {
        error_log("[AdminManager] publishToWall: adId={$adId}, groupId={$groupId}");

        $ad = $this->getAdWithMedia($adId);
        if (!$ad) {
            error_log("[AdminManager] ERROR: Ad #{$adId} not found");
            return false;
        }

        $adText = $ad['text'];
        if (!empty($ad['username']) && strpos($adText, '@') === false) {
            $adText .= "\n\n@" . $ad['username'];
        }

        // Собираем вложения из БД
        $wallAttachments = [];
        if (!empty($ad['media'])) {
            foreach ($ad['media'] as $media) {
                $mediaId = $media['media_id'];
                // Нормализуем: photo-238306571_123 → photo-238306571_123 (уже правильно)
                // Убеждаемся что формат корректный
                if (strpos($mediaId, 'photo') === 0) {
                    $wallAttachments[] = $mediaId;
                    error_log("[AdminManager] Adding attachment: {$mediaId}");
                }
            }
        }

        error_log("[AdminManager] Publishing: text=" . mb_substr($adText, 0, 50) . ", attachments=" . implode(',', $wallAttachments));

        // Параметры для wall.post
        $params = [
            'owner_id'   => '-' . $groupId,
            'message'    => $adText,
            'from_group' => 1,
            'signed'     => 0
        ];

        if (!empty($wallAttachments)) {
            $params['attachments'] = implode(',', $wallAttachments);
        }

        error_log("[AdminManager] wall.post params: " . json_encode($params));

        // Вызываем wall.post напрямую
        $result = $this->vkApi('wall.post', $params);

        error_log("[AdminManager] wall.post result: " . json_encode($result));

        if (!empty($result['post_id'])) {
            $this->markAsPublished($adId);
            $vkHelper->sendMessage($ad['user_id'], "✅ Ваше объявление опубликовано на стене сообщества!");
            return true;
        }

        error_log("[AdminManager] ERROR: wall.post failed");
        return false;
    }

    public function deleteAd($adId, $vkHelper) {
        $ad = $this->getAd($adId);
        if (!$ad) return false;

        $this->deleteAdFromDB($adId);
        $vkHelper->sendMessage($ad['user_id'], "Ваше объявление удалено администратором.");
        $vkHelper->sendMessage($this->adminUserId, "Объявление #{$adId} удалено.");
        return true;
    }

    private function getAdWithMedia($adId) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM vk_ads WHERE id = :id");
            $stmt->execute([':id' => $adId]);
            $result = $stmt->fetch();
            if (!$result) return null;

            $stmt = $this->db->prepare("SELECT * FROM vk_ad_media WHERE ad_id = :ad_id ORDER BY id");
            $stmt->execute([':ad_id' => $adId]);
            $result['media'] = $stmt->fetchAll();

            return $result;
        } catch (PDOException $e) {
            error_log("[AdminManager] getAdWithMedia Error: " . $e->getMessage());
            return null;
        }
    }

    private function getAd($adId) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM vk_ads WHERE id = :id");
            $stmt->execute([':id' => $adId]);
            $result = $stmt->fetch();
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("[AdminManager] getAd Error: " . $e->getMessage());
            return null;
        }
    }

    private function markAsPublished($adId) {
        try {
            $stmt = $this->db->prepare("UPDATE vk_ads SET post = 1 WHERE id = :id");
            $stmt->execute([':id' => $adId]);
            return true;
        } catch (PDOException $e) {
            error_log("[AdminManager] markAsPublished Error: " . $e->getMessage());
            return false;
        }
    }

    private function deleteAdFromDB($adId) {
        try {
            $this->db->prepare("DELETE FROM vk_ad_media WHERE ad_id = :id")->execute([':id' => $adId]);
            $this->db->prepare("DELETE FROM vk_queue WHERE ad_id = :id")->execute([':id' => $adId]);
            $this->db->prepare("DELETE FROM vk_admin_messages WHERE ad_id = :id")->execute([':id' => $adId]);
            $this->db->prepare("DELETE FROM vk_ads WHERE id = :id")->execute([':id' => $adId]);
            return true;
        } catch (PDOException $e) {
            error_log("[AdminManager] deleteAdFromDB Error: " . $e->getMessage());
            return false;
        }
    }

    public function handleAdminCommand($text, $vkHelper) {
        if (preg_match('/\/post(\d+)$/', $text, $matches)) {
            $adId   = $matches[1];
            $result = $this->publishToWall($adId, $vkHelper, VK_GROUP_ID);
            if ($result) {
                $vkHelper->sendMessage($this->adminUserId, "✅ Объявление #{$adId} опубликовано!");
            } else {
                $vkHelper->sendMessage($this->adminUserId, "❌ Ошибка публикации объявления #{$adId}");
            }
            return true;
        }

        if (preg_match('/\/delete(\d+)$/', $text, $matches)) {
            $adId   = $matches[1];
            $result = $this->deleteAd($adId, $vkHelper);
            if ($result) {
                $vkHelper->sendMessage($this->adminUserId, "✅ Объявление #{$adId} удалено!");
            } else {
                $vkHelper->sendMessage($this->adminUserId, "❌ Ошибка удаления объявления #{$adId}");
            }
            return true;
        }

        return false;
    }

    private function vkApi($method, $params = []) {
        $params['access_token'] = VK_ACCESS_TOKEN;
        $params['v']            = VK_API_VERSION;

        $ch = curl_init("https://api.vk.com/method/" . $method);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("[AdminManager] vkApi curl error [{$method}]: " . $curlError);
            return null;
        }

        $data = json_decode($response, true);
        if (isset($data['error'])) {
            error_log("[AdminManager] vkApi error [{$method}]: " . json_encode($data['error']));
            return null;
        }

        return $data['response'] ?? null;
    }
}
