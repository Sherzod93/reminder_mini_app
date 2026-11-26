<?php
declare(strict_types=1);

// Только пять намазов; quyosh (восход) из API не показываем и не напоминаем.
const NAMAZ_KEYS = ['bomdod', 'peshin', 'asr', 'shom', 'xufton'];
// В кэш пишем и восход: им кончается время Бомдода (учёт Қазо).
const NAMAZ_CACHE_KEYS = ['bomdod', 'quyosh', 'peshin', 'asr', 'shom', 'xufton'];

/**
 * Время намаза на сегодня: ['date', 'times' => [key => 'HH:MM'], 'labels' => [key => 'Бомдод'], 'stale' => bool].
 * Кэшируется в БД на дату; при сбое источника отдаётся последний кэш с stale=true.
 */
function namaz_today(): ?array
{
    $n = namaz_today_raw();
    if ($n) {
        // В кэше могут лежать ключи, которых больше нет в NAMAZ_KEYS (например, quyosh).
        $keep = array_flip(NAMAZ_KEYS);
        $n['times'] = array_intersect_key($n['times'], $keep);
        $n['labels'] = array_intersect_key($n['labels'], $keep);
    }
    return $n;
}

function namaz_today_raw(): ?array
{
    $today = today();
    $st = db()->prepare('SELECT data FROM namaz_cache WHERE date = ?');
    $st->execute([$today]);
    $cached = ($row = $st->fetch()) ? json_decode($row['data'], true) : null;
    // Кэш до этой правки без восхода — перезапросить один раз (при сбое источника он остаётся).
    if ($cached && isset($cached['times']['quyosh'])) {
        return $cached + ['stale' => false];
    }

    // Не долбим источник при сбое чаще раза в 5 минут.
    $failFlag = dirname((string) cfg('db_path')) . '/namaz-fail.flag';
    $fresh = (is_file($failFlag) && time() - filemtime($failFlag) < 300) ? null : namaz_fetch();

    if ($fresh && $fresh['date'] === $today) {
        db()->prepare('INSERT OR REPLACE INTO namaz_cache (date, data, fetched_at) VALUES (?, ?, ?)')
            ->execute([$today, json_encode($fresh, JSON_UNESCAPED_UNICODE), date('c')]);
        @unlink($failFlag);
        return $fresh + ['stale' => false];
    }
    if (!$fresh) {
        @touch($failFlag);
    }
    if ($cached) {
        return $cached + ['stale' => false];
    }

    $last = db()->query('SELECT data FROM namaz_cache ORDER BY date DESC LIMIT 1')->fetch();
    return $last ? json_decode($last['data'], true) + ['stale' => true] : null;
}

function namaz_fetch(): ?array
{
    $ch = curl_init((string) cfg('namaz_url'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'reminder-miniapp/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false || $code !== 200) {
        error_log("namaz fetch failed: HTTP $code " . curl_error($ch));
        return null;
    }
    $j = json_decode((string) $body, true);
    $times = $j['today']['times'] ?? null;
    $date = $j['meta']['date'] ?? null;
    if (!is_array($times) || !is_string($date)) {
        error_log('namaz fetch: unexpected json');
        return null;
    }
    $out = ['date' => $date, 'times' => [], 'labels' => []];
    foreach (NAMAZ_CACHE_KEYS as $k) {
        $t = (string) ($times[$k] ?? '');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
            continue;
        }
        $out['times'][$k] = $t;
        $out['labels'][$k] = (string) ($j['labels'][$k] ?? ucfirst($k));
    }
    return $out['times'] ? $out : null;
}

/** Какие намазы включены у пользователя: [key => bool]. */
function namaz_user_settings(int $userId): array
{
    $defaults = cfg('namaz_default_on');
    $out = [];
    foreach (NAMAZ_KEYS as $k) {
        $out[$k] = in_array($k, $defaults, true);
    }
    $st = db()->prepare('SELECT prayer, enabled FROM namaz_settings WHERE user_id = ?');
    $st->execute([$userId]);
    foreach ($st as $r) {
        if (array_key_exists($r['prayer'], $out)) {
            $out[$r['prayer']] = (bool) $r['enabled'];
        }
    }
    return $out;
}
