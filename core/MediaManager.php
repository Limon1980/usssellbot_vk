<?php
// ussselbot_vk/core/MediaManager.php
// Менеджер для работы с медиа VK

require_once __DIR__ . '/../config.php';

class MediaManager {
    private $db;

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
        } catch (PDOException $e) {
            error_log("[MediaManager] DB Connection Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Сохранение медиа для объявления VK
     */
    public function saveMedia($adId, $mediaId, $type = 'photo', $photoUrl = null) {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO vk_ad_media (ad_id, media_id, type, photo_url, created_at)
                 VALUES (:ad_id, :media_id, :type, :photo_url, NOW())"
            );
            $stmt->execute([
                ':ad_id' => $adId,
                ':media_id' => $mediaId,
                ':type' => $type,
                ':photo_url' => $photoUrl
            ]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("[MediaManager] saveMedia Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Получение всех медиа для объявления
     */
    public function getMedia($adId) {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM vk_ad_media WHERE ad_id = :ad_id ORDER BY id"
            );
            $stmt->execute([':ad_id' => $adId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("[MediaManager] getMedia Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Получение media_id для формирования attachment
     */
    public function getMediaAttachments($adId) {
        $media = $this->getMedia($adId);
        $attachments = [];

        foreach ($media as $item) {
            $attachments[] = $item['media_id'];
        }

        return $attachments;
    }

    /**
     * Получение количества медиа для объявления
     */
    public function getMediaCount($adId) {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as count FROM vk_ad_media WHERE ad_id = :ad_id"
            );
            $stmt->execute([':ad_id' => $adId]);
            $result = $stmt->fetch();
            return $result['count'] ?? 0;
        } catch (PDOException $e) {
            error_log("[MediaManager] getMediaCount Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Удаление всех медиа для объявления
     */
    public function deleteMedia($adId) {
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM vk_ad_media WHERE ad_id = :ad_id"
            );
            $stmt->execute([':ad_id' => $adId]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("[MediaManager] deleteMedia Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Загрузка фото в группу VK и получение attachment
     */
    public function uploadPhotoToGroup($photoUrl) {
        try {
            error_log("[MediaManager] uploadPhotoToGroup: photoUrl=" . substr($photoUrl, 0, 100));

            // 1. Получаем upload сервер
            $uploadServer = $this->vkApi('photos.getWallUploadServer', [
                'group_id' => VK_GROUP_ID
            ]);

            if (!isset($uploadServer['upload_url'])) {
                error_log("[MediaManager] ERROR: No upload_url in response");
                throw new Exception('Не удалось получить upload server');
            }

            // 2. Скачиваем фото во временный файл
            $tempFile = sys_get_temp_dir() . '/vk_' . uniqid() . '.jpg';
            $imageData = file_get_contents($photoUrl);

            if ($imageData === false) {
                error_log("[MediaManager] ERROR: Failed to download photo");
                throw new Exception('Не удалось скачать фото');
            }

            file_put_contents($tempFile, $imageData);

            // 3. Загружаем файл в VK
            $ch = curl_init($uploadServer['upload_url']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'photo' => new CURLFile($tempFile)
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $uploadResponse = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                error_log("[MediaManager] CURL Error: " . $error);
                throw new Exception('Ошибка загрузки фото: ' . $error);
            }

            $uploadData = json_decode($uploadResponse, true);

            if (!isset($uploadData['photo'])) {
                error_log("[MediaManager] ERROR: No photo in upload response: " . json_encode($uploadData));
                throw new Exception('Ошибка загрузки фото');
            }

            // 4. Сохраняем фото
            $saveResponse = $this->vkApi('photos.saveWallPhoto', [
                'group_id' => VK_GROUP_ID,
                'photo' => $uploadData['photo'],
                'server' => $uploadData['server'],
                'hash' => $uploadData['hash']
            ]);

            if (!isset($saveResponse[0]['id'])) {
                error_log("[MediaManager] ERROR: No id in save response: " . json_encode($saveResponse));
                throw new Exception('Ошибка сохранения фото');
            }

            $photo = $saveResponse[0];

            // 5. Удаляем временный файл
            unlink($tempFile);

            // 6. Возвращаем attachment
            $attachment = 'photo' . $photo['owner_id'] . '_' . $photo['id'];
            error_log("[MediaManager] SUCCESS: attachment=" . $attachment);

            return $attachment;

        } catch (Exception $e) {
            error_log("[MediaManager] uploadPhotoToGroup Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Вызов API VK
     */
    private function vkApi($method, $params = []) {
        $params['access_token'] = VK_ACCESS_TOKEN;
        $params['v'] = VK_API_VERSION;

        $url = "https://api.vk.com/method/" . $method;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            error_log("[VK API] Error: " . json_encode($data['error']));
            return null;
        }

        return $data['response'] ?? null;
    }
}
