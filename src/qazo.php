<?php
declare(strict_types=1);

// Қазо дафтари. Время намаза кончается: Бомдод — с восходом (Қуёш), Пешин — с Асром, Аср — с
// Шомом, Шом — с Хуфтоном, Хуфтон — с Бомдодом следующего дня. Не отмечен к этому моменту —
// запись в namaz_log: qazo, а для Хуфтона — qarz. Отметка «бажарилди» после этого — адо.

require_once __DIR__ . '/namaz.php';

const QAZO_NEXT = ['bomdod' => 'quyosh', 'peshin' => 'asr', 'asr' => 'shom', 'shom' => 'xufton'];

/** Все времена дня из кэша, включая восход; null — кэша на этот день нет. */
function namaz_raw(string $date): ?array
{
    static $memo = [];
    if (!array_key_exists($date, $memo)) {
        $st = db()->prepare('SELECT data FROM namaz_cache WHERE date = ?');
        $st->execute([$date]);
        $row = $st->fetch();
        $memo[$date] = $row ? (json_decode($row['data'], true)['times'] ?? null) : null;
    }
    return $memo[$date];
}

/** Момент, когда время намаза кончается; null — неизвестно (нет времён). */
function qazo_deadline(string $date, string $key): ?int
{
    if ($key === 'xufton') {
        $next = date('Y-m-d', strtotime("$date +1 day"));
        $t = namaz_raw($next)['bomdod'] ?? null;
        return $t ? strtotime("$next $t") : null;
    }
    $times = namaz_raw($date);
    // Кэш без восхода (до правки 26.11) — для Бомдода берём Пешин.
    $t = $times[QAZO_NEXT[$key]] ?? ($key === 'bomdod' ? ($times['peshin'] ?? null) : null);
    return $t ? strtotime("$date $t") : null;
}

/** Время намаза ещё идёт (можно отметить вовремя)? */
function namaz_in_time(string $date, string $key, ?int $now = null): bool
{
    $dl = qazo_deadline($date, $key);
    return $dl === null || ($now ?? time()) < $dl;
}

/**
 * Записать Қазо/Қарз за вчера и сегодня, у кого время намаза кончилось, а отметки нет.
 * Не считаем намазы до регистрации и отключённые в настройках.
 */
function qazo_check(int $uid, ?int $now = null): int
{
    $now ??= time();
    $u = db()->prepare('SELECT namaz_enabled, created_at FROM users WHERE id = ?');
    $u->execute([$uid]);
    $u = $u->fetch();
    if (!$u || !$u['namaz_enabled']) {
        return 0;
    }
    $since = strtotime($u['created_at'] . ' UTC');
    $settings = namaz_user_settings($uid);
    $has = db()->prepare('SELECT 1 FROM namaz_log WHERE user_id = ? AND date = ? AND prayer = ?');
    $done = db()->prepare('SELECT 1 FROM done WHERE user_id = ? AND date = ? AND ref = ?');
    $ins = db()->prepare('INSERT OR IGNORE INTO namaz_log (user_id, date, prayer, status, decided_at) VALUES (?, ?, ?, ?, ?)');
    $added = 0;

    foreach ([date('Y-m-d', strtotime('-1 day', $now)), date('Y-m-d', $now)] as $date) {
        $times = namaz_raw($date);
        if (!$times) {
            continue;
        }
        foreach (NAMAZ_KEYS as $key) {
            if (!$settings[$key] || empty($times[$key]) || strtotime("$date {$times[$key]}") < $since) {
                continue;
            }
            $dl = qazo_deadline($date, $key);
            if ($dl === null || $now < $dl) {
                continue;
            }
            $has->execute([$uid, $date, $key]);
            $done->execute([$uid, $date, "n:$key"]);
            if ($has->fetch() || $done->fetch()) {
                continue;
            }
            $ins->execute([$uid, $date, $key, $key === 'xufton' ? 'qarz' : 'qazo', date('c', $now)]);
            $added += $ins->rowCount();
        }
    }
    return $added;
}

/**
 * Последствия отметки «бажарилди» (или её снятия) для намаза за дату: если намаз уже в Қазо
 * дафтари — это адо; если день уже закрыт в истории — поправить его итог.
 */
function namaz_done_changed(int $uid, string $ref, string $date, bool $done): void
{
    if (preg_match('/^n:([a-z]+)$/', $ref, $m)) {
        db()->prepare('UPDATE namaz_log SET made_up_at = ? WHERE user_id = ? AND date = ? AND prayer = ?')
            ->execute([$done ? date('c') : null, $uid, $date, $m[1]]);
    }
    db()->prepare('UPDATE history SET status = ? WHERE user_id = ? AND date = ? AND ref = ?')
        ->execute([$done ? 'done' : 'missed', $uid, $date, $ref]);
}

/** Отметить адо прямо в дафтаре (нажатие на ячейку). */
function qazo_set_made_up(int $uid, string $date, string $key, bool $madeUp): bool
{
    $st = db()->prepare('UPDATE namaz_log SET made_up_at = ? WHERE user_id = ? AND date = ? AND prayer = ?');
    $st->execute([$madeUp ? date('c') : null, $uid, $date, $key]);
    return $st->rowCount() > 0;
}

function qazo_list(int $uid): array
{
    $st = db()->prepare('SELECT date, prayer, status, made_up_at FROM namaz_log WHERE user_id = ? ORDER BY date DESC');
    $st->execute([$uid]);
    return array_map(fn ($r) => [
        'date'    => $r['date'],
        'prayer'  => $r['prayer'],
        'status'  => $r['status'],
        'made_up' => $r['made_up_at'] !== null,
    ], $st->fetchAll());
}

/**
 * Перенести пропуски из истории (дни до появления дафтари): невыполненный намаз — qazo,
 * Хуфтон — qarz. Повторный запуск ничего не меняет.
 */
function qazo_backfill_from_history(int $uid): int
{
    $rows = db()->prepare("SELECT h.date, h.ref, h.time, u.created_at FROM history h JOIN users u ON u.id = h.user_id
                            WHERE h.user_id = ? AND h.kind = 'namaz' AND h.status IN ('missed', 'skipped')");
    $rows->execute([$uid]);
    $ins = db()->prepare('INSERT OR IGNORE INTO namaz_log (user_id, date, prayer, status, decided_at) VALUES (?, ?, ?, ?, ?)');
    $n = 0;
    foreach ($rows as $r) {
        $key = substr($r['ref'], 2);
        // Намаз до регистрации пропустить было нельзя.
        if (!in_array($key, NAMAZ_KEYS, true) || strtotime("{$r['date']} {$r['time']}") < strtotime($r['created_at'] . ' UTC')) {
            continue;
        }
        $ins->execute([$uid, $r['date'], $key, $key === 'xufton' ? 'qarz' : 'qazo', date('c')]);
        $n += $ins->rowCount();
    }
    return $n;
}
