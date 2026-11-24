<?php
declare(strict_types=1);

// Отдаёт страницу Mini App с заголовками безопасности: vhost общий с другим проектом,
// поэтому заголовки ставит само приложение. Telegram Mini App — без X-Frame-Options,
// в frame-ancestors домены Telegram (веб-клиенты открывают приложение в iframe).
header('Strict-Transport-Security: max-age=31536000');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: frame-ancestors 'self' https://web.telegram.org https://*.telegram.org");
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');

readfile(__DIR__ . '/index.html');
