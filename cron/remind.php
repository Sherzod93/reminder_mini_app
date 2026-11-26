<?php
declare(strict_types=1);

// Запуск раз в минуту: * * * * * php /var/www/reminder/cron/remind.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/telegram.php';
require __DIR__ . '/../src/namaz.php';
require __DIR__ . '/../src/history.php';
require __DIR__ . '/../src/qazo.php';

$lock = fopen(dirname((string) cfg('db_path')) . '/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit; // предыдущий запуск ещё идёт
}

$now = time();
$today = today();
$weekday = date('N', $now);
$window = (int) cfg('send_window_min') * 60;
$repeatEvery = max(1, (int) cfg('repeat_every_min')) * 60;
$endOfDay = strtotime("$today 23:59:59");
$appUrl = rtrim((string) cfg('app_url'), '/') . '/';
$stat = ['first' => 0, 'repeat' => 0];

$q = static function (string $sql, array $args = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
};

$buttons = static fn (string $ref, bool $namaz) => [
    [
        ['text' => '✅ Бажарилди', 'callback_data' => "d|$ref|$today"],
        ['text' => $namaz ? '🔕 Бугун эслатмасин' : '⏭ Бугун вақтим йўқ', 'callback_data' => "s|$ref|$today"],
    ],
    [['text' => '📋 Очиш', 'web_app' => ['url' => $appUrl]]],
];

/**
 * Одно напоминание: первое — когда наступил момент (не позже $window). Дальше, пока не отмечено
 * «бажарилди» или «вақтим йўқ» и не наступил $until: если задан $onceAt — один повтор в этот
 * момент (намаз), иначе повторы каждые $repeatEvery (задачи).
 */
$process = static function (int $uid, string $ref, string $hhmm, int $beforeMin, int $until, bool $isNamaz, callable $text, ?int $onceAt = null)
    use ($q, $now, $today, $window, $repeatEvery, $buttons, &$stat): void {
    $at = strtotime("$today $hhmm") - $beforeMin * 60;
    if ($at > $now) {
        return;
    }
    if ($q('SELECT 1 FROM done WHERE user_id = ? AND ref = ? AND date = ?', [$uid, $ref, $today])->fetch()
        || $q('SELECT 1 FROM skipped WHERE user_id = ? AND ref = ? AND date = ?', [$uid, $ref, $today])->fetch()) {
        return;
    }
    $row = $q('SELECT last_at, msg_id, repeats FROM sent WHERE user_id = ? AND ref = ? AND date = ?', [$uid, $ref, $today])->fetch();

    if (!$row) {
        if ($now - $at >= $window) {
            return; // момент давно прошёл (задачу завели позже) — не дёргаем
        }
        $ins = $q('INSERT OR IGNORE INTO sent (user_id, ref, date, last_at) VALUES (?, ?, ?, ?)', [$uid, $ref, $today, $now]);
        if ($ins->rowCount() !== 1) {
            return;
        }
        $res = tg_send($uid, $text(false), $buttons($ref, $isNamaz));
        $stat['first']++;
    } else {
        if ($now >= $until) {
            return;
        }
        $notYet = $onceAt !== null
            ? ((int) $row['repeats'] > 0 || $now < $onceAt)
            : ($now - (int) $row['last_at'] < $repeatEvery);
        if ($notYet) {
            return;
        }
        $q('UPDATE sent SET last_at = ?, repeats = repeats + 1 WHERE user_id = ? AND ref = ? AND date = ?', [$now, $uid, $ref, $today]);
        if ($row['msg_id']) {
            tg_api('deleteMessage', ['chat_id' => $uid, 'message_id' => (int) $row['msg_id']]); // чат не засоряем
        }
        $res = tg_send($uid, $text(true), $buttons($ref, $isNamaz));
        $stat['repeat']++;
    }
    if (!empty($res['ok'])) {
        $q('UPDATE sent SET msg_id = ? WHERE user_id = ? AND ref = ? AND date = ?', [(int) $res['result']['message_id'], $uid, $ref, $today]);
    }
};

$beforeText = static fn (int $min): string => $min > 0 ? " ({$min} дақиқадан сўнг)" : '';

/**
 * Время окончания задачи наступило, а она не отмечена — одно «⌛ вақти тугади». Только если о
 * задаче сегодня уже напоминали. Отметка отправки — строка e:<id> в sent; кнопки и msg_id
 * переходят к t:<id>, чтобы «Бажарилди» в приложении снимал их и с этого сообщения.
 */
$finish = static function (int $uid, int $taskId, string $title, string $hhmm)
    use ($q, $now, $today, $window, $buttons, &$stat): void {
    $endAt = strtotime("$today $hhmm");
    $ref = "t:$taskId";
    if ($now < $endAt || $now - $endAt >= $window) {
        return;
    }
    $main = $q('SELECT msg_id FROM sent WHERE user_id = ? AND ref = ? AND date = ?', [$uid, $ref, $today])->fetch();
    if (!$main
        || $q('SELECT 1 FROM done WHERE user_id = ? AND ref = ? AND date = ?', [$uid, $ref, $today])->fetch()
        || $q('SELECT 1 FROM skipped WHERE user_id = ? AND ref = ? AND date = ?', [$uid, $ref, $today])->fetch()) {
        return;
    }
    $ins = $q('INSERT OR IGNORE INTO sent (user_id, ref, date, last_at) VALUES (?, ?, ?, ?)', [$uid, "e:$taskId", $today, $now]);
    if ($ins->rowCount() !== 1) {
        return;
    }
    if ($main['msg_id']) {
        tg_api('deleteMessage', ['chat_id' => $uid, 'message_id' => (int) $main['msg_id']]);
    }
    $res = tg_send($uid, "⌛ <b>" . h($title) . "</b> вақти тугади ({$hhmm})\nБажардингизми?", $buttons($ref, false));
    if (!empty($res['ok'])) {
        $q('UPDATE sent SET msg_id = ? WHERE user_id = ? AND ref = ? AND date = ?', [(int) $res['result']['message_id'], $uid, $ref, $today]);
    }
    $stat['repeat']++;
};

$namaz = namaz_today();
if ($namaz && $namaz['stale']) {
    $namaz = null; // не шлём намаз по вчерашнему времени
}
// За warnBefore до конца времени намаза — одно напоминание, если не отмечен. Граница та же, что
// у Қазо дафтари (qazo_deadline): Бомдод — восход, Хуфтон — Бомдод следующего утра (его
// предупреждение уходит утром, отдельным шагом ниже).
$warnBefore = (int) cfg('namaz_warn_before_next_min') * 60;
const WARN_UNTIL_LABEL = ['bomdod' => 'Қуёш', 'peshin' => 'Аср', 'asr' => 'Шом', 'shom' => 'Хуфтон'];

// После полуночи — сохранить итог вчерашнего дня всем, включая тех, кому бот писать не может.
foreach ($q('SELECT id FROM users')->fetchAll() as $row) {
    close_days((int) $row['id']);
    qazo_check((int) $row['id']); // время намаза кончилось без отметки — в Қазо дафтари
}

$users = $q('SELECT id, namaz_enabled, namaz_before FROM users WHERE can_message = 1')->fetchAll();

foreach ($users as $u) {
    $uid = (int) $u['id'];

    // Свои задачи
    $tasks = $q('SELECT id, title, note, time, end_time, days, remind_before FROM tasks WHERE user_id = ? AND enabled = 1', [$uid])->fetchAll();
    foreach ($tasks as $t) {
        if (!str_contains($t['days'], $weekday)) {
            continue;
        }
        $before = (int) $t['remind_before'];
        $range = $t['end_time'] !== '' ? "{$t['time']}–{$t['end_time']}" : $t['time'];
        // С временем окончания повторы идут до него, без — до конца дня.
        $until = $t['end_time'] !== '' ? strtotime("$today {$t['end_time']}") : $endOfDay;
        $process($uid, 't:' . $t['id'], $t['time'], $before, $until, false, static function (bool $repeat) use ($t, $before, $beforeText, $range): string {
            $text = $repeat
                ? "🔁 Ҳали бажарилмаган: <b>" . h($t['title']) . "</b>\n🕒 Вақти: " . $range
                : "⏰ <b>" . h($t['title']) . "</b>\n🕒 " . $range . $beforeText($before);
            return $t['note'] !== '' ? $text . "\n\n" . h($t['note']) : $text;
        });
        if ($t['end_time'] !== '') {
            $finish($uid, (int) $t['id'], $t['title'], $t['end_time']);
        }
    }

    // Намаз
    if (!$namaz || !$u['namaz_enabled']) {
        continue;
    }
    $before = (int) $u['namaz_before'];
    foreach (namaz_user_settings($uid) as $key => $on) {
        $time = $namaz['times'][$key] ?? null;
        if (!$on || !$time) {
            continue;
        }
        $label = h($namaz['labels'][$key] ?? $key);
        $endAt = $key === 'xufton' ? null : qazo_deadline($today, $key);
        $warnAt = $endAt ? $endAt - $warnBefore : PHP_INT_MAX;
        $left = intdiv($warnBefore, 60);
        $untilLabel = WARN_UNTIL_LABEL[$key] ?? '';
        $process($uid, "n:$key", $time, $before, $endAt ?? $endOfDay + 1, true, static fn (bool $repeat): string => $repeat
            ? "⏳ <b>{$label}</b> намози ҳали ўқилмаган\n{$untilLabel}гача {$left} дақиқа қолди"
            : "🕌 <b>{$label}</b> намози вақти — {$time}" . $beforeText($before),
            // Хуфтон — утром (ниже); если от начала до конца меньше 45 минут — предупреждать нечего.
            $warnAt > strtotime("$today $time") ? $warnAt : PHP_INT_MAX);
    }

    // Вчерашний Хуфтон не отмечен — за 45 минут до сегодняшнего Бомдода одно напоминание.
    // Только если вечером о нём напоминали; кнопки отмечают вчерашнюю дату.
    $y = date('Y-m-d', strtotime('-1 day', $now));
    $dl = namaz_user_settings($uid)['xufton'] ? qazo_deadline($y, 'xufton') : null;
    if ($dl && $now >= $dl - $warnBefore && $now < $dl) {
        $row = $q('SELECT msg_id, repeats FROM sent WHERE user_id = ? AND ref = ? AND date = ?', [$uid, 'n:xufton', $y])->fetch();
        $marked = $q('SELECT 1 FROM done WHERE user_id = ? AND ref = ? AND date = ?', [$uid, 'n:xufton', $y])->fetch()
            || $q('SELECT 1 FROM skipped WHERE user_id = ? AND ref = ? AND date = ?', [$uid, 'n:xufton', $y])->fetch();
        if ($row && (int) $row['repeats'] === 0 && !$marked) {
            $q('UPDATE sent SET repeats = 1, last_at = ? WHERE user_id = ? AND ref = ? AND date = ?', [$now, $uid, 'n:xufton', $y]);
            if ($row['msg_id']) {
                tg_api('deleteMessage', ['chat_id' => $uid, 'message_id' => (int) $row['msg_id']]); // вечернее
            }
            $res = tg_send($uid, "⏳ <b>Хуфтон</b> намози ҳали ўқилмаган\nБомдодгача (" . date('H:i', $dl) . ') '
                . intdiv($warnBefore, 60) . ' дақиқа қолди', [
                [
                    ['text' => '✅ Бажарилди', 'callback_data' => "d|n:xufton|$y"],
                    ['text' => '🔕 Эслатмасин', 'callback_data' => "s|n:xufton|$y"],
                ],
                [['text' => '📋 Очиш', 'web_app' => ['url' => $appUrl]]],
            ]);
            if (!empty($res['ok'])) {
                $q('UPDATE sent SET msg_id = ? WHERE user_id = ? AND ref = ? AND date = ?', [(int) $res['result']['message_id'], $uid, 'n:xufton', $y]);
            }
            $stat['repeat']++;
        }
    }
}

if ($stat['first'] + $stat['repeat'] > 0) {
    echo date('c') . " sent={$stat['first']} repeat={$stat['repeat']}\n";
}

// Чистка старых служебных записей.
if (date('H:i', $now) === '03:00') {
    $old = date('Y-m-d', strtotime('-60 days'));
    $q('DELETE FROM sent WHERE date < ?', [$old]);
    $q('DELETE FROM skipped WHERE date < ?', [$old]);
    $q('DELETE FROM namaz_cache WHERE date < ?', [$old]);
}
