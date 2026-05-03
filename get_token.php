<?php
$client_id = '54575692';
$redirect_uri = 'https://ussurbot.ru/usssellbot_vk/get_token.php';
$scope = 'photos,wall,groups,offline';

if (isset($_GET['access_token'])) {
    echo "<h2>Ваш токен:</h2>";
    echo "<textarea rows='3' cols='80'>" . htmlspecialchars($_GET['access_token']) . "</textarea>";
    echo "<br>Скопируйте токен выше";
    exit;
}

if (isset($_GET['error'])) {
    echo "Ошибка: " . htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
    exit;
}

// Страница с кнопкой и JS для извлечения токена из hash
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Получение токена VK</title></head>
<body>
<script>
// Токен приходит в hash (#access_token=...)
if (window.location.hash) {
    var params = {};
    window.location.hash.substring(1).split('&').forEach(function(part) {
        var item = part.split('=');
        params[item[0]] = decodeURIComponent(item[1]);
    });
    if (params.access_token) {
        document.write('<h2>Ваш токен:</h2><textarea rows="3" cols="80">' + params.access_token + '</textarea><br>Скопируйте токен выше и вставьте в config.php как VK_USER_ACCESS_TOKEN');
    }
} else {
    var url = 'https://oauth.vk.com/authorize?client_id=<?= $client_id ?>&display=page&redirect_uri=<?= urlencode($redirect_uri) ?>&scope=<?= $scope ?>&response_type=token&v=5.199';
    document.write('<a href="' + url + '"><button style="font-size:20px;padding:20px">Получить токен VK</button></a>');
}
</script>
</body>
</html>
