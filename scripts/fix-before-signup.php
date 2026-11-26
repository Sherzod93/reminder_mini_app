<?php
declare(strict_types=1);

// Разовая правка 26.11.2026 (после выкатки Қазо дафтари):
// 1) убрать тестового пользователя 999000111 — его создавала проверка на сервере;
// 2) из истории — невыполненное, время которого наступило до регистрации пользователя
//    (намаз) или до создания задачи: пропустить его было нельзя;
// 3) Қазо дафтари без отметок адо пересобрать по исправленной истории и текущей проверке.
// Повторный запуск ничего не портит. php scripts/fix-before-signup.php
if (PHP_SAPI !== 'cli') {
    exit;
}

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/history.php';
require __DIR__ . '/../src/qazo.php';

$test = 999000111;
foreach (['done', 'skipped', 'sent', 'history', 'days_closed', 'namaz_log', 'namaz_settings', 'tasks', 'users'] as $t) {
    $col = $t === 'users' ? 'id' : 'user_id';
    $st = db()->prepare("DELETE FROM $t WHERE $col = ?");
    $st->execute([$test]);
    if ($st->rowCount()) {
        echo "test user: $t -{$st->rowCount()}\n";
    }
}

$users = db()->query('SELECT id, created_at FROM users')->fetchAll(PDO::FETCH_KEY_PAIR);
$tasksCreated = db()->query('SELECT id, created_at FROM tasks')->fetchAll(PDO::FETCH_KEY_PAIR);
$del = db()->prepare('DELETE FROM history WHERE user_id = ? AND date = ? AND ref = ?');
$removed = 0;
foreach (db()->query("SELECT user_id, date, ref, kind, time FROM history WHERE status = 'missed'")->fetchAll() as $h) {
    $since = $h['kind'] === 'task'
        ? ($tasksCreated[(int) substr($h['ref'], 2)] ?? null)
        : ($users[$h['user_id']] ?? null);
    if ($since && strtotime("{$h['date']} {$h['time']}") < strtotime($since . ' UTC')) {
        $del->execute([$h['user_id'], $h['date'], $h['ref']]);
        $removed++;
    }
}
echo "history: -$removed (до регистрации / создания задачи)\n";

$st = db()->query('DELETE FROM namaz_log WHERE made_up_at IS NULL');
echo "namaz_log: очищено {$st->rowCount()} без адо, пересборка:\n";
foreach (array_keys($users) as $uid) {
    $a = qazo_backfill_from_history((int) $uid);
    $b = qazo_check((int) $uid);
    echo "  user $uid: история +$a, вчера/сегодня +$b\n";
}
