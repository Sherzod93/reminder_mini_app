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
    // Невыполненная задача напоминается повторно с этим интервалом (минуты) до конца дня.
    // Кнопка «Бугун вақтим йўқ» прекращает повторы.
    'repeat_every_min'  => 15,
    // Неотмеченный намаз: вместо повторов — одно напоминание за столько минут до следующего
    // намаза (для Хуфтона — до полуночи).
    'namaz_warn_before_next_min' => 45,
    // Лимит задач на одного пользователя.
    'max_tasks'         => 100,
];
