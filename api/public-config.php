<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/config.php';

$config = load_app_config();

echo json_encode([
    'maxProfileUrl' => (string) ($config['MAX_PROFILE_URL'] ?? ''),
    'telegramUrl' => (string) ($config['TELEGRAM_URL'] ?? ''),
    'whatsappUrl' => (string) ($config['WHATSAPP_URL'] ?? ''),
], JSON_UNESCAPED_UNICODE);
