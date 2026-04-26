<?php
// ussselbot_vk/core/StateManager.php
// State Machine для VK бота (отдельный от Telegram)

require_once __DIR__ . '/../config.php';

class StateManager {
    const STATE_IDLE = 'idle';
    const STATE_CREATING_TEXT = 'creating_text';
    const STATE_ADDING_PHOTO = 'adding_photo';
    const STATE_PREVIEW = 'preview';
    const STATE_WAITING_PAYMENT = 'waiting_payment';
    const STATE_CHATTING_WITH_ADMIN = 'chatting_with_admin';

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
            error_log("[StateManager] DB Connection Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Получение текущего состояния пользователя
     */
    public function getState($userId) {
        try {
            $stmt = $this->db->prepare(
                "SELECT state, draft_id FROM vk_user_states WHERE user_id = :user_id"
            );
            $stmt->execute([':user_id' => $userId]);
            $result = $stmt->fetch();

            if (!$result) {
                return ['state' => self::STATE_IDLE, 'draft_id' => null];
            }

            return [
                'state' => $result['state'],
                'draft_id' => $result['draft_id']
            ];
        } catch (PDOException $e) {
            error_log("[StateManager] getState Error: " . $e->getMessage());
            return ['state' => self::STATE_IDLE, 'draft_id' => null];
        }
    }

    /**
     * Установка состояния пользователя
     */
    public function setState($userId, $state, $draftId = null) {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO vk_user_states (user_id, state, draft_id, updated_at)
                 VALUES (:user_id, :state, :draft_id, NOW())
                 ON DUPLICATE KEY UPDATE state = :state, draft_id = :draft_id, updated_at = NOW()"
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':state' => $state,
                ':draft_id' => $draftId
            ]);
            return true;
        } catch (PDOException $e) {
            error_log("[StateManager] setState Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Переход между состояниями с валидацией
     */
    public function transition($userId, $newState, $draftId = null) {
        $currentState = $this->getState($userId);

        // Валидация переходов
        $validTransitions = [
            self::STATE_IDLE => [self::STATE_CREATING_TEXT, self::STATE_CHATTING_WITH_ADMIN],
            self::STATE_CREATING_TEXT => [self::STATE_ADDING_PHOTO, self::STATE_PREVIEW, self::STATE_IDLE],
            self::STATE_ADDING_PHOTO => [self::STATE_PREVIEW, self::STATE_ADDING_PHOTO, self::STATE_IDLE],
            self::STATE_PREVIEW => [self::STATE_WAITING_PAYMENT, self::STATE_IDLE],
            self::STATE_WAITING_PAYMENT => [self::STATE_IDLE],
            self::STATE_CHATTING_WITH_ADMIN => [self::STATE_IDLE]
        ];

        $allowedStates = $validTransitions[$currentState['state']] ?? [];

        if (!in_array($newState, $allowedStates)) {
            error_log("[StateManager] Invalid transition from {$currentState['state']} to {$newState} for user {$userId}");
            return false;
        }

        return $this->setState($userId, $newState, $draftId);
    }

    /**
     * Сброс состояния в idle
     */
    public function reset($userId) {
        return $this->setState($userId, self::STATE_IDLE, null);
    }

    /**
     * Получение всех пользователей в определённом состоянии
     */
    public function getUsersInState($state) {
        try {
            $stmt = $this->db->prepare(
                "SELECT user_id, draft_id FROM vk_user_states WHERE state = :state"
            );
            $stmt->execute([':state' => $state]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("[StateManager] getUsersInState Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Очистка устаревших состояний (старше 24 часов)
     */
    public function cleanupOldStates() {
        try {
            $stmt = $this->db->prepare(
                "UPDATE vk_user_states SET state = :idle, draft_id = NULL
                 WHERE updated_at < DATE_SUB(NOW(), INTERVAL 24 HOUR) AND state != :idle"
            );
            $stmt->execute([':idle' => self::STATE_IDLE]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("[StateManager] cleanupOldStates Error: " . $e->getMessage());
            return 0;
        }
    }
}
