<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Request/error lines that php -S "Accepted/Closing" does not show.
 * error_log() is forwarded by the built-in server; the bind-mounted
 * var/log/api.log file is visible on the host even when STDERR is not.
 */
final class OperationalLog
{
    public static function write(string $line): void
    {
        if (($_SERVER['APP_ENV'] ?? '') === 'test') {
            return;
        }

        error_log($line);
        $file = dirname(__DIR__, 2).'/var/log/api.log';
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents($file, '['.gmdate('Y-m-d H:i:s').'] '.$line."\n", FILE_APPEND | LOCK_EX);
    }
}
