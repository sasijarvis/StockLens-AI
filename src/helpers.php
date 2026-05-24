<?php

function logError(string $context, Throwable $e): void {
    $dir = dirname(__DIR__) . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $msg = sprintf("[%s] %s: %s in %s:%d\n",
        date('Y-m-d H:i:s'), $context, $e->getMessage(),
        basename($e->getFile()), $e->getLine()
    );
    error_log($msg, 3, $dir . '/app.log');
    if (env('APP_DEBUG') === 'true') {
        error_log($msg); // also to stderr in debug mode
    }
}

function validateSymbol(string $symbol): string {
    $clean = strtoupper(preg_replace('/[^A-Z0-9&\-]/i', '', $symbol));
    if (strlen($clean) < 1 || strlen($clean) > 20) {
        throw new InvalidArgumentException("Invalid symbol: $symbol");
    }
    return $clean;
}

function formatCr(float|null $val, int $decimals = 0): string {
    if ($val === null) return 'N/A';
    if (abs($val) >= 1_00_000) return 'Rs ' . number_format($val / 1_00_000, 1) . 'L Cr';
    return 'Rs ' . number_format($val, $decimals) . ' Cr';
}

function formatPct(float|null $val, int $decimals = 1): string {
    if ($val === null) return 'N/A';
    $sign = $val > 0 ? '+' : '';
    return $sign . number_format($val, $decimals) . '%';
}

function formatX(float|null $val, int $decimals = 1): string {
    if ($val === null) return 'N/A';
    return number_format($val, $decimals) . 'x';
}

function naIfNull(mixed $val): string {
    return ($val !== null && $val !== '') ? (string)$val : 'N/A';
}

function verdictClass(string $verdict): string {
    return match(strtolower($verdict)) {
        'buy'   => 'buy',
        'avoid' => 'avoid',
        default => 'hold',
    };
}

function verdictIcon(string $verdict): string {
    return match(strtolower($verdict)) {
        'buy'   => '▲',
        'avoid' => '▼',
        default => '●',
    };
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

function jsonResponse(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    header('X-Accel-Buffering: no');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (ob_get_level() > 0) ob_end_flush();
    flush();
    exit;
}
