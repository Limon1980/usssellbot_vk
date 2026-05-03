<?php
// ussselbot_vk/core/MediaManager.php

require_once __DIR__ . '/../config.php';

class MediaManager {
    private $db;

    public function __construct() {
        try {
            $this->db = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            error_log("[MediaManager] DB Error: " . $e->getMessage());
        }
    }

    public function saveMedia($adId, $mediaId, $type = 'photo', $photoUrl = null) {
        $stmt = $this->db->prepare("INSERT INTO vk_ad_media (ad_id, media_id, type, photo_url, created_at) VALUES (?, ?, ?, ?, NOW())");
        return $stmt->execute([$adId, $mediaId, $type, $photoUrl]);
    }

    public function getMediaAttachments($adId) {
        $stmt = $this->db->prepare("SELECT media_id FROM vk_ad_media WHERE ad_id = ?");
        $stmt->execute([$adId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getMediaCount($adId) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM vk_ad_media WHERE ad_id = ?");
        $stmt->execute([$adId]);
        return $stmt->fetchColumn();
    }

    /**
     * Загрузка фото по логике вашей рабочей библиотеки
     */
    public function uploadPhotoToGroup($photoUrl, $albumId) {
        $tempFile = __DIR__ . '/../uploads/tmp_' . uniqid() . '.jpg';
        
        // 1. Скачиваем файл
        $img = file_get_contents($photoUrl);
        if (!$img) {
            error_log("[MediaManager] Failed to download image: $photoUrl");
            return null;
        }
        file_put_contents($tempFile, $img);

        try {
            // 2. Получаем сервер для загрузки (photos.getUploadServer)
            $serverData = $this->vkApi('photos.getUploadServer', [
                'album_id' => $albumId,
                'group_id' => VK_GROUP_ID
            ], true);

            if (!$serverData || empty($serverData['upload_url'])) {
                error_log("[MediaManager] No upload URL: " . json_encode($serverData));
                return null;
            }

            // 3. Загружаем файл на сервер ВК (поле 'file')
            $ch = curl_init($serverData['upload_url']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => new CURLFile($tempFile)]);
            $uploadResult = curl_exec($ch);
            curl_close($ch);

            $uploadData = json_decode($uploadResult, true);
            if (empty($uploadData['server'])) {
                error_log("[MediaManager] Upload failed: " . $uploadResult);
                return null;
            }

            // 4. Сохраняем фото в альбом (photos.save)
            $saveData = $this->vkApi('photos.save', [
                'album_id'    => $albumId,
                'group_id'    => VK_GROUP_ID,
                'server'      => $uploadData['server'],
                'photos_list' => $uploadData['photos_list'],
                'hash'        => $uploadData['hash']
            ], true);

            if (!empty($saveData[0]['id'])) {
                // ВАЖНО: Формируем строку как photo-GROUPID_PHOTOID
                // Обратите внимание: owner_id от ВК уже содержит минус для групп
                $photoId = 'photo' . $saveData[0]['owner_id'] . '_' . $saveData[0]['id'];
                error_log("[MediaManager] Success! Photo ID: " . $photoId);
                return $photoId;
            }

            error_log("[MediaManager] Save failed: " . json_encode($saveData));
            return null;

        } finally {
            if (file_exists($tempFile)) unlink($tempFile);
        }
    }

    private function vkApi($method, $params = [], $useUserToken = false) {
        $params['access_token'] = $useUserToken ? VK_USER_ACCESS_TOKEN : VK_ACCESS_TOKEN;
        $params['v'] = VK_API_VERSION;

        $ch = curl_init("https://api.vk.com/method/" . $method);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        return $data['response'] ?? $data;
    }
}