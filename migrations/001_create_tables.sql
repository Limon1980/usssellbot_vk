-- Миграция для VK бота
-- Создание таблицы vk_ads для объявлений VK

-- Таблица объявлений VK
CREATE TABLE IF NOT EXISTS vk_ads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL COMMENT 'ID пользователя в VK',
    username VARCHAR(255) NULL COMMENT 'Username пользователя в VK',
    firstname VARCHAR(100) NOT NULL COMMENT 'Имя пользователя',
    lastname VARCHAR(100) NOT NULL COMMENT 'Фамилия пользователя',
    phone VARCHAR(20) NULL COMMENT 'Телефон',
    text TEXT NOT NULL COMMENT 'Текст объявления',
    post INT NOT NULL DEFAULT 0 COMMENT 'Опубликовано (0/1)',
    moder INT NOT NULL DEFAULT 0 COMMENT 'Прошло модерацию (0/1)',
    date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата создания',
    pay_type ENUM('free', 'pending', 'paid', 'private', 'commerc') NOT NULL DEFAULT 'free' COMMENT 'Тип оплаты',
    pay_comment TEXT NULL COMMENT 'Комментарий к оплате',
    pay_status TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Статус оплаты (0/1)',
    INDEX idx_user_id (user_id),
    INDEX idx_moder (moder),
    INDEX idx_post (post),
    INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Объявления VK барахолки';

-- Таблица состояний пользователей VK
CREATE TABLE IF NOT EXISTS vk_user_states (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    state ENUM('idle', 'creating_text', 'adding_photo', 'preview', 'waiting_payment', 'chatting_with_admin') NOT NULL DEFAULT 'idle',
    draft_id BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user (user_id),
    INDEX idx_state (state),
    INDEX idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Состояния пользователей VK';

-- Таблица медиа VK
CREATE TABLE IF NOT EXISTS vk_ad_media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad_id BIGINT UNSIGNED NOT NULL,
    media_id VARCHAR(255) NOT NULL COMMENT 'VK media_id (photoXXX_XXX)',
    type ENUM('photo', 'video', 'document') NOT NULL DEFAULT 'photo',
    photo_url TEXT NULL COMMENT 'URL фото для перезаливки',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ad_id (ad_id),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Медиа объявлений VK';

-- Таблица сообщений админу (для редактирования inline кнопок)
CREATE TABLE IF NOT EXISTS vk_admin_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad_id BIGINT UNSIGNED NOT NULL,
    message_id INT NOT NULL COMMENT 'ID сообщения в VK',
    command VARCHAR(50) NOT NULL COMMENT 'Команда (post, delete, edit и т.д.)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ad_id (ad_id),
    INDEX idx_command (command)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Сообщения админу для редактирования';

-- Таблица очереди публикаций VK
CREATE TABLE IF NOT EXISTS vk_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ad_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    published_at TIMESTAMP NULL,
    status ENUM('pending', 'published', 'failed') NOT NULL DEFAULT 'pending',
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Очередь публикаций VK';
