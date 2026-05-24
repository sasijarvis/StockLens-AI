<?php
/**
 * cron/rotate-logs.php — Log rotation
 * Schedule: 0 0 * * * php /path/to/cron/rotate-logs.php
 *
 * Rotates app.log when it exceeds 10 MB.
 * Keeps up to 5 generations: app.log → app.log.1 → app.log.2 … app.log.5
 */

$logDir  = dirname(__DIR__) . '/logs';
$logFile = $logDir . '/app.log';
$maxSize = 10 * 1024 * 1024; // 10 MB
$maxGen  = 5;

if (!file_exists($logFile)) {
    echo "No log file found at $logFile\n";
    exit(0);
}

$size = filesize($logFile);

if ($size < $maxSize) {
    echo "Log size is " . round($size / 1024 / 1024, 2) . " MB — no rotation needed.\n";
    exit(0);
}

echo "Rotating logs (current size: " . round($size / 1024 / 1024, 2) . " MB)…\n";

// Shift existing generations: .5 is deleted, .4→.5, .3→.4 …
for ($i = $maxGen; $i >= 1; $i--) {
    $old = $logFile . '.' . $i;
    $new = $logFile . '.' . ($i + 1);
    if (file_exists($old)) {
        if ($i === $maxGen) {
            unlink($old);
        } else {
            rename($old, $new);
        }
    }
}

// Rotate current log
rename($logFile, $logFile . '.1');
file_put_contents($logFile, ''); // create fresh empty log
chmod($logFile, 0644);

echo "Rotation complete. Previous log saved as app.log.1\n";

// Also clean old PHP error log if it exists
$phpLog = $logDir . '/php_errors.log';
if (file_exists($phpLog) && filesize($phpLog) > $maxSize) {
    rename($phpLog, $phpLog . '.1');
    file_put_contents($phpLog, '');
    echo "Rotated php_errors.log\n";
}
