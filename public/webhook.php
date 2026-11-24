<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/telegram.php';

$secret = secret('WEBHOOK_SECRET');
if ($secret === '' || !hash_equals($secret, (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) {
    http_response_code(403);
    exit;
}

$update = json_decode((string) file_get_contents('php://input'), true);
http_response_code(200);
if (!is_array($update)) {
    exit;
}

$appUrl = rtrim((string) cfg('app_url'), '/') . '/';

// /start и любые сообщения в личке
if (isset($update['message']['from'], $update['message']['chat']) && $update['message']['chat']['type'] === 'private') {
    $from = $update['message']['from'];
    user_upsert($from, true);
    $name = h((string) ($from['first_name'] ?? ''));
    tg_send(
        (int) $from['id'],
        "Ассалому алайкум, <b>{$name}</b>!\n\n"
        . "Бу бот кундалик вазифаларингизни эслатиб туради. Намоз вақтлари аввалдан қўшилган.\n"
        . "Вазифа қўшиш учун пастдаги тугмани босинг.",
        [[['text' => '📋 Вазифаларим', 'web_app' => ['url' => $appUrl]]]]
    );
    exit;
}

// Кнопка «Бажарилди» под напоминанием: d|<ref>|<date>
if (isset($update['callback_query'])) {
    $cq = $update['callback_query'];
    $uid = (int) $cq['from']['id'];
    $parts = explode('|', (string) ($cq['data'] ?? ''));
    $text = '';
    if (count($parts) === 3 && $parts[0] === 'd'
        && preg_match('/^(t:\d+|n:[a-z]+)$/', $parts[1])
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[2])) {
        $ok = true;
        if (preg_match('/^t:(\d+)$/', $parts[1], $m)) {
            $st = db()->prepare('SELECT 1 FROM tasks WHERE id = ? AND user_id = ?');
            $st->execute([(int) $m[1], $uid]);
            $ok = (bool) $st->fetch();
        }
        if ($ok) {
            db()->prepare('INSERT OR IGNORE INTO done (user_id, ref, date) VALUES (?, ?, ?)')->execute([$uid, $parts[1], $parts[2]]);
            $text = '✅ Бажарилди';
            if (isset($cq['message']['message_id'])) {
                tg_api('editMessageReplyMarkup', [
                    'chat_id'      => $cq['message']['chat']['id'],
                    'message_id'   => $cq['message']['message_id'],
                    'reply_markup' => ['inline_keyboard' => [[['text' => '✅ Бажарилди', 'callback_data' => 'noop']]]],
                ]);
            }
        } else {
            $text = 'Вазифа топилмади';
        }
    }
    tg_api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => $text]);
    exit;
}

// Пользователь заблокировал / разблокировал бота
if (isset($update['my_chat_member'])) {
    $m = $update['my_chat_member'];
    if (($m['chat']['type'] ?? '') === 'private') {
        $status = $m['new_chat_member']['status'] ?? '';
        user_upsert($m['from'], $status === 'member');
    }
}
