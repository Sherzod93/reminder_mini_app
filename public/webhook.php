<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/telegram.php';
require __DIR__ . '/../src/qazo.php';

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

// Кнопки под напоминанием: d|<ref>|<date> — «Бажарилди», s|<ref>|<date> — «Бугун вақтим йўқ»
// (повторы на этот день прекращаются).
if (isset($update['callback_query'])) {
    $cq = $update['callback_query'];
    $uid = (int) $cq['from']['id'];
    $parts = explode('|', (string) ($cq['data'] ?? ''));
    $text = '';
    if (count($parts) === 3 && in_array($parts[0], ['d', 's'], true)
        && preg_match('/^(t:\d+|n:[a-z]+)$/', $parts[1])
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[2])) {
        $ok = true;
        if (preg_match('/^t:(\d+)$/', $parts[1], $m)) {
            $st = db()->prepare('SELECT 1 FROM tasks WHERE id = ? AND user_id = ?');
            $st->execute([(int) $m[1], $uid]);
            $ok = (bool) $st->fetch();
        }
        if ($ok) {
            $table = $parts[0] === 'd' ? 'done' : 'skipped';
            db()->prepare("INSERT OR IGNORE INTO $table (user_id, ref, date) VALUES (?, ?, ?)")->execute([$uid, $parts[1], $parts[2]]);
            if ($table === 'done') {
                namaz_done_changed($uid, $parts[1], $parts[2], true); // поздняя отметка Қазо = адо
            }
            $text = $parts[0] === 'd' ? '✅ Бажарилди' : '⏭ Бугун бошқа эслатилмайди';
            if (isset($cq['message']['message_id'])) {
                tg_close_reminder((int) $cq['message']['chat']['id'], (int) $cq['message']['message_id'], $text, $cq['message']);
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
