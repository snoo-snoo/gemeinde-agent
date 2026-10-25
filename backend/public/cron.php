<?php

/**
 * All-Inkl KAS cron entry. KAS can only call a URL.
 *
 *   http://gemeinde.snoo.at/cron.php?token=CRON_TOKEN
 *
 * Interval: every minute. E-Mail-Filter: OK
 * The web request only starts a CLI process and returns. Queue work and the
 * analysis service stay out of the Apache process.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$project = dirname($root);
$log = $root.'/storage/logs/cron.log';
$lockPath = $root.'/storage/framework/cron.lock';

if (PHP_SAPI !== 'cli') {
    $token = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
    $expected = cron_env($root.'/.env', 'CRON_TOKEN');
    if ($expected === '' || ! hash_equals($expected, $token)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Forbidden\n";
        exit;
    }
    if (! function_exists('exec')) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "FAILED exec disabled\n";
        exit;
    }
    $php = cron_env($root.'/.env', 'CRON_PHP_BINARY');
    if ($php === '' || ! is_executable($php)) {
        $php = '/usr/bin/php84';
    }
    $command = 'nohup '.escapeshellarg($php).' '.escapeshellarg(__FILE__).' cli >> '.escapeshellarg($log).' 2>&1 &';
    exec($command);
    http_response_code(200);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "OK\n";
    exit;
}

$lock = fopen($lockPath, 'c');
if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$php = cron_env($root.'/.env', 'CRON_PHP_BINARY');
if ($php === '' || ! is_executable($php)) {
    $php = PHP_BINARY;
}

$stamp = date('c');
file_put_contents($log, "[{$stamp}] tick\n", FILE_APPEND);

$ensure = $project.'/services/analysis/host-ensure.sh';
if (is_executable($ensure)) {
    passthru(escapeshellarg($ensure), $ensureStatus);
    file_put_contents($log, "[{$stamp}] analysis {$ensureStatus}\n", FILE_APPEND);
}

passthru(
    escapeshellarg($php).' '.escapeshellarg($root.'/artisan').' schedule:run --no-interaction',
    $scheduleStatus,
);
file_put_contents($log, "[{$stamp}] schedule {$scheduleStatus}\n", FILE_APPEND);

passthru(
    escapeshellarg($php).' '.escapeshellarg($root.'/artisan').' queue:work --stop-when-empty --timeout=3600 --tries=1 --no-interaction',
    $queueStatus,
);
file_put_contents($log, "[{$stamp}] queue {$queueStatus}\n", FILE_APPEND);

flock($lock, LOCK_UN);
fclose($lock);
exit($scheduleStatus === 0 && $queueStatus === 0 ? 0 : 1);

function cron_env(string $path, string $key): string
{
    if (! is_file($path)) {
        return '';
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        if (trim($name) !== $key) {
            continue;
        }
        $value = trim($value);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }

    return '';
}
