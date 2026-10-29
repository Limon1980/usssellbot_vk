<?php
/**
 * Пример конфигурационного файла VK бота Барахолки.
 * Скопируйте этот файл в config.php на боевом сервере и заполните реальными данными.
 */

// Параметры подключения к базе данных MySQL (боевой сервер)
define('DB_HOST', 'localhost');
define('DB_NAME', 'usssellbot_vk');
define('DB_USER', 'db_user');
define('DB_PASS', 'db_password');

// Параметры VK API
define('VK_GROUP_ID', '123456789'); // ID группы ВКонтакте (только цифры)
define('VK_ACCESS_TOKEN', 'vk1.a.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'); // Ключ доступа сообщества
define('VK_USER_ACCESS_TOKEN', ''); // Пользовательский токен (при необходимости работы от имени пользователя)
define('VK_CONFIRMATION_CODE', 'abcdef12'); // Строка, которую бот должен вернуть при запросе типа "confirmation"
define('VK_SECRET_KEY', 'your_secret_callback_key'); // Секретный ключ Callback API
define('VK_ADMIN_USER_ID', '123456789'); // ID администратора бота в VK
define('VK_API_VERSION', '5.199'); // Версия API ВКонтакте
