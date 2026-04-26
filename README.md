# VK Барахолка - Отдельный бот

## 📋 Описание

Отдельный VK бот для барахолки Уссурийска. Работает независимо от Telegram бота.

## 🎯 Особенности

- **Отдельная БД**: таблицы с префиксом `vk_`
- **Отдельный State Machine**: `vk_user_states`
- **Отдельные медиа**: `vk_ad_media`
- **Кнопки на старте**: "Предложить объявление" и "Сообщение админу"
- **Интеграция только на этапе публикации**: объявление можно опубликовать и в VK, и в Telegram

## 📁 Структура проекта

```
ussselbot_vk/
├── config.php                      # Конфигурация VK бота
├── core/
│   ├── StateManager.php           # State Machine для VK
│   └── MediaManager.php           # Медиа-менеджер для VK
├── helpers/
│   └── VKHelper.php               # VK API хелпер
├── webhooks/
│   └── vk.php                     # Webhook для VK
├── migrations/
│   └── 001_create_tables.sql      # SQL для создания таблиц
├── scripts/
│   ├── run_migration.php          # Запуск миграции
│   └── check_migration.php        # Проверка миграции
├── logs/                          # Логи (создаются автоматически)
│   ├── vk_webhook.log
│   └── error.log
└── README.md                      # Этот файл
```

## 🚀 Установка

### 1. Настройка VK Callback API

1. **Создайте группу** ВКонтакте
2. **Получите ID группы** (число, не screen_name)
3. **Создайте бота** в разделе "Управление" → "Сообщения сообщества" → "Настройки для бота"
4. **Получите Service Token** в разделе "Управление" → "Работа с API" → "Ключи доступа"

5. **Настройте Callback API**:
   - Перейдите в "Управление" → "Работа с API" → "Callback API"
   - Выберите тип событий: "Сообщения сообщества"
   - Укажите URL: `https://ussurbot.ru/ussselbot_vk/webhooks/vk.php`
   - Нажмите "Подтвердить"
   - Скопируйте **Код подтверждения**

6. **Обновите config.php**:
   ```php
   const VK_GROUP_ID = '12345678'; // ID группы (число)
   const VK_ACCESS_TOKEN = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'; // Service Token
   const VK_CONFIRMATION_CODE = 'xxxxxxxxxxxxxxxx'; // Код подтверждения
   const VK_SECRET_KEY = 'my_secret_key_123'; // Любой секретный ключ
   const VK_ADMIN_USER_ID = '123456789'; // ID админа в VK
   ```

### 2. Запуск миграции БД

```bash
php ussselbot_vk/scripts/run_migration.php
```

### 3. Проверка миграции

```bash
php ussselbot_vk/scripts/check_migration.php
```

### 4. Проверка webhook

```bash
curl -X POST https://ussurbot.ru/ussselbot_vk/webhooks/vk.php \
  -H "Content-Type: application/json" \
  -d '{"type":"confirmation","secret":"my_secret_key_123"}'
```

Должен вернуться код подтверждения.

## 🎮 Использование

### Команды бота

- `/start` - Начать работу
- `Предложить объявление` - Создать объявление
- `Сообщение админу` - Написать админу
- `Опубликовать` - Опубликовать объявление
- `Посмотреть` - Посмотреть превью
- `Удалить объявление` - Удалить объявление

### Состояния пользователя

- `idle` - Ожидание команды
- `creating_text` - Создание текста объявления
- `adding_photo` - Добавление фото
- `preview` - Просмотр объявления
- `waiting_payment` - Ожидание оплаты
- `chatting_with_admin` - Чат с админом

## 📊 Таблицы БД

### vk_ads
Объявления VK бота.

### vk_user_states
Состояния пользователей VK бота.

### vk_ad_media
Медиа объявлений VK бота.

### vk_admin_messages
Сообщения админу для редактирования inline кнопок.

### vk_queue
Очередь публикаций VK бота.

## 🔧 Конфигурация

### config.php

```php
// VK настройки
const VK_GROUP_ID = 'XXXXXXXX'; // ID группы
const VK_ACCESS_TOKEN = 'XXXXXXXX'; // Service Token
const VK_CONFIRMATION_CODE = 'XXXXXXXX'; // Код подтверждения
const VK_SECRET_KEY = 'XXXXXXXX'; // Секретный ключ
const VK_API_VERSION = '5.131';

// Админ
const VK_ADMIN_USER_ID = 'XXXXXXXX'; // ID админа

// База данных
const DB_HOST = 'localhost';
const DB_NAME = 'data';
const DB_USER = 'user1';
const DB_PASS = 'TRargo.12';

// ЮКасса (позже)
const YOOKASSA_SHOP_ID = '1192826';
const YOOKASSA_SECRET_KEY = 'live_3Pn2kS1zwJ3CWGzRlaoZI8Cq98bDxdlzmjuqg5DW7Rc';
const YOOKASSA_WEBHOOK_URL = 'https://ussurbot.ru/ussselbot_vk/webhooks/yookassa.php';
const AD_PAYMENT_AMOUNT = '20.00';

// AI API
const AI_API_KEY = 'sk-vij1SOAhUXsKr5G4qfBiFwjfBu0ispl7vzBlYtr6HgeFXyic';
```

## 🐛 Troubleshooting

### Webhook не работает

1. Проверьте, что URL доступен:
   ```bash
   curl https://ussurbot.ru/ussselbot_vk/webhooks/vk.php
   ```

2. Проверьте логи:
   ```bash
   tail -f ussselbot_vk/logs/vk_webhook.log
   ```

3. Проверьте настройки Callback API в VK

### Бот не отвечает

1. Проверьте Service Token
2. Проверьте права доступа (messages, groups, photos)
3. Проверьте логи ошибок PHP

### Миграция БД не работает

1. Проверьте подключение к БД
2. Проверьте права пользователя
3. Проверьте SQL синтаксис

## 📝 TODO

- [ ] Интегрировать AdManager
- [ ] Интегрировать UserManager
- [ ] Интегрировать PaymentManager
- [ ] Реализовать полную логику создания объявлений
- [ ] Реализовать публикацию в канал VK
- [ ] Реализовать чат с админом
- [ ] Реализовать webhook Юкассы
- [ ] Тестирование полного цикла

## 📚 Полезные ссылки

- [VK API Documentation](https://dev.vk.com/api/)
- [VK Callback API](https://dev.vk.com/api/callback/updates)
- [VK Messages API](https://dev.vk.com/api/messages)
- [VK Groups API](https://dev.vk.com/api/groups)

## ⚠️ Важно

1. **URL webhook должен быть HTTPS**
2. **Service Token нужен с правами messages, groups, photos**
3. **Код подтверждения нужно скопировать из настроек VK**
4. **Секретный ключ можно придумать любой**
5. **Telegram бот работает независимо, без изменений**

## 🎯 Следующие шаги

1. Настроить VK Callback API
2. Запустить миграцию БД
3. Протестировать webhook
4. Интегрировать AdManager
5. Реализовать полную логику создания объявлений
6. Реализовать публикацию в канал VK
