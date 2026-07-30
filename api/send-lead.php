<?php
/**
 * Приём заявок с формы на probuyer24.ru и отправка их в Telegram менеджеру.
 *
 * НАСТРОЙКА ПЕРЕД ИСПОЛЬЗОВАНИЕМ:
 * 1) Создайте отдельного бота через @BotFather в Telegram (/newbot) и получите токен.
 * 2) Отправьте боту любое сообщение (/start) от аккаунта, который должен получать заявки.
 * 3) Откройте https://api.telegram.org/bot<ВАШ_ТОКЕН>/getUpdates и найдите "chat":{"id": ...}
 * 4) Вставьте токен и chat_id ниже вместо заглушек и загрузите файл на хостинг.
 *
 * Токен и chat_id никуда, кроме этого файла на вашем сервере, вводить не нужно.
 */

define('TELEGRAM_BOT_TOKEN', 'ВСТАВЬТЕ_ТОКЕН_БОТА_СЮДА');
define('TELEGRAM_CHAT_ID',   'ВСТАВЬТЕ_CHAT_ID_СЮДА');

header('Content-Type: application/json; charset=utf-8');

// Разрешаем запросы только с собственного сайта
$allowedOrigins = ['https://probuyer24.ru', 'https://www.probuyer24.ru'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

// Читаем JSON-тело запроса
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST; // на случай обычной отправки формы без JS
}

// Honeypot: скрытое поле, которое настоящие люди не заполняют, а боты — заполняют
if (!empty($data['website'])) {
    // Тихо "успешно" отвечаем, чтобы бот не понял, что его отфильтровали
    echo json_encode(['success' => true]);
    exit;
}

function clean($value) {
    $value = is_string($value) ? trim($value) : '';
    $value = strip_tags($value);
    return mb_substr($value, 0, 500);
}

$name      = clean($data['name'] ?? '');
$phone     = clean($data['phone'] ?? '');
$direction = clean($data['direction'] ?? '');
$promo     = clean($data['promo'] ?? '');
$page      = clean($data['page'] ?? '');

if ($name === '' || $phone === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_fields']);
    exit;
}

// Простая валидация телефона: должно остаться 10+ цифр
$digits = preg_replace('/\D/', '', $phone);
if (mb_strlen($digits) < 10) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_phone']);
    exit;
}

if (TELEGRAM_BOT_TOKEN === 'ВСТАВЬТЕ_ТОКЕН_БОТА_СЮДА' || TELEGRAM_CHAT_ID === 'ВСТАВЬТЕ_CHAT_ID_СЮДА') {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'not_configured']);
    exit;
}

$text = "🆕 Новая заявка с сайта PRO BAYER\n\n"
      . "👤 Имя: {$name}\n"
      . "📞 Телефон: {$phone}\n"
      . ($direction !== '' ? "📦 Направление: {$direction}\n" : '')
      . ($promo !== '' ? "🎁 Промокод: {$promo}\n" : '')
      . ($page !== '' ? "🔗 Страница: {$page}\n" : '')
      . "🕒 " . date('d.m.Y H:i');

$telegramUrl = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
$payload = http_build_query([
    'chat_id' => TELEGRAM_CHAT_ID,
    'text'    => $text,
]);

$ch = curl_init($telegramUrl . '?' . $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 8);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    // Логируем, но не палим пользователю детали
    error_log('Telegram send-lead error: ' . $response);
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'telegram_failed']);
    exit;
}

echo json_encode(['success' => true]);
