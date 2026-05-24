<?php

function env(string $key, mixed $default = null): mixed {
    static $loaded = false;
    static $vars = [];

    if (!$loaded) {
        $file = dirname(__DIR__) . '/.env';
        if (file_exists($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with(trim($line), '#')) continue;
                if (!str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $vars[trim($k)] = trim($v);
            }
        }
        $loaded = true;
    }

    return $vars[$key] ?? $default;
}
