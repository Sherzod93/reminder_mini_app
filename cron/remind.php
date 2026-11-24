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

$lock = fopen(dirname((string) cfg('db_path')) . '/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit; // предыдущий запуск ещё идёт
}

$now = time();
$today = today();
$weekday = date('N', $now);
$window = (int) cfg('send_window_min') * 60;
$appUrl = rtrim((string) cfg('app_url'), '/') . '/';
$sent = 0;

/** Пора ли слать: момент напоминания наступил, но не раньше, чем $window назад. */
$due = static function (string $hhmm, int $beforeMin) use ($today, $now, $window): bool {
    $at = strtotime("$today $hhmm") - $beforeMin * 60;
    return $at <= $now && $now - $at < $window;
};

/** Отметить как отправленное; true — если ещё не было (защита от дублей). */
$claim = static function (int $uid, string $ref) use ($today): bool {
    $st = db()->prepare('INSERT OR IGNORE INTO sent (user_id, ref, date) VALUES (?, ?, ?)');
    $st->execute([$uid, $ref, $today]);
    return $st->rowCount() === 1;
};

$isDone = static function (int $uid, string $ref) use ($today): bool {
    $st = db()->prepare('SELECT 1 FROM done WHERE user_id = ? AND ref = ? AND date = ?');
    $st->execute([$uid, $ref, $today]);
    return (bool) $st->fetch();
};

$buttons = static fn (string $ref) => [[
    ['text' => '✅ Бажарилди', 'callback_data' => "d|$ref|$today"],
    ['text' => '📋 Очиш', 'web_app' => ['url' => $appUrl]],
]];

$beforeText = static fn (int $min): string => $min > 0 ? " ({$min} дақиқадан сўнг)" : '';

$namaz = namaz_today();
if ($namaz && $namaz['stale']) {
    $namaz = null; // не шлём намаз по вчерашнему времени
}

$users = db()->query('SELECT id, namaz_enabled, namaz_before FROM users WHERE can_message = 1')->fetchAll();
$taskSt = db()->prepare('SELECT id, title, note, time, days, remind_before FROM tasks WHERE user_id = ? AND enabled = 1');

foreach ($users as $u) {
    $uid = (int) $u['id'];

    // Свои задачи
    $taskSt->execute([$uid]);
    foreach ($taskSt->fetchAll() as $t) {
        $ref = 't:' . $t['id'];
        $before = (int) $t['remind_before'];
        if (!str_contains($t['days'], $weekday) || !$due($t['time'], $before) || $isDone($uid, $ref) || !$claim($uid, $ref)) {
            continue;
        }
        $text = "⏰ <b>" . h($t['title']) . "</b>\n🕒 " . $t['time'] . $beforeText($before);
        if ($t['note'] !== '') {
            $text .= "\n\n" . h($t['note']);
        }
        tg_send($uid, $text, $buttons($ref));
        $sent++;
    }

    // Намаз
    if (!$namaz || !$u['namaz_enabled']) {
        continue;
    }
    $before = (int) $u['namaz_before'];
    foreach (namaz_user_settings($uid) as $key => $on) {
        $ref = "n:$key";
        $time = $namaz['times'][$key] ?? null;
        if (!$on || !$time || !$due($time, $before) || $isDone($uid, $ref) || !$claim($uid, $ref)) {
            continue;
        }
        $label = h($namaz['labels'][$key] ?? $key);
        $text = "🕌 <b>{$label}</b> намози вақти — {$time}" . $beforeText($before);
        tg_send($uid, $text, $buttons($ref));
        $sent++;
    }
}

if ($sent > 0) {
    echo date('c') . " sent=$sent\n";
}

// Чистка старых служебных записей (раз в сутки около полуночи хватает, но запрос дешёвый).
if (date('H:i', $now) === '03:00') {
    $old = date('Y-m-d', strtotime('-60 days'));
    db()->prepare('DELETE FROM sent WHERE date < ?')->execute([$old]);
    db()->prepare('DELETE FROM namaz_cache WHERE date < ?')->execute([$old]);
}
