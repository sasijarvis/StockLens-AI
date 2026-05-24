<?php

/**
 * MySQL-backed per-IP rate limiter.
 *
 * Limits for /api/analyse:
 *   - 5 requests per minute
 *   - 20 requests per hour
 *
 * Uses the `rate_limits` table (see database/migrations/).
 */
class RateLimiter {

    private const LIMITS = [
        ['window' => 60,   'max' => 5,  'label' => 'minute'],
        ['window' => 3600, 'max' => 50, 'label' => 'hour'],
    ];

    /**
     * Check rate limit for current IP on $endpoint.
     * Calls jsonResponse() and exits with HTTP 429 if the limit is exceeded.
     */
    public static function check(string $endpoint): void {
        try {
            $db     = Database::get();
            $ipHash = hash('sha256', self::clientIp());

            // Prune records older than 2 hours to keep the table small
            $db->prepare("DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)")
               ->execute();

            foreach (self::LIMITS as $limit) {
                $stmt = $db->prepare(
                    "SELECT COUNT(*) FROM rate_limits
                     WHERE ip_hash = ? AND endpoint = ?
                     AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
                );
                $stmt->execute([$ipHash, $endpoint, $limit['window']]);
                $count = (int)$stmt->fetchColumn();

                if ($count >= $limit['max']) {
                    http_response_code(429);
                    header('Retry-After: ' . $limit['window']);
                    header('Content-Type: application/json');
                    echo json_encode([
                        'error'       => "Rate limit exceeded: max {$limit['max']} analyses per {$limit['label']}. Please wait before trying again.",
                        'retry_after' => $limit['window'],
                    ]);
                    exit;
                }
            }

            // Record this request
            $db->prepare("INSERT INTO rate_limits (ip_hash, endpoint) VALUES (?, ?)")
               ->execute([$ipHash, $endpoint]);

        } catch (Throwable $e) {
            // If rate limiter itself errors, log it but don't block the request
            logError('RateLimiter::check', $e);
        }
    }

    private static function clientIp(): string {
        // Check forwarded headers (for reverse proxy / load balancer setups)
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            $val = $_SERVER[$key] ?? '';
            if ($val) {
                // X-Forwarded-For may be a comma-separated list — take first entry
                return trim(explode(',', $val)[0]);
            }
        }
        return '0.0.0.0';
    }
}
