<?php
declare(strict_types=1);

// Один раз после выкатки Қазо дафтари: пропущенные намазы из истории (дни до 26.11.2026) —
// в namaz_log: невыполненный — qazo, Хуфтон — qarz. Повторный запуск ничего не меняет.
// php scripts/backfill-qazo.php
if (PHP_SAPI !== 'cli') {
    exit;
}

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/history.php';
require __DIR__ . '/../src/qazo.php';

foreach (db()->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN) as $uid) {
    close_days((int) $uid); // сначала закрыть прошедшие дни, если cron не успел
    $n = qazo_backfill_from_history((int) $uid);
    echo "user $uid: +$n\n";
}
