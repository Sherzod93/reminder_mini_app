<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/telegram.php';
require __DIR__ . '/../src/namaz.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const REMIND_BEFORE = [0, 5, 10, 15, 30, 60];

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): never
{
    out(['error' => $msg], $code);
}

$user = tg_validate_init_data(
    (string) ($_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? ''),
    secret('BOT_TOKEN'),
    (int) cfg('init_data_max_age')
);
if (!$user) {
    fail('unauthorized', 401);
}
$uid = (int) $user['id'];
user_upsert($user);

$action = (string) ($_GET['action'] ?? 'state');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in = [];
if ($method === 'POST') {
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) {
        fail('bad json');
    }
}

try {
    match ([$method, $action]) {
        ['GET', 'state']        => null,
        ['POST', 'task_save']   => task_save($uid, $in),
        ['POST', 'task_delete'] => task_delete($uid, $in),
        ['POST', 'done']        => set_mark($uid, $in, 'done'),
        ['POST', 'skip']        => set_mark($uid, $in, 'skipped'),
        ['POST', 'settings']    => save_settings($uid, $in),
        default                 => fail('not found', 404),
    };
    out(state($uid));
} catch (InvalidArgumentException $e) {
    fail($e->getMessage(), 422);
}

function state(int $uid): array
{
    $u = db()->prepare('SELECT can_message, namaz_enabled, namaz_before FROM users WHERE id = ?');
    $u->execute([$uid]);
    $u = $u->fetch();

    $t = db()->prepare('SELECT id, title, note, time, end_time, days, remind_before, enabled FROM tasks WHERE user_id = ? ORDER BY time, id');
    $t->execute([$uid]);
    $tasks = array_map(fn ($r) => [
        'id'            => (int) $r['id'],
        'title'         => $r['title'],
        'note'          => $r['note'],
        'time'          => $r['time'],
        'end_time'      => $r['end_time'],
        'days'          => $r['days'],
        'remind_before' => (int) $r['remind_before'],
        'enabled'       => (bool) $r['enabled'],
    ], $t->fetchAll());

    $d = db()->prepare('SELECT ref FROM done WHERE user_id = ? AND date = ?');
    $d->execute([$uid, today()]);
    $sk = db()->prepare('SELECT ref FROM skipped WHERE user_id = ? AND date = ?');
    $sk->execute([$uid, today()]);

    $namaz = namaz_today();
    return [
        'today'    => today(),
        'weekday'  => (int) date('N'),
        'now'      => date('H:i'),
        'user'     => [
            'can_message'   => (bool) $u['can_message'],
            'namaz_enabled' => (bool) $u['namaz_enabled'],
            'namaz_before'  => (int) $u['namaz_before'],
        ],
        'namaz'    => $namaz ? [
            'date'     => $namaz['date'],
            'stale'    => $namaz['stale'],
            'times'    => $namaz['times'],
            'labels'   => $namaz['labels'],
        ] : null,
        'prayers'  => namaz_user_settings($uid),
        'tasks'    => $tasks,
        'done'     => array_column($d->fetchAll(), 'ref'),
        'skipped'  => array_column($sk->fetchAll(), 'ref'),
    ];
}

function task_save(int $uid, array $in): void
{
    $title = trim((string) ($in['title'] ?? ''));
    $note = trim((string) ($in['note'] ?? ''));
    $time = (string) ($in['time'] ?? '');
    $end = (string) ($in['end_time'] ?? '');
    $days = implode('', array_unique(str_split(preg_replace('/[^1-7]/', '', (string) ($in['days'] ?? '')))));
    $before = (int) ($in['remind_before'] ?? 0);
    $enabled = (int) (bool) ($in['enabled'] ?? true);

    if ($title === '' || mb_strlen($title) > 200) {
        throw new InvalidArgumentException('title');
    }
    if (mb_strlen($note) > 1000) {
        throw new InvalidArgumentException('note');
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        throw new InvalidArgumentException('time');
    }
    // Окончание необязательно; если задано — в тот же день и позже начала.
    if ($end !== '' && (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) || $end <= $time)) {
        throw new InvalidArgumentException('end_time');
    }
    if ($days === '') {
        throw new InvalidArgumentException('days');
    }
    $chars = str_split($days);
    sort($chars);
    $days = implode('', $chars);
    if (!in_array($before, REMIND_BEFORE, true)) {
        throw new InvalidArgumentException('remind_before');
    }

    $id = (int) ($in['id'] ?? 0);
    if ($id > 0) {
        $st = db()->prepare('UPDATE tasks SET title = ?, note = ?, time = ?, end_time = ?, days = ?, remind_before = ?, enabled = ? WHERE id = ? AND user_id = ?');
        $st->execute([$title, $note, $time, $end, $days, $before, $enabled, $id, $uid]);
        if ($st->rowCount() === 0) {
            throw new InvalidArgumentException('task not found');
        }
        // Время поменяли — разрешаем напомнить сегодня ещё раз.
        db()->prepare('DELETE FROM sent WHERE user_id = ? AND ref IN (?, ?) AND date = ?')->execute([$uid, "t:$id", "e:$id", today()]);
        db()->prepare('DELETE FROM skipped WHERE user_id = ? AND ref = ? AND date = ?')->execute([$uid, "t:$id", today()]);
        return;
    }

    $cnt = db()->prepare('SELECT COUNT(*) FROM tasks WHERE user_id = ?');
    $cnt->execute([$uid]);
    if ((int) $cnt->fetchColumn() >= (int) cfg('max_tasks')) {
        throw new InvalidArgumentException('too many tasks');
    }
    db()->prepare('INSERT INTO tasks (user_id, title, note, time, end_time, days, remind_before, enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$uid, $title, $note, $time, $end, $days, $before, $enabled]);
}

function task_delete(int $uid, array $in): void
{
    $id = (int) ($in['id'] ?? 0);
    db()->prepare('DELETE FROM tasks WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    db()->prepare('DELETE FROM done WHERE user_id = ? AND ref = ?')->execute([$uid, "t:$id"]);
    db()->prepare('DELETE FROM sent WHERE user_id = ? AND ref IN (?, ?)')->execute([$uid, "t:$id", "e:$id"]);
    db()->prepare('DELETE FROM skipped WHERE user_id = ? AND ref = ?')->execute([$uid, "t:$id"]);
}

/** Отметка на сегодня: $table = done («бажарилди») или skipped («вақтим йўқ»). */
function set_mark(int $uid, array $in, string $table): void
{
    if (!in_array($table, ['done', 'skipped'], true)) {
        throw new InvalidArgumentException('table');
    }
    $flag = $table === 'done' ? 'done' : 'skip';
    $ref = (string) ($in['ref'] ?? '');
    if (preg_match('/^t:(\d+)$/', $ref, $m)) {
        $st = db()->prepare('SELECT 1 FROM tasks WHERE id = ? AND user_id = ?');
        $st->execute([(int) $m[1], $uid]);
        if (!$st->fetch()) {
            throw new InvalidArgumentException('task not found');
        }
    } elseif (!preg_match('/^n:(' . implode('|', NAMAZ_KEYS) . ')$/', $ref)) {
        throw new InvalidArgumentException('ref');
    }
    // $table — из белого списка выше, в запрос подставляется безопасно.
    if (!empty($in[$flag])) {
        db()->prepare("INSERT OR IGNORE INTO $table (user_id, ref, date) VALUES (?, ?, ?)")->execute([$uid, $ref, today()]);
        // Отметили в приложении — убрать кнопки у сегодняшнего напоминания в чате.
        $st = db()->prepare('SELECT msg_id FROM sent WHERE user_id = ? AND ref = ? AND date = ? AND msg_id IS NOT NULL');
        $st->execute([$uid, $ref, today()]);
        if ($msgId = (int) $st->fetchColumn()) {
            tg_close_reminder($uid, $msgId, '');
        }
    } else {
        db()->prepare("DELETE FROM $table WHERE user_id = ? AND ref = ? AND date = ?")->execute([$uid, $ref, today()]);
    }
}

function save_settings(int $uid, array $in): void
{
    if (array_key_exists('namaz_enabled', $in)) {
        db()->prepare('UPDATE users SET namaz_enabled = ? WHERE id = ?')->execute([(int) (bool) $in['namaz_enabled'], $uid]);
    }
    if (array_key_exists('namaz_before', $in)) {
        $b = (int) $in['namaz_before'];
        if (!in_array($b, REMIND_BEFORE, true)) {
            throw new InvalidArgumentException('namaz_before');
        }
        db()->prepare('UPDATE users SET namaz_before = ? WHERE id = ?')->execute([$b, $uid]);
    }
    if (isset($in['prayers']) && is_array($in['prayers'])) {
        $st = db()->prepare('INSERT INTO namaz_settings (user_id, prayer, enabled) VALUES (?, ?, ?)
                             ON CONFLICT(user_id, prayer) DO UPDATE SET enabled = excluded.enabled');
        foreach ($in['prayers'] as $k => $v) {
            if (in_array($k, NAMAZ_KEYS, true)) {
                $st->execute([$uid, $k, (int) (bool) $v]);
            }
        }
    }
}
