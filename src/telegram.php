<?php
declare(strict_types=1);

/** Проверка initData из Telegram.WebApp. Возвращает user или null. */
function tg_validate_init_data(string $initData, string $botToken, int $maxAge): ?array
{
    if ($initData === '' || $botToken === '') {
        return null;
    }
    $data = [];
    foreach (explode('&', $initData) as $pair) {
        if (!str_contains($pair, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $pair, 2);
        $data[urldecode($k)] = urldecode($v);
    }
    $hash = $data['hash'] ?? '';
    unset($data['hash']);
    if ($hash === '') {
        return null;
    }
    ksort($data, SORT_STRING);
    $check = [];
    foreach ($data as $k => $v) {
        $check[] = $k . '=' . $v;
    }
    $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $calc = hash_hmac('sha256', implode("\n", $check), $secretKey);
    if (!hash_equals($calc, $hash)) {
        return null;
    }
    $authDate = (int) ($data['auth_date'] ?? 0);
    if ($authDate <= 0 || time() - $authDate > $maxAge) {
        return null;
    }
    $user = json_decode($data['user'] ?? '', true);
    return (is_array($user) && isset($user['id'])) ? $user : null;
}

/** Вызов Bot API. Возвращает декодированный ответ (ok=false при ошибке сети). */
function tg_api(string $method, array $params = []): array
{
    $token = secret('BOT_TOKEN');
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    if ($body === false) {
        return ['ok' => false, 'error_code' => 0, 'description' => $err];
    }
    $res = json_decode((string) $body, true);
    return is_array($res) ? $res : ['ok' => false, 'error_code' => 0, 'description' => 'bad json'];
}

function tg_send(int $chatId, string $html, ?array $keyboard = null): array
{
    $p = [
        'chat_id'                  => $chatId,
        'text'                     => $html,
        'parse_mode'               => 'HTML',
        'link_preview_options'     => ['is_disabled' => true],
    ];
    if ($keyboard) {
        $p['reply_markup'] = ['inline_keyboard' => $keyboard];
    }
    $res = tg_api('sendMessage', $p);
    // 403 — пользователь заблокировал бота или не нажимал /start.
    if (!$res['ok'] && ($res['error_code'] ?? 0) === 403) {
        db()->prepare('UPDATE users SET can_message = 0 WHERE id = ?')->execute([$chatId]);
    }
    return $res;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
