<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

function cfg(string $key): mixed
{
    static $c = null;
    $c ??= require APP_ROOT . '/config/config.php';
    return $c[$key] ?? null;
}

/** Секреты: сначала переменные окружения, затем файл .env. */
function secret(string $key): string
{
    static $file = null;
    if ($file === null) {
        $file = [];
        $path = APP_ROOT . '/.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $file[trim($k)] = trim(trim($v), "\"'");
            }
        }
    }
    $v = getenv($key);
    return ($v !== false && $v !== '') ? $v : ($file[$key] ?? '');
}

date_default_timezone_set((string) cfg('timezone'));

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $path = (string) cfg('db_path');
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    $isNew = !is_file($path);
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // Не WAL: сайт (PHP-FPM) и cron работают под разными пользователями одной группы,
    // а файлы -wal/-shm создаются с umask того, кто открыл базу первым.
    $pdo->exec('PRAGMA journal_mode = DELETE; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    db_migrate($pdo);
    if ($isNew) {
        @chmod($path, 0660); // писать должны и сайт, и cron (одна группа)
    }
    return $pdo;
}

function db_migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY,           -- Telegram user id
            first_name    TEXT    NOT NULL DEFAULT '',
            username      TEXT    NOT NULL DEFAULT '',
            can_message   INTEGER NOT NULL DEFAULT 0,    -- нажал /start в боте и не блокировал его
            namaz_enabled INTEGER NOT NULL DEFAULT 1,
            namaz_before  INTEGER NOT NULL DEFAULT 0,    -- за сколько минут напоминать о намазе
            created_at    TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS namaz_settings (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            prayer  TEXT    NOT NULL,
            enabled INTEGER NOT NULL,
            PRIMARY KEY (user_id, prayer)
        );
        CREATE TABLE IF NOT EXISTS tasks (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            title         TEXT    NOT NULL,
            note          TEXT    NOT NULL DEFAULT '',
            time          TEXT    NOT NULL,               -- HH:MM по Ташкенту
            days          TEXT    NOT NULL DEFAULT '1234567', -- ISO-дни недели: 1=Пн … 7=Вс
            remind_before INTEGER NOT NULL DEFAULT 0,
            enabled       INTEGER NOT NULL DEFAULT 1,
            created_at    TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS tasks_user ON tasks(user_id);
        -- ref: 't:<task id>' или 'n:<prayer>'
        CREATE TABLE IF NOT EXISTS done (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            ref     TEXT    NOT NULL,
            date    TEXT    NOT NULL,
            PRIMARY KEY (user_id, ref, date)
        );
        CREATE TABLE IF NOT EXISTS sent (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            ref     TEXT    NOT NULL,
            date    TEXT    NOT NULL,
            PRIMARY KEY (user_id, ref, date)
        );
        -- Итог прошедшего дня: что было в плане и результат (done | skipped | missed).
        CREATE TABLE IF NOT EXISTS history (
            user_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            date     TEXT    NOT NULL,
            ref      TEXT    NOT NULL,
            kind     TEXT    NOT NULL,          -- task | namaz
            title    TEXT    NOT NULL,
            time     TEXT    NOT NULL,
            end_time TEXT    NOT NULL DEFAULT '',
            status   TEXT    NOT NULL,
            PRIMARY KEY (user_id, date, ref)
        );
        CREATE TABLE IF NOT EXISTS days_closed (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            date    TEXT    NOT NULL,
            PRIMARY KEY (user_id, date)
        );
        -- Қазо дафтари: намаз, не отмеченный до конца своего времени, — qazo; Хуфтон, не
        -- отмеченный до утреннего Бомдода, — qarz. made_up_at — когда восполнен (адо).
        CREATE TABLE IF NOT EXISTS namaz_log (
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            date       TEXT    NOT NULL,         -- день намаза (для Хуфтона — вечер, не утро)
            prayer     TEXT    NOT NULL,
            status     TEXT    NOT NULL,         -- qazo | qarz
            decided_at TEXT    NOT NULL,
            made_up_at TEXT,
            PRIMARY KEY (user_id, date, prayer)
        );
        -- «Сегодня нет времени»: повторы напоминаний на этот день прекращаются.
        CREATE TABLE IF NOT EXISTS skipped (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            ref     TEXT    NOT NULL,
            date    TEXT    NOT NULL,
            PRIMARY KEY (user_id, ref, date)
        );
        CREATE TABLE IF NOT EXISTS namaz_cache (
            date       TEXT PRIMARY KEY,
            data       TEXT NOT NULL,
            fetched_at TEXT NOT NULL
        );
    SQL);

    // Время окончания задачи (HH:MM, пусто — без окончания): повторы идут до него.
    $taskCols = array_column($pdo->query('PRAGMA table_info(tasks)')->fetchAll(), 'name');
    if (!in_array('end_time', $taskCols, true)) {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN end_time TEXT NOT NULL DEFAULT ''");
    }

    // Повторные напоминания: когда слали последний раз, id сообщения, сколько повторов.
    $cols = array_column($pdo->query('PRAGMA table_info(sent)')->fetchAll(), 'name');
    if (!in_array('last_at', $cols, true)) {
        $pdo->exec(<<<SQL
            ALTER TABLE sent ADD COLUMN last_at INTEGER NOT NULL DEFAULT 0;
            ALTER TABLE sent ADD COLUMN msg_id  INTEGER;
            ALTER TABLE sent ADD COLUMN repeats INTEGER NOT NULL DEFAULT 0;
            UPDATE sent SET last_at = CAST(strftime('%s', 'now') AS INTEGER);
        SQL);
    }
}

function user_upsert(array $u, ?bool $canMessage = null): void
{
    $st = db()->prepare(
        'INSERT INTO users (id, first_name, username) VALUES (:id, :fn, :un)
         ON CONFLICT(id) DO UPDATE SET first_name = excluded.first_name, username = excluded.username'
    );
    $st->execute([
        ':id' => (int) $u['id'],
        ':fn' => mb_substr((string) ($u['first_name'] ?? ''), 0, 100),
        ':un' => mb_substr((string) ($u['username'] ?? ''), 0, 100),
    ]);
    if ($canMessage !== null) {
        db()->prepare('UPDATE users SET can_message = ? WHERE id = ?')->execute([(int) $canMessage, (int) $u['id']]);
    }
}

function today(): string
{
    return date('Y-m-d');
}
