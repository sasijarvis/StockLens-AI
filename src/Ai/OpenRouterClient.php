<?php

class OpenRouterClient {

    const MODELS = [
        'claude-sonnet'  => 'anthropic/claude-sonnet-4-5',
        'claude-haiku'   => 'anthropic/claude-haiku-4-5',
        'gpt4o'          => 'openai/gpt-4o',
        'gemini-pro'     => 'google/gemini-pro-1.5',
        'llama-70b'      => 'meta-llama/llama-3.1-70b-instruct',
        'deepseek'       => 'deepseek/deepseek-r1',
    ];

    public const SELECTABLE_MODELS = [
        'fast'     => ['id' => 'google/gemini-2.5-flash-lite', 'label' => 'Fast (Gemini Flash Lite)', 'desc' => 'Fastest, lowest cost'],
        'balanced' => ['id' => 'google/gemini-2.5-flash-lite', 'label' => 'Balanced (Gemini Flash Lite)', 'desc' => 'Good quality, fast'],
        'best'     => ['id' => 'google/gemini-2.5-flash-lite', 'label' => 'Best (Gemini Flash Lite)', 'desc' => 'Highest quality'],
    ];

    public function analyse(string $systemPrompt, string $userPrompt, ?string $modelOverride = null): array {
        $model   = $this->resolveModel($modelOverride);
        $maxTok  = (int)env('OPENROUTER_MAX_TOKENS', 4000);
        $apiKey  = env('OPENROUTER_API_KEY');
        error_log('[StockLens] Using model: ' . $model);

        if (!$apiKey) {
            throw new RuntimeException('OPENROUTER_API_KEY not set in .env');
        }

        $isGemini = str_contains($model, 'gemini');

        if ($isGemini) {
            $messages = [[
                'role'    => 'user',
                'content' => $systemPrompt . "\n\n---\n\n" . $userPrompt
                           . "\n\nIMPORTANT: You MUST respond using EXACTLY these 11 section headings in this order:\n"
                           . "## Price and Technical Setup\n## Support and Resistance\n## Valuation\n"
                           . "## Business Quality\n## Balance Sheet Health\n## Cash Flow Quality\n"
                           . "## Catalysts and Risks\n## Trade Setup\n## Scenario Analysis\n"
                           . "## Conviction Matrix\n## Verdict\n"
                           . "Do not write anything before the first heading. Start immediately with ## Price and Technical Setup.",
            ]];
        } else {
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ];
        }

        $payload = json_encode([
            'model'       => $model,
            'max_tokens'  => $maxTok,
            'temperature' => 0.3,
            'messages'    => $messages,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'HTTP-Referer: ' . env('APP_URL', 'http://localhost'),
                'X-Title: AI Stock Analysis Platform',
            ],
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr)              throw new RuntimeException("OpenRouter connection error: $curlErr");
        if ($httpCode === 429)     throw new RuntimeException('OpenRouter rate limit hit. Please try again in a moment.');
        if ($httpCode !== 200 || !$body) throw new RuntimeException("OpenRouter API error: HTTP $httpCode");

        $response = json_decode($body, true);
        if (isset($response['error'])) {
            throw new RuntimeException('OpenRouter error: ' . ($response['error']['message'] ?? 'Unknown error'));
        }

        $text = $response['choices'][0]['message']['content'] ?? '';
        if (empty($text)) throw new RuntimeException('OpenRouter returned empty response.');

        $sections = self::parseSections($text);

        return [
            'raw'           => $text,
            'sections'      => $sections,
            'model_used'    => $response['model'] ?? $model,
            'prompt_tokens' => $response['usage']['prompt_tokens']    ?? 0,
            'output_tokens' => $response['usage']['completion_tokens'] ?? 0,
        ];
    }

    // Public wrappers used by analyse_status.php to re-parse raw DB text
    public static function parseTradeSetupPublic(string $text): array {
        return self::parseTradeSetup($text);
    }
    public static function parseScenariosPublic(string $text): array {
        return self::parseScenarios($text);
    }

    // ── Conviction Matrix parser ──────────────────────────────────────────────
    private static function parseConvictionMatrix(string $text): array {
        $dims    = ['TECHNICAL', 'VALUATION', 'QUALITY', 'NEWS', 'MOMENTUM'];
        $result  = [];
        $reasons = [];
        $clean   = preg_replace('/\*+/', '', $text);

        foreach ($dims as $dim) {
            if (preg_match('/' . $dim . '[^0-9\n]{0,15}(\d{1,3})\s*(?:\/\s*100)?\s*[-–—:]*\s*([^\n]*)/i', $clean, $m)) {
                $result[$dim]  = max(0, min(100, (int)$m[1]));
                $reasons[$dim] = trim(preg_replace('/\*+/', '', $m[2]));
            } else {
                $result[$dim]  = null;
                $reasons[$dim] = '';
            }
        }

        return ['scores' => $result, 'reasons' => $reasons];
    }

    // ── Trade Setup parser ────────────────────────────────────────────────────
    private static function parseTradeSetup(string $text): array {
        $clean = preg_replace('/\*+/', '', $text);

        // Normalise single-line output: insert newline before each keyword
        foreach (['ENTRY ZONE', 'TARGET 1', 'TARGET 2', 'STOP LOSS', 'TIMEFRAME', 'RISK/REWARD'] as $kw) {
            $clean = str_ireplace($kw, "\n" . $kw, $clean);
        }
        $clean = ltrim($clean);

        $matchPrice = function(string $pat) use ($clean): mixed {
            if (preg_match($pat, $clean, $m)) {
                $price = trim(preg_replace('/^Rs?\s*/i', '', $m[1]));
                $pct   = isset($m[2]) ? trim($m[2]) : null;
                return $pct ? ['price' => $price, 'pct' => $pct] : $price;
            }
            return null;
        };
        $matchPlain = function(string $pat) use ($clean): ?string {
            return preg_match($pat, $clean, $m) ? trim($m[1]) : null;
        };

        $entry = null;
        if (preg_match('/ENTRY\s+ZONE\s*:\s*(Rs?\s*[\d,.\s\-–]+?)(?:\s*\(|\s*–\s*[A-Za-z]|\s*—\s*[A-Za-z]|$)/im', $clean, $m)) {
            $entry = trim(preg_replace('/^Rs?\s*/i', '', trim($m[1])));
        }

        $target1  = $matchPrice('/TARGET\s+1\s*:\s*(Rs?\s*[\d,.]+)\s*\(([^)]+)\)/im');
        $target2  = $matchPrice('/TARGET\s+2\s*:\s*(Rs?\s*[\d,.]+)\s*\(([^)]+)\)/im');
        $stopLoss = $matchPrice('/STOP\s*LOSS\s*:\s*(Rs?\s*[\d,.]+)\s*\(([^)]+)\)/im');
        $timeframe = $matchPlain('/TIMEFRAME\s*:\s*([^\n]+)/im');
        $rr        = $matchPlain('/RISK\s*\/\s*REWARD\s*:\s*([^\n]+)/im');

        return [
            'entry'     => $entry,
            'target1'   => $target1,
            'target2'   => $target2,
            'stop_loss' => $stopLoss,
            'timeframe' => $timeframe ? trim($timeframe) : null,
            'rr'        => $rr        ? trim($rr)        : null,
            'raw'       => trim($text),
        ];
    }

    // ── Scenario Analysis parser ──────────────────────────────────────────────
    private static function parseScenarios(string $text): array {
        $cases = ['BULL' => null, 'BASE' => null, 'BEAR' => null];
        $clean = preg_replace('/\*+/', '', $text);

        // Normalise single-line output: insert newline before each scenario keyword
        foreach (['BULL CASE', 'BASE CASE', 'BEAR CASE'] as $kw) {
            $clean = str_ireplace($kw, "\n" . $kw, $clean);
        }
        // Also catch bare BULL/BASE/BEAR followed by ( or :
        $clean = preg_replace('/(\s)(BULL|BASE|BEAR)(\s*[\(:])/i', "$1\n$2$3", $clean);
        $clean = ltrim($clean);

        foreach (array_keys($cases) as $case) {
            $patterns = [
                '/' . $case . '(?:\s+CASE)?\s*\(([^)]+)\)\s*:?\s*Rs?\s*([\d,.]+)\s*[-–—]\s*([^\n]+)/im',
                '/' . $case . '(?:\s+CASE)?\s*:\s*([+\-]?\d+[^,\n]*),?\s*Rs?\s*([\d,.]+)\s*[-–—]\s*([^\n]+)/im',
            ];
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $clean, $m)) {
                    $cases[$case] = [
                        'pct'       => trim($m[1]),
                        'price'     => trim($m[2]),
                        'condition' => trim(preg_replace('/\*+/', '', $m[3])),
                    ];
                    break;
                }
            }
        }

        return ['cases' => $cases, 'raw' => trim($text)];
    }

    // ── Model resolver ────────────────────────────────────────────────────────
    private function resolveModel(?string $override): string {
        if ($override && isset(self::SELECTABLE_MODELS[$override])) {
            return self::SELECTABLE_MODELS[$override]['id'];
        }
        if ($override && strlen($override) > 5) {
            return $override;
        }
        return env('OPENROUTER_MODEL', 'anthropic/claude-sonnet-4-5');
    }

    // ── Section parser ────────────────────────────────────────────────────────
    private static function parseSections(string $text): array {
        $sections = [];
        $text     = str_replace(["\r\n", "\r"], "\n", $text);
        $lines    = explode("\n", $text);
        $curKey   = null;
        $curBody  = [];

        foreach ($lines as $line) {
            if (preg_match('/^(?:\*{0,2})\s*#{1,3}\s+\*{0,2}(.+?)\*{0,2}\s*$/', $line, $m)) {
                if ($curKey !== null) {
                    $sections[$curKey] = trim(implode("\n", $curBody));
                }
                $heading = trim(preg_replace('/[*#]+/', '', trim($m[1])));
                $curKey  = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($heading)), '_');
                $curBody = [];
            } elseif ($curKey !== null) {
                $curBody[] = $line;
            }
        }
        if ($curKey !== null) {
            $sections[$curKey] = trim(implode("\n", $curBody));
        }

        error_log('[StockLens] Parsed section keys: ' . implode(', ', array_keys($sections)));

        // Verdict label
        $verdictText = $sections['verdict'] ?? $text;
        preg_match('/\b(Buy|Hold|Avoid)\b/i', $verdictText, $vm);
        $sections['verdict_label'] = isset($vm[1]) ? ucfirst(strtolower($vm[1])) : 'Hold';

        // Conviction matrix
        $convText = $sections['conviction_matrix'] ?? $sections['conviction'] ?? $text;
        $conv     = self::parseConvictionMatrix($convText);
        $sections['conviction_parsed'] = $conv;
        $scores   = array_filter($conv['scores'] ?? [], fn($v) => $v !== null);
        $sections['confidence_value'] = !empty($scores)
            ? (int)round(array_sum($scores) / count($scores))
            : 50;

        // Trade setup
        $sections['trade_setup_parsed'] = self::parseTradeSetup($sections['trade_setup'] ?? '');

        // Scenarios
        $sections['scenarios_parsed'] = self::parseScenarios(
            $sections['scenario_analysis'] ?? $sections['scenarios'] ?? ''
        );

        return $sections;
    }
}
