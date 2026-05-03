<?php
header('Content-Type: text/html; charset=utf-8');

// Подключаем ваши конфиги, где должны быть оба токена
require_once __DIR__ . '/config.php'; 
require_once __DIR__ . '/../setting.php';

// --- ФУНКЦИЯ ВЫЗОВА API (С ВЫБОРОМ ТОКЕНА) ---
function vk_call($method, $params, $useUserToken = false) {
    // Используем пользовательский токен для альбомов, и токен группы для стены
    $params['access_token'] = $useUserToken ? VK_USER_ACCESS_TOKEN : VK_ACCESS_TOKEN;
    $params['v'] = VK_API_VERSION;
    $url = 'https://api.vk.com/method/' . $method;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

/**
 * Загрузка фотографии в альбом (используя логику MediaManager)
 */
function upload_photo_to_album($groupId, $albumId, $filePath) {
    // 1. Получаем сервер (используем USER токен)
    $serverData = vk_call('photos.getUploadServer', [
        'album_id' => $albumId,
        'group_id' => $groupId
    ], true); // true = USER TOKEN

    if (!isset($serverData['response']['upload_url'])) return null;

    // 2. Загружаем файл (поле 'file')
    $ch = curl_init($serverData['response']['upload_url']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => new CURLFile(realpath($filePath))]);
    $uploadResult = curl_exec($ch);
    curl_close($ch);

    $uploadData = json_decode($uploadResult, true);
    if (empty($uploadData['server'])) return null;

    // 3. Сохраняем в альбом (используем USER токен)
    $saveData = vk_call('photos.save', [
        'album_id'    => $albumId,
        'group_id'    => $groupId,
        'server'      => $uploadData['server'],
        'photos_list' => $uploadData['photos_list'],
        'hash'        => $uploadData['hash']
    ], true); // true = USER TOKEN

    if (!empty($saveData['response'][0]['id'])) {
        $img = $saveData['response'][0];
        // Формируем photo-123_456
        return 'photo' . $img['owner_id'] . '_' . $img['id'];
    }
    return null;
}

// --- ФУНКЦИИ ТЕКСТА ---
function get_phone($text) {
    $text = strip_tags($text);
    preg_match('/((8-|8|\+7|Тел:|Тел: |Тел\.:|Тел\.: |Тел\.|Тел\. |Тел|Тел|тел|тел\.|тел\:|тел\.: |т|т |т\.|т\. )[^Цена]?[0-9\-\ \)\(]{9,18})/is', $text, $array);
    if (isset($array[0])) {
        $arr = $array[0];
        $maspoisk = array("(", ")", "-", ",", "Тел:", "Тел", ".", ":", " ", "тел", "т", "e", "л", "Т", "к.т:", "к-т");
        $unumber = str_replace($maspoisk, '', $arr) * 1;
        if (is_numeric($unumber)) return '+7' . substr($unumber, -10);
    }
    return NULL;
}

function clean_text_for_vk($text, $phone) {
    $text = strip_tags($text, '<br>');
    $text = str_replace('➖➖➖➖➖➖➖➖', '', $text);
    $text = preg_replace('/Telegram\s*<a[^>]*>@baraholochka<\/a>/iu', '', $text);
    if ($phone) {
        $pattern = '/((8\-|8|\+7|Тел:|Тел: |Тел\.:|Тел\.: |Тел\.|Тел\. |Тел|Тел|тел|тел\.|тел\:|тел\.: )?[0-9\-\ \)\(]{10,17})/is';
        $text = preg_replace($pattern, "\nТел: ".$phone."\n", $text, 1);
    }
    $text = preg_replace('/(<br\s*\/?>\s*)+$/i', '', trim($text));
    return strip_tags(html_entity_decode($text));
}

// --- ЛОГИКА ---
if ($CONNECT) echo 'Соединение с БД установлено<br>';

$ResPrime = mysqli_query($CONNECT, "SELECT `prime_id` FROM `baraholka` WHERE `post` = 0 ORDER BY `id` ASC LIMIT 1");
$RowPrime = mysqli_fetch_assoc($ResPrime);

if (!$RowPrime) die("Новых объявлений нет.");

$prime_id = $RowPrime['prime_id'];
$Result = mysqli_query($CONNECT, "SELECT * FROM `baraholka` WHERE `post` = 0 AND `prime_id` = $prime_id");

$rows = [];
while ($r = mysqli_fetch_assoc($Result)) $rows[] = $r;

$attachments = [];
$final_text = "";

foreach ($rows as $index => $row) {
    if ($index === 0) {
        $phone = get_phone($row['text']);
        $final_text = clean_text_for_vk($row['text'], $phone);
    }

    $link = $row['link'];
    if ($link !== 'ТЕКСТ') {
        // Конвертация пути
        $local_path = (strpos($link, 'http') === 0) 
            ? str_replace('https://ussurbot.ru/', '/var/www/html/', $link) 
            : $link;
        $local_path = str_replace('//', '/', $local_path);

        if (file_exists($local_path)) {
            echo "Загрузка фото: $local_path ... ";
            // ВАЖНО: Используем USER TOKEN внутри этой функции
            $attach = upload_photo_to_album(VK_GROUP_ID, VK_ALBUM_ID, $local_path);
            if ($attach) {
                $attachments[] = $attach;
                echo "OK ($attach)<br>";
            } else {
                echo "ОШИБКА (проверьте права USER токена)<br>";
            }
        }
    }
    mysqli_query($CONNECT, "UPDATE `baraholka` SET `post` = 1 WHERE `post_id` = " . $row['post_id']);
}

// ПУБЛИКАЦИЯ
if (!empty($final_text) || !empty($attachments)) {
    // Для wall.post обычно достаточно токена группы
    $res = vk_call('wall.post', [
        'owner_id'    => "-" . VK_GROUP_ID,
        'from_group'  => 1,
        'message'     => $final_text,
        'attachments' => implode(',', $attachments),
        'guid'        => uniqid()
    ], false); // false = GROUP TOKEN

    if (isset($res['response']['post_id'])) {
        echo "✅ Успех! Пост опубликован. ID: " . $res['response']['post_id'] . "<br>";
    } else {
        echo "❌ Ошибка публикации: " . json_encode($res, JSON_UNESCAPED_UNICODE) . "<br>";
    }
}

// ОЧИСТКА
foreach ($rows as $row) {
    $link = $row['link'];
    if ($link !== 'ТЕКСТ') {
        $path_to_del = (strpos($link, 'http') === 0) ? str_replace('https://ussurbot.ru/', '/var/www/html/', $link) : $link;
        $path_to_del = str_replace('//', '/', $path_to_del);
        if (file_exists($path_to_del)) @unlink($path_to_del);
    }
}

echo "Завершено.";
?>