<?php

/**
 * Lightweight AI news sentiment scorer.
 * Runs a separate, cheap AI call (Gemini Flash) in parallel with the main analysis.
 * Scores each news item as BULLISH / BEARISH / NEUTRAL with an impact summary.
 */
class NewsSentimentAnalyser {

    private const SENTIMENT_MODEL = 'google/gemini-2.5-flash-lite';

    /**
     * Score each news item and return an overall sentiment pulse.
     * @param  array  $newsItems  — array of {title, source, published_at}
     * @param  string $symbol     — NSE symbol for context
     * @return array  {items: [...], overall_score: int, overall_label: string}
     */
    public static function analyse(array $newsItems, string $symbol): array {
        if (empty($newsItems)) {
            return ['items' => [], 'overall_score' => 50, 'overall_label' => 'NEUTRAL'];
        }

        $apiKey = env('OPENROUTER_API_KEY');
        if (!$apiKey) return self::fallback($newsItems);

        $newsLines = '';
        foreach (array_slice($newsItems, 0, 10) as $i => $item) {
            $newsLines .= ($i + 1) . '. ' . ($item['title'] ?? '') . "\n";
        }

        $prompt = 'You are scoring news sentiment for ' . $symbol . ' (Indian NSE stock).' . "\n\n"
            . 'For EACH news item below, output EXACTLY one line in this format:' . "\n"
            . '[item_number]|[BULLISH/BEARISH/NEUTRAL]|[score_0_to_100]|[max_10_word_impact_summary]' . "\n\n"
            . 'Rules:' . "\n"
            . '- BULLISH = positive for stock price (score 60-100)' . "\n"
            . '- BEARISH = negative for stock price (score 0-40)' . "\n"
            . '- NEUTRAL = no clear price impact (score 41-59)' . "\n"
            . '- Score reflects STRENGTH of sentiment (100 = very strong bullish, 0 = very strong bearish)' . "\n"
            . '- Impact summary must be max 10 words, specific to the headline' . "\n"
            . '- Output ONLY the pipe-delimited lines, nothing else' . "\n\n"
            . 'NEWS ITEMS:' . "\n"
            . $newsLines;

        $payload = json_encode([
            'model'      => self::SENTIMENT_MODEL,
            'max_tokens' => 400,
            'temperature'=> 0.1,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'HTTP-Referer: ' . env('APP_URL', 'http://localhost'),
                'X-Title: StockLens Sentiment',
            ],
        ]);

        $body     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$body) return self::fallback($newsItems);

        $json = json_decode($body, true);
        $text = $json['choices'][0]['message']['content'] ?? '';
        if (!$text) return self::fallback($newsItems);

        return self::parse($text, $newsItems);
    }

    private static function parse(string $text, array $newsItems): array {
        $lines  = array_filter(array_map('trim', explode("\n", $text)));
        $scored = [];
        $totalScore = 0;
        $count      = 0;

        foreach ($lines as $line) {
            $parts = explode('|', $line);
            if (count($parts) < 4) continue;

            $idx     = (int)trim($parts[0]) - 1;
            $label   = strtoupper(trim($parts[1]));
            $score   = max(0, min(100, (int)trim($parts[2])));
            $summary = trim($parts[3]);

            if (!in_array($label, ['BULLISH', 'BEARISH', 'NEUTRAL'])) continue;
            if (!isset($newsItems[$idx])) continue;

            $scored[] = [
                'title'      => $newsItems[$idx]['title']       ?? '',
                'source'     => $newsItems[$idx]['source']      ?? '',
                'published_at'=> $newsItems[$idx]['published_at'] ?? null,
                'link'       => $newsItems[$idx]['link']        ?? '#',
                'sentiment'  => $label,
                'score'      => $score,
                'impact'     => $summary,
            ];

            $totalScore += $score;
            $count++;
        }

        // Fill any unscored items as NEUTRAL
        foreach ($newsItems as $i => $item) {
            $alreadyScored = false;
            foreach ($scored as $s) {
                if ($s['title'] === ($item['title'] ?? '')) { $alreadyScored = true; break; }
            }
            if (!$alreadyScored) {
                $scored[] = [
                    'title'       => $item['title']       ?? '',
                    'source'      => $item['source']      ?? '',
                    'published_at'=> $item['published_at'] ?? null,
                    'link'        => $item['link']        ?? '#',
                    'sentiment'   => 'NEUTRAL',
                    'score'       => 50,
                    'impact'      => 'No clear price impact identified',
                ];
                $totalScore += 50;
                $count++;
            }
        }

        $overallScore = $count > 0 ? round($totalScore / $count) : 50;
        $overallLabel = $overallScore >= 60 ? 'BULLISH' : ($overallScore <= 40 ? 'BEARISH' : 'NEUTRAL');

        return [
            'items'         => $scored,
            'overall_score' => $overallScore,
            'overall_label' => $overallLabel,
        ];
    }

    /** Graceful fallback: mark all items NEUTRAL when AI call fails. */
    private static function fallback(array $newsItems): array {
        $items = [];
        foreach (array_slice($newsItems, 0, 10) as $n) {
            $items[] = [
                'title'        => $n['title']        ?? '',
                'source'       => $n['source']       ?? '',
                'published_at' => $n['published_at']  ?? null,
                'link'         => $n['link']          ?? '#',
                'sentiment'    => 'NEUTRAL',
                'score'        => 50,
                'impact'       => 'Sentiment analysis unavailable',
            ];
        }
        return ['items' => $items, 'overall_score' => 50, 'overall_label' => 'NEUTRAL'];
    }
}
