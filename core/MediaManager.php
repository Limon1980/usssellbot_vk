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
    public function saveMedia($adId, $mediaId, $type = 'photo') {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO vk_ad_media (ad_id, media_id, type, created_at)
                 VALUES (:ad_id, :media_id, :type, NOW())"
            );
            $stmt->execute([
                ':ad_id' => $adId,
                ':media_id' => $mediaId,
                ':type' => $type
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
     * Проверка наличия медиа определённого типа
     */
    public function hasMedia($adId, $type = 'photo') {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as count FROM vk_ad_media WHERE ad_id = :ad_id AND type = :type"
            );
            $stmt->execute([':ad_id' => $adId, ':type' => $type]);
            $result = $stmt->fetch();
            return ($result['count'] ?? 0) > 0;
        } catch (PDOException $e) {
            error_log("[MediaManager] hasMedia Error: " . $e->getMessage());
            return false;
        }
    }
}
