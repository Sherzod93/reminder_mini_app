<?php
// Открытая конфигурация. Секреты (BOT_TOKEN, WEBHOOK_SECRET) — только в .env.
return [
    // Публичный адрес Mini App (HTTPS обязателен для Telegram).
    // Корень домена занят ботом toshkentkaboblari — приложение живёт в подпапке.
    'app_url'          => 'https://sherzod.devx.uz/reminder',
    'timezone'         => 'Asia/Tashkent',
    'db_path'          => __DIR__ . '/../data/reminder.sqlite',

    // Источник времени намаза.
    'namaz_url'        => 'https://namoz-vaqti.uz/kirill/namoz-vaqtlari/toshkent-shahri?format=json',
    // Намазы, которые включены у нового пользователя по умолчанию.
    'namaz_default_on' => ['bomdod', 'peshin', 'asr', 'shom', 'xufton'],

    // Сколько секунд действительна подпись initData от Telegram.
    'init_data_max_age' => 86400,
    // Если cron пропустил минуту — напоминание ещё уходит в течение этого окна (минуты).
    'send_window_min'   => 10,
    // Невыполненное напоминается повторно с этим интервалом (минуты): задача — до конца дня,
    // намаз — до времени следующего намаза. Кнопка «Бугун вақтим йўқ» прекращает повторы.
    'repeat_every_min'  => 15,
    // Лимит задач на одного пользователя.
    'max_tasks'         => 100,
];
