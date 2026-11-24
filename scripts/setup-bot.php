<?php
declare(strict_types=1);

// Один раз после выкатки: php scripts/setup-bot.php
// Ставит вебхук, кнопку меню «Вазифалар» и команду /start.
if (PHP_SAPI !== 'cli') {
    exit;
}

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/telegram.php';

if (secret('BOT_TOKEN') === '' || secret('WEBHOOK_SECRET') === '') {
    fwrite(STDERR, "BOT_TOKEN и WEBHOOK_SECRET должны быть в .env\n");
    exit(1);
}

$appUrl = rtrim((string) cfg('app_url'), '/');

$steps = [
    'setWebhook' => [
        'url'             => "$appUrl/webhook.php",
        'secret_token'    => secret('WEBHOOK_SECRET'),
        'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
        'drop_pending_updates' => true,
    ],
    'setChatMenuButton' => [
        'menu_button' => ['type' => 'web_app', 'text' => 'Вазифалар', 'web_app' => ['url' => "$appUrl/"]],
    ],
    'setMyCommands' => [
        'commands' => [['command' => 'start', 'description' => 'Бошлаш']],
    ],
];

foreach ($steps as $method => $params) {
    $res = tg_api($method, $params);
    echo str_pad($method, 20) . ($res['ok'] ? 'ok' : 'FAIL: ' . ($res['description'] ?? '')) . "\n";
}

print_r(tg_api('getWebhookInfo')['result'] ?? []);
