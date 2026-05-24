<?php

class OpenRouterClient {

    // Recommended models on OpenRouter — easily switchable
    const MODELS = [
        'claude-sonnet'  => 'anthropic/claude-sonnet-4-5',
        'claude-haiku'   => 'anthropic/claude-haiku-4-5',
        'gpt4o'          => 'openai/gpt-4o',
        'gemini-pro'     => 'google/gemini-pro-1.5',
        'llama-70b'      => 'meta-llama/llama-3.1-70b-instruct',
        'deepseek'       => 'deepseek/deepseek-r1',
    ];

    public function analyse(string $systemPrompt, string $userPrompt): array {
        $model   = env('OPENROUTER_MODEL', 'anthropic/claude-sonnet-4-5');
        $maxTok  = (int)env('OPENROUTER_MAX_TOKENS', 2000);
        $apiKey  = env('OPENROUTER_API_KEY');

        if (!$apiKey) {
            throw new RuntimeException('OPENROUTER_API_KEY not set in .env');
        }

        $payload = json_encode([
            'model'       => $model,
            'max_tokens'  => $maxTok,
            'temperature' => 0.3,   // Low temperature for factual financial analysis
            'system'      => $systemPrompt,
            'messages'    => [['role' => 'user', 'content' => $userPrompt]],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
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

        if ($curlErr) {
            throw new RuntimeException("OpenRouter connection error: $curlErr");
        }
        if ($httpCode === 429) {
            throw new RuntimeException('OpenRouter rate limit hit. Please try again in a moment.');
        }
        if ($httpCode !== 200 || !$body) {
            throw new RuntimeException("OpenRouter API error: HTTP $httpCode");
        }

        $response = json_decode($body, true);
        if (isset($response['error'])) {
            throw new RuntimeException('OpenRouter error: ' . ($response['error']['message'] ?? 'Unknown error'));
        }

        $text = $response['choices'][0]['message']['content'] ?? '';
        if (empty($text)) {
            throw new RuntimeException('OpenRouter returned empty response.');
        }

        $sections = self::parseSections($text);

        return [
            'raw'            => $text,
            'sections'       => $sections,
            'model_used'     => $response['model'] ?? $model,
            'prompt_tokens'  => $response['usage']['prompt_tokens']     ?? 0,
            'output_tokens'  => $response['usage']['completion_tokens'] ?? 0,
        ];
    }

    private static function parseSections(string $text): array {
        $sections = [];

        // Normalise line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Match ## Heading (with optional ** bold markers) then content until next ## or end
        preg_match_all('/^##\s+\*{0,2}(.+?)\*{0,2}\s*\n(.*?)(?=\n##\s|\z)/ms', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $heading = trim($m[1]);
            // Normalise key: lowercase, replace non-alphanumeric runs with underscore, trim underscores
            $key = strtolower(preg_replace('/[^a-z0-9]+/', '_', $heading));
            $key = trim($key, '_');
            $sections[$key] = trim($m[2]);
        }

        // Log what keys were parsed (helps debug)
        if (env('APP_DEBUG') === 'true') {
            error_log('Parsed section keys: ' . implode(', ', array_keys($sections)));
        }

        // Extract verdict label (Buy / Hold / Avoid) — check verdict section first
        $verdictText = $sections['verdict'] ?? $text;
        preg_match('/\b(Buy|Hold|Avoid)\b/i', $verdictText, $vm);
        $sections['verdict_label'] = isset($vm[1]) ? ucfirst(strtolower($vm[1])) : 'Hold';

        // Extract confidence score integer from confidence_score section or anywhere in text
        $confText = $sections['confidence_score'] ?? $sections['confidence'] ?? $text;
        preg_match('/\b(\d{1,3})\s*(?:\/\s*100|out of\s*100)?/i', $confText, $cm);
        $raw = isset($cm[1]) ? (int)$cm[1] : 50;
        // Guard against matching a year (e.g. "2024") or price — confidence must be 0-100
        $sections['confidence_value'] = ($raw >= 0 && $raw <= 100) ? $raw : 50;

        return $sections;
    }
}
