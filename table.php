<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/config.php';

if (!isset($connect) || !($connect instanceof mysqli)) {
    http_response_code(500);
    exit('خطا: اتصال MySQL در فایل config.php تعریف نشده است.');
}

try {
    $connect->set_charset('utf8mb4');

    $tables = [
        "CREATE TABLE IF NOT EXISTS `user` (
            `id` BIGINT NOT NULL PRIMARY KEY,
            `step` VARCHAR(20) NOT NULL DEFAULT '',
            `step2` VARCHAR(20) NOT NULL DEFAULT '',
            `coin` BIGINT NOT NULL DEFAULT 0,
            `member` BIGINT NOT NULL DEFAULT 0,
            `name` VARCHAR(50) NOT NULL DEFAULT '',
            `photo` VARCHAR(100) NOT NULL DEFAULT '',
            `sex` VARCHAR(15) NOT NULL DEFAULT '',
            `username` VARCHAR(100) NOT NULL DEFAULT '',
            `city` VARCHAR(50) NOT NULL DEFAULT '',
            `old` BIGINT NOT NULL DEFAULT 0,
            `bio` VARCHAR(200) NOT NULL DEFAULT '',
            `stats` VARCHAR(50) NOT NULL DEFAULT '',
            `like` BIGINT NOT NULL DEFAULT 0,
            `dislike` BIGINT NOT NULL DEFAULT 0,
            `blocklist` VARCHAR(1000) NOT NULL DEFAULT '',
            `frindlist` VARCHAR(1000) NOT NULL DEFAULT '',
            `liker` VARCHAR(2000) NOT NULL DEFAULT '',
            `gps` VARCHAR(20) NOT NULL DEFAULT '',
            `baner` VARCHAR(100) NOT NULL DEFAULT '',
            `daily` VARCHAR(10) NOT NULL DEFAULT '',
            `side` BIGINT NOT NULL DEFAULT 0,
            `time` VARCHAR(20) NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `chat` (
            `user_id` BIGINT NOT NULL PRIMARY KEY
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `chatvip` (
            `user_id` BIGINT NOT NULL PRIMARY KEY,
            `type` VARCHAR(50) NOT NULL DEFAULT '',
            `stats` VARCHAR(100) NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `daily` (
            `run` VARCHAR(20) NOT NULL PRIMARY KEY,
            `time` VARCHAR(20) NOT NULL DEFAULT '',
            `user` INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `sendall` (
            `run` VARCHAR(20) NOT NULL PRIMARY KEY,
            `step` VARCHAR(20) NOT NULL DEFAULT '',
            `msgid` VARCHAR(20) NOT NULL DEFAULT '',
            `text` VARCHAR(2000) NOT NULL DEFAULT '',
            `chat` VARCHAR(30) NOT NULL DEFAULT '',
            `user` INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `msgpm` (
            `codemsg` BIGINT NOT NULL,
            `matn` TEXT NOT NULL,
            `ferestande` BIGINT NOT NULL,
            `idkarbar` BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    foreach ($tables as $sql) {
        $connect->query($sql);
    }

    echo 'جداول دیتابیس با موفقیت بررسی/ایجاد شدند.';
} catch (mysqli_sql_exception $e) {
    error_log('Database table setup error: ' . $e->getMessage());
    http_response_code(500);
    echo 'خطا در ساخت جداول دیتابیس. تنظیمات اتصال و لاگ خطای PHP را بررسی کنید.';
}
