<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

require __DIR__ . '/config.php';

function respond_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
        $digits = '7' . substr($digits, 1);
    }
    if (strlen($digits) === 10) {
        $digits = '7' . $digits;
    }
    if (strlen($digits) === 11 && str_starts_with($digits, '7')) {
        return sprintf(
            '+7 (%s) %s-%s-%s',
            substr($digits, 1, 3),
            substr($digits, 4, 3),
            substr($digits, 7, 2),
            substr($digits, 9, 2)
        );
    }
    return trim($phone);
}

function build_message(string $name, string $phone, string $debt): string
{
    $debtText = trim($debt) !== '' ? trim($debt) : 'не указана';
    $sentAt = (new DateTime('now'))->format('d.m.Y H:i:s');

    return implode("\n", [
        '📩 Новая заявка с сайта «ЯПомогаю.рф - Банкротство»',
        '',
        '👤 Имя: ' . $name,
        '📞 Телефон: ' . $phone,
        '💰 Сумма долга: ' . $debtText,
        '',
        '🕒 ' . $sentAt,
    ]);
}

function get_recipient_ids(string $raw): array
{
    $ids = [];
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part !== '' && ctype_digit($part)) {
            $ids[] = (int) $part;
        }
    }
    return $ids;
}

function validate_lead(array $data): array
{
    $name = trim((string) ($data['name'] ?? ''));
    $phoneRaw = trim((string) ($data['phone'] ?? ''));
    $debt = trim((string) ($data['debt'] ?? ''));
    $consent = !empty($data['consent']);

    $errors = [];
    if (mb_strlen($name) < 2) {
        $errors[] = 'укажите имя';
    }
    if (strlen(preg_replace('/\D+/', '', $phoneRaw) ?? '') < 11) {
        $errors[] = 'укажите корректный телефон';
    }
    if (!$consent) {
        $errors[] = 'подтвердите согласие на обработку данных';
    }

    if ($errors) {
        throw new InvalidArgumentException('Пожалуйста, ' . implode(', ', $errors) . '.');
    }

    return [$name, normalize_phone($phoneRaw), $debt];
}

function send_max_message(string $token, int $userId, string $text): void
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('На хостинге не включено расширение cURL.');
    }

    $url = 'https://platform-api.max.ru/messages?user_id=' . $userId;
    $payload = json_encode(['text' => $text, 'notify' => true], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $token,
            'Content-Type: application/json; charset=utf-8',
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException($error !== '' ? $error : 'Ошибка соединения с MAX API.');
    }
    if ($status >= 400) {
        throw new RuntimeException($body !== '' ? $body : 'HTTP ' . $status);
    }
}

$config = load_app_config();
$token = trim((string) ($config['MAX_BOT_TOKEN'] ?? ''));

if ($token === '') {
    respond_json(500, [
        'ok' => false,
        'error' => 'Сервер не настроен: отсутствует MAX_BOT_TOKEN.',
    ]);
}

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new JsonException('Invalid payload');
    }

    [$name, $phone, $debt] = validate_lead($data);
    $message = build_message($name, $phone, $debt);
    $recipients = get_recipient_ids((string) ($config['MAX_RECIPIENT_IDS'] ?? ''));

    if (!$recipients) {
        throw new RuntimeException('Не указаны получатели MAX_RECIPIENT_IDS.');
    }

    foreach ($recipients as $userId) {
        send_max_message($token, $userId, $message);
    }

    respond_json(200, ['ok' => true]);
} catch (InvalidArgumentException $e) {
    respond_json(400, ['ok' => false, 'error' => $e->getMessage()]);
} catch (JsonException $e) {
    respond_json(400, ['ok' => false, 'error' => 'Некорректный JSON.']);
} catch (Throwable $e) {
    error_log('Lead send error: ' . $e->getMessage());
    respond_json(502, [
        'ok' => false,
        'error' => 'Не удалось отправить заявку в MAX. Попробуйте позже или позвоните нам.',
    ]);
}
