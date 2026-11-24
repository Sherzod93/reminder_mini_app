<?php
declare(strict_types=1);

// История по дням: что было в плане и чем закончилось. Прошедший день «закрывается» —
// план и результат копируются в history, дальше правки задач его не меняют.
// Сегодняшний день считается на лету.

require_once __DIR__ . '/namaz.php';

/** Время намаза на дату из кэша (без quyosh). */
function namaz_for_date(string $date): ?array
{
    $st = db()->prepare('SELECT data FROM namaz_cache WHERE date = ?');
    $st->execute([$date]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $n = json_decode($row['data'], true);
    $keep = array_flip(NAMAZ_KEYS);
    return [
        'times'  => array_intersect_key($n['times'] ?? [], $keep),
        'labels' => array_intersect_key($n['labels'] ?? [], $keep),
    ];
}

/**
 * План дня по текущим задачам и настройкам с результатом. Задачи, заведённые после этого дня,
 * не попадают. status: done | skipped | missed (день прошёл) | pending (сегодня, не отмечено).
 */
function day_plan(int $uid, string $date): array
{
    $weekday = date('N', strtotime($date));
    $dayEndUtc = gmdate('Y-m-d H:i:s', strtotime("$date 23:59:59"));
    $items = [];

    $st = db()->prepare("SELECT id, title, time, end_time, days FROM tasks
                         WHERE user_id = ? AND enabled = 1 AND created_at <= ?");
    $st->execute([$uid, $dayEndUtc]);
    foreach ($st as $t) {
        if (str_contains($t['days'], $weekday)) {
            $items[] = ['ref' => 't:' . $t['id'], 'kind' => 'task', 'title' => $t['title'],
                        'time' => $t['time'], 'end_time' => $t['end_time']];
        }
    }

    $u = db()->prepare('SELECT namaz_enabled FROM users WHERE id = ?');
    $u->execute([$uid]);
    $namaz = $u->fetchColumn() ? namaz_for_date($date) : null;
    if ($namaz) {
        foreach (namaz_user_settings($uid) as $key => $on) {
            if ($on && isset($namaz['times'][$key])) {
                $items[] = ['ref' => "n:$key", 'kind' => 'namaz', 'title' => $namaz['labels'][$key] ?? $key,
                            'time' => $namaz['times'][$key], 'end_time' => ''];
            }
        }
    }

    $marks = [];
    foreach (['done', 'skipped'] as $table) {
        $m = db()->prepare("SELECT ref FROM $table WHERE user_id = ? AND date = ?");
        $m->execute([$uid, $date]);
        foreach ($m as $r) {
            $marks[$r['ref']] ??= $table === 'done' ? 'done' : 'skipped';
        }
    }
    $past = $date < today();
    foreach ($items as &$it) {
        $it['status'] = $marks[$it['ref']] ?? ($past ? 'missed' : 'pending');
    }
    unset($it);

    usort($items, fn ($a, $b) => [$a['time'], $a['kind'] === 'task'] <=> [$b['time'], $b['kind'] === 'task']);
    return $items;
}

/** Закрыть прошедшие незакрытые дни пользователя (не дальше 40 дней назад и не раньше регистрации). */
function close_days(int $uid): void
{
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $chk = db()->prepare('SELECT 1 FROM days_closed WHERE user_id = ? AND date = ?');
    $chk->execute([$uid, $yesterday]);
    if ($chk->fetch()) {
        return; // обычный случай — вчера уже закрыт
    }
    $u = db()->prepare('SELECT created_at FROM users WHERE id = ?');
    $u->execute([$uid]);
    $created = $u->fetchColumn();
    if (!$created) {
        return;
    }
    $from = max(date('Y-m-d', strtotime($created . ' UTC')), date('Y-m-d', strtotime('-40 days')));

    $ins = db()->prepare('INSERT OR REPLACE INTO history (user_id, date, ref, kind, title, time, end_time, status)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $close = db()->prepare('INSERT OR IGNORE INTO days_closed (user_id, date) VALUES (?, ?)');
    for ($d = $from; $d <= $yesterday; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        $chk->execute([$uid, $d]);
        if ($chk->fetch()) {
            continue;
        }
        db()->beginTransaction();
        foreach (day_plan($uid, $d) as $it) {
            $ins->execute([$uid, $d, $it['ref'], $it['kind'], $it['title'], $it['time'], $it['end_time'], $it['status']]);
        }
        $close->execute([$uid, $d]);
        db()->commit();
    }
}

/** Статистика за последние $days дней (включая сегодня). */
function user_stats(int $uid, int $days): array
{
    close_days($uid);
    $today = today();
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

    $byDate = [];
    $closed = db()->prepare('SELECT date FROM days_closed WHERE user_id = ? AND date >= ?');
    $closed->execute([$uid, $from]);
    foreach ($closed as $r) {
        $byDate[$r['date']] = [];
    }
    $h = db()->prepare('SELECT date, ref, kind, title, time, end_time, status FROM history
                        WHERE user_id = ? AND date >= ? ORDER BY date, time, kind DESC');
    $h->execute([$uid, $from]);
    foreach ($h as $r) {
        $date = $r['date'];
        unset($r['date']);
        $byDate[$date][] = $r;
    }
    $byDate[$today] = day_plan($uid, $today);

    $out = [];
    for ($d = $from; $d <= $today; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        if (!array_key_exists($d, $byDate)) {
            $out[] = ['date' => $d, 'weekday' => (int) date('N', strtotime($d)), 'tracked' => false, 'items' => []];
            continue;
        }
        $out[] = ['date' => $d, 'weekday' => (int) date('N', strtotime($d)), 'tracked' => true, 'items' => $byDate[$d]];
    }
    return ['today' => $today, 'days' => $out];
}
