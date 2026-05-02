<?php
// ussselbot_vk/helpers/VKHelper.php
// Хелпер для работы с VK API

require_once __DIR__ . '/../config.php';

class VKHelper {
    private $accessToken;
    private $groupId;
    private $apiVersion;
    private $adminUserId;

    public function __construct() {
        $this->accessToken = VK_ACCESS_TOKEN;
        $this->groupId = VK_GROUP_ID;
        $this->apiVersion = VK_API_VERSION;
        $this->adminUserId = VK_ADMIN_USER_ID;
    }

    /**
     * Отправка сообщения пользователю
     */
    public function sendMessage($peerId, $text, $params = []) {
        $defaultParams = [
            'peer_id' => $peerId,
            'message' => $text,
            'random_id' => $this->generateRandomId()
        ];

        if (isset($params['keyboard'])) {
            $defaultParams['keyboard'] = json_encode($params['keyboard']);
        }

        if (isset($params['attachment'])) {
            $defaultParams['attachment'] = $params['attachment'];
        }

        return $this->call('messages.send', $defaultParams);
    }

    /**
     * Отправка фото
     */
    public function sendPhoto($peerId, $photoUrl, $text = '', $params = []) {
        // Сначала загружаем фото на сервер VK
        $uploadServer = $this->getMessagesUploadServer($peerId);
        $uploadedPhoto = $this->uploadPhoto($uploadServer['upload_url'], $photoUrl);
        $savedPhoto = $this->saveMessagesPhoto($uploadedPhoto);

        // Формируем attachment
        $attachment = "photo{$savedPhoto[0]['owner_id']}_{$savedPhoto[0]['id']}";

        // Отправляем сообщение с фото
        $defaultParams = [
            'peer_id' => $peerId,
            'attachment' => $attachment,
            'random_id' => $this->generateRandomId()
        ];

        if (!empty($text)) {
            $defaultParams['message'] = $text;
        }

        if (isset($params['keyboard'])) {
            $defaultParams['keyboard'] = json_encode($params['keyboard']);
        }

        return $this->call('messages.send', $defaultParams);
    }

    /**
     * Отправка нескольких фото
     */
    public function sendPhotos($peerId, $photoUrls, $text = '', $params = []) {
        if (empty($photoUrls)) {
            return $this->sendMessage($peerId, $text, $params);
        }

        $attachments = [];

        foreach ($photoUrls as $photoUrl) {
            $uploadServer = $this->getMessagesUploadServer($peerId);
            $uploadedPhoto = $this->uploadPhoto($uploadServer['upload_url'], $photoUrl);
            $savedPhoto = $this->saveMessagesPhoto($uploadedPhoto);
            $attachments[] = "photo{$savedPhoto[0]['owner_id']}_{$savedPhoto[0]['id']}";
        }

        $defaultParams = [
            'peer_id' => $peerId,
            'attachment' => implode(',', $attachments),
            'random_id' => $this->generateRandomId()
        ];

        if (!empty($text)) {
            $defaultParams['message'] = $text;
        }

        if (isset($params['keyboard'])) {
            $defaultParams['keyboard'] = json_encode($params['keyboard']);
        }

        return $this->call('messages.send', $defaultParams);
    }

    /**
     * Проверка подписки на группу
     */
    public function isGroupMember($userId, $groupId = null) {
        $groupId = $groupId ?: $this->groupId;

        $result = $this->call('groups.isMember', [
            'group_id' => $groupId,
            'user_id' => $userId
        ]);

        return $result === 1;
    }

    /**
     * Получение информации о пользователе
     */
    public function getUserInfo($userId) {
        return $this->call('users.get', [
            'user_ids' => $userId,
            'fields' => 'first_name,last_name,screen_name'
        ]);
    }

    /**
     * Получение URL для загрузки фото на стену
     */
    public function getWallUploadServer($groupId) {
        return $this->call('photos.getWallUploadServer', [
            'group_id' => $groupId
        ]);
    }

    /**
     * Загрузка фото на сервер VK для стены
     */
    public function uploadWallPhoto($uploadUrl, $photoPath) {
        $ch = curl_init($uploadUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'photo' => new CURLFile($photoPath)
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    /**
     * Сохранение фото для стены
     */
    public function saveWallPhoto($uploadedPhoto, $groupId) {
        return $this->call('photos.saveWallPhoto', [
            'photo' => $uploadedPhoto['photo'],
            'server' => $uploadedPhoto['server'],
            'hash' => $uploadedPhoto['hash'],
            'group_id' => $groupId
        ]);
    }

    /**
     * Публикация поста на стену
     */
    public function wallPost($groupId, $text, $attachments = []) {
        $params = [
            'owner_id' => -$groupId,
            'message' => $text,
            'from_group' => 1,
            'signed' => 0
        ];

        if (!empty($attachments)) {
            $params['attachments'] = implode(',', $attachments);
        }

        return $this->call('wall.post', $params);
    }

    /**
     * Получение клавиатуры старта (с кнопками "Сообщение админу" и "Предложить объявление")
     */
    public function getStartKeyboard() {
        return [
            'one_time' => false,
            'buttons' => [
                [
                    [
                        'action' => [
                            'type' => 'text',
                            'label' => 'Предложить объявление'
                        ],
                        'color' => 'primary'
                    ],
                    [
                        'action' => [
                            'type' => 'text',
                            'label' => 'Сообщение админу'
                        ],
                        'color' => 'default'
                    ]
                ]
            ]
        ];
    }

    /**
     * Получение клавиатуры добавления фото
     */
    public function getAddPhotoKeyboard() {
        return [
            'one_time' => false,
            'buttons' => [
                [
                    [
                        'action' => [
                            'type' => 'text',
                            'label' => 'Опубликовать'
                        ],
                        'color' => 'positive'
                    ],
                    [
                        'action' => [
                            'type' => 'text',
                            'label' => 'Посмотреть'
                        ],
                        'color' => 'default'
                    ]
                ],
                [
                    [
                        'action' => [
                            'type' => 'text',
                            'label' => 'Добавить фото'
                        ],
                        'color' => 'primary'
                    ]
                ],
                [
                    [
                        'action' => [
                            'type' => 'text',
                            'label' => 'Удалить объявление'
                        ],
                        'color' => 'negative'
                    ]
                ]
            ]
        ];
    }

    /**
     * Получение клавиатуры оплаты
     */
    public function getPaymentKeyboard($paymentUrl, $adId) {
        return [
            'one_time' => false,
            'buttons' => [
                [
                    [
                        'action' => [
                            'type' => 'open_link',
                            'link' => $paymentUrl,
                            'label' => 'Оплатить 20 ₽'
                        ],
                        'color' => 'positive'
                    ]
                ],
                [
                    [
                        'action' => [
                            'type' => 'text',
                            'label' => 'Удалить объявление'
                        ],
                        'color' => 'negative'
                    ]
                ]
            ]
        ];
    }

    /**
     * Получение клавиатуры без кнопок (удаление)
     */
    public function getEmptyKeyboard() {
        return [
            'one_time' => false,
            'buttons' => []
        ];
    }

    /**
     * Генерация случайного ID для сообщений
     */
    private function generateRandomId() {
        return rand(0, 2147483647);
    }

    /**
     * Вызов API VK
     */
    private function call($method, $params = []) {
        $url = "https://api.vk.com/method/{$method}";

        $params['access_token'] = $this->accessToken;
        $params['v'] = $this->apiVersion;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("[VKHelper] CURL Error: {$error}");
            return null;
        }

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            error_log("[VKHelper] API Error: " . json_encode($data['error']));
            return null;
        }

        return $data['response'] ?? null;
    }
}
