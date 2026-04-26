<?php
// ussselbot_vk/core/AdManager.php
// Менеджер для работы с объявлениями VK

require_once __DIR__ . '/../config.php';

class AdManager {
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
            error_log("[AdManager] DB Connection Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Создание объявления
     */
    public function createAd($userId, $username, $firstname, $lastname, $phone, $text) {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO vk_ads (user_id, username, firstname, lastname, phone, text, post, moder, date, pay_type, pay_comment, pay_status)
                 VALUES (:user_id, :username, :firstname, :lastname, :phone, :text, 0, 0, NOW(), 'free', NULL, 0)"
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':username' => $username,
                ':firstname' => $firstname,
                ':lastname' => $lastname,
                ':phone' => $phone,
                ':text' => $text
            ]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("[AdManager] createAd Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Получение объявления по ID
     */
    public function getAd($adId) {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM vk_ads WHERE id = :id"
            );
            $stmt->execute([':id' => $adId]);
            $result = $stmt->fetch();
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("[AdManager] getAd Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Получение черновика объявления пользователя
     */
    public function getDraftAd($userId) {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM vk_ads WHERE user_id = :user_id AND moder = 0 AND post = 0 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([':user_id' => $userId]);
            $result = $stmt->fetch();
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("[AdManager] getDraftAd Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Обновление текста объявления
     */
    public function updateText($adId, $text) {
        try {
            $stmt = $this->db->prepare(
                "UPDATE vk_ads SET text = :text WHERE id = :id"
            );
            $stmt->execute([
                ':text' => $text,
                ':id' => $adId
            ]);
            return true;
        } catch (PDOException $e) {
            error_log("[AdManager] updateText Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Обновление типа оплаты
     */
    public function updatePayType($adId, $payType, $payComment = null) {
        try {
            $stmt = $this->db->prepare(
                "UPDATE vk_ads SET pay_type = :pay_type, pay_comment = :pay_comment WHERE id = :id"
            );
            $stmt->execute([
                ':pay_type' => $payType,
                ':pay_comment' => $payComment,
                ':id' => $adId
            ]);
            return true;
        } catch (PDOException $e) {
            error_log("[AdManager] updatePayType Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Обновление статуса оплаты
     */
    public function updatePayStatus($adId, $payStatus) {
        try {
            $stmt = $this->db->prepare(
                "UPDATE vk_ads SET pay_status = :pay_status WHERE id = :id"
            );
            $stmt->execute([
                ':pay_status' => $payStatus,
                ':id' => $adId
            ]);
            return true;
        } catch (PDOException $e) {
            error_log("[AdManager] updatePayStatus Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Утверждение объявления (модерация)
     */
    public function approveAd($adId) {
        try {
            $stmt = $this->db->prepare(
                "UPDATE vk_ads SET moder = 1 WHERE id = :id"
            );
            $stmt->execute([':id' => $adId]);
            return true;
        } catch (PDOException $e) {
            error_log("[AdManager] approveAd Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Публикация объявления
     */
    public function publishAd($adId) {
        try {
            $stmt = $this->db->prepare(
                "UPDATE vk_ads SET post = 1 WHERE id = :id"
            );
            $stmt->execute([':id' => $adId]);
            return true;
        } catch (PDOException $e) {
            error_log("[AdManager] publishAd Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Удаление объявления
     */
    public function deleteAd($adId) {
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
            error_log("[AdManager] deleteAd Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Валидация объявления
     */
    public function validateAd($adId) {
        $ad = $this->getAd($adId);
        if (!$ad) {
            return ['valid' => false, 'error' => 'Объявление не найдено'];
        }

        // Проверка текста
        if (empty($ad['text'])) {
            return ['valid' => false, 'error' => 'Текст объявления не может быть пустым'];
        }

        // Проверка длины текста
        if (mb_strlen($ad['text']) > 970) {
            return ['valid' => false, 'error' => 'Текст объявления слишком длинный (максимум 970 символов)'];
        }

        // Проверка контактов
        if (empty($ad['phone']) && empty($ad['username'])) {
            return ['valid' => false, 'error' => 'Добавьте номер телефона или username'];
        }

        return ['valid' => true];
    }

    /**
     * Получение объявлений на модерацию
     */
    public function getAdsForModeration() {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM vk_ads WHERE moder = 0 AND post = 0 ORDER BY date DESC"
            );
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("[AdManager] getAdsForModeration Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Получение объявлений в очереди
     */
    public function getAdsInQueue() {
        try {
            $stmt = $this->db->prepare(
                "SELECT a.*, q.created_at as queue_created_at FROM vk_ads a
                 INNER JOIN vk_queue q ON a.id = q.ad_id
                 WHERE q.status = 'pending'
                 ORDER BY q.created_at ASC"
            );
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("[AdManager] getAdsInQueue Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Добавление объявления в очередь
     */
    public function addToQueue($adId) {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO vk_queue (ad_id, created_at, status) VALUES (:ad_id, NOW(), 'pending')"
            );
            $stmt->execute([':ad_id' => $adId]);
            return true;
        } catch (PDOException $e) {
            error_log("[AdManager] addToQueue Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Удаление из очереди
     */
    public function removeFromQueue($adId) {
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM vk_queue WHERE ad_id = :ad_id"
            );
            $stmt->execute([':ad_id' => $adId]);
            return true;
        } catch (PDOException $e) {
            error_log("[AdManager] removeFromQueue Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Сохранение сообщения админу
     */
    public function saveAdminMessage($adId, $messageId, $command) {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO vk_admin_messages (ad_id, message_id, command, created_at)
                 VALUES (:ad_id, :message_id, :command, NOW())"
            );
            $stmt->execute([
                ':ad_id' => $adId,
                ':message_id' => $messageId,
                ':command' => $command
            ]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("[AdManager] saveAdminMessage Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Получение сообщения админу
     */
    public function getAdminMessage($adId, $command) {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM vk_admin_messages WHERE ad_id = :ad_id AND command = :command LIMIT 1"
            );
            $stmt->execute([
                ':ad_id' => $adId,
                ':command' => $command
            ]);
            $result = $stmt->fetch();
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("[AdManager] getAdminMessage Error: " . $e->getMessage());
            return null;
        }
    }
}
