<?php
declare(strict_types=1);

function load_app_config(): array
{
    $config = [
        'MAX_BOT_TOKEN' => '',
        'MAX_RECIPIENT_IDS' => '',
        'MAX_PROFILE_URL' => '',
        'TELEGRAM_URL' => '',
        'WHATSAPP_URL' => '',
    ];

    $local = __DIR__ . '/config.local.php';
    if (is_file($local)) {
        $localConfig = require $local;
        if (is_array($localConfig)) {
            $config = array_merge($config, $localConfig);
        }
    }

    $envFile = dirname(__DIR__) . '/.env';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (($config[$key] ?? '') === '') {
                $config[$key] = $value;
            }
        }
    }

    return $config;
}
