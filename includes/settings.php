<?php
/**
 * Application settings stored in the `settings` table (key/value pairs).
 */

/** Load all settings, caching them in a global for the request. */
function settings(): array
{
    if (isset($GLOBALS['__settings_cache'])) {
        return $GLOBALS['__settings_cache'];
    }

    $cache = [];
    try {
        foreach (db()->query('SELECT setting_key, setting_value FROM settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    } catch (PDOException $e) {
        error_log('Unable to load settings: ' . $e->getMessage());
        $cache = [];
    }

    $GLOBALS['__settings_cache'] = $cache;
    return $cache;
}

/** Force the settings cache to be reloaded on the next read. */
function flush_settings_cache(): void
{
    unset($GLOBALS['__settings_cache']);
}

/** Read a setting value with a default fallback. */
function setting(string $key, $default = null)
{
    $all = settings();
    return array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== ''
        ? $all[$key]
        : $default;
}

/** Read a boolean setting ("1"/"0"). */
function setting_bool(string $key, bool $default = false): bool
{
    return (string) setting($key, $default ? '1' : '0') === '1';
}

/** Persist a setting value. */
function set_setting(string $key, $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, (string) $value]);
    flush_settings_cache();
}
