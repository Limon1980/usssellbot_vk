<?php
// ussselbot_vk/core/UserManager.php
// Менеджер для работы с пользователями VK

require_once __DIR__ . '/../config.php';

class UserManager {
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
            error_log("[UserManager] DB Connection Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Проверка дневного лимита объявлений
     */
    public function checkDailyLimit($userId) {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as count FROM vk_ads
                 WHERE user_id = :user_id
                 AND (moder = 1 OR post = 1)
                 AND DATE(date) = CURDATE()"
            );
            $stmt->execute([':user_id' => $userId]);
            $result = $stmt->fetch();
            $count = $result['count'] ?? 0;

            return $count < 2;
        } catch (PDOException $e) {
            error_log("[UserManager] checkDailyLimit Error: " . $e->getMessage());
            return true; // В случае ошибки разрешаем
        }
    }

    /**
     * Получение количества объявлений за сегодня
     */
    public function getTodayAdsCount($userId) {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as count FROM vk_ads
                 WHERE user_id = :user_id
                 AND (moder = 1 OR post = 1)
                 AND DATE(date) = CURDATE()"
            );
            $stmt->execute([':user_id' => $userId]);
            $result = $stmt->fetch();
            return $result['count'] ?? 0;
        } catch (PDOException $e) {
            error_log("[UserManager] getTodayAdsCount Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Получение информации о пользователе
     */
    public function getUserInfo($userId) {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM vk_ads WHERE user_id = :user_id ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([':user_id' => $userId]);
            $result = $stmt->fetch();

            if ($result) {
                return [
                    'user_id' => $result['user_id'],
                    'username' => $result['username'],
                    'firstname' => $result['firstname'],
                    'lastname' => $result['lastname'],
                    'phone' => $result['phone']
                ];
            }

            return null;
        } catch (PDOException $e) {
            error_log("[UserManager] getUserInfo Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Сохранение информации о пользователе
     */
    public function saveUserInfo($userId, $username, $firstname, $lastname, $phone) {
        // Информация о пользователе хранится в таблице vk_ads
        // При создании объявления она сохраняется автоматически
        return true;
    }

    /**
     * Получение всех активных пользователей
     */
    public function getActiveUsers() {
        try {
            $stmt = $this->db->prepare(
                "SELECT DISTINCT user_id FROM vk_user_states WHERE state != 'idle'"
            );
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("[UserManager] getActiveUsers Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Получение объявлений пользователя
     */
    public function getUserAds($userId, $limit = 10) {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM vk_ads WHERE user_id = :user_id ORDER BY date DESC LIMIT :limit"
            );
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("[UserManager] getUserAds Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Блокировка пользователя
     */
    public function blockUser($userId) {
        // TODO: Реализовать блокировку пользователя
        return true;
    }

    /**
     * Разблокировка пользователя
     */
    public function unblockUser($userId) {
        // TODO: Реализовать разблокировку пользователя
        return true;
    }

    /**
     * Проверка заблокирован ли пользователь
     */
    public function isBlocked($userId) {
        // TODO: Реализовать проверку блокировки
        return false;
    }
}
