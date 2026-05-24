<?php

class NewsApi {

    public function getNews(string $companyName, string $symbol): array {
        $encodedName   = urlencode($companyName);
        $encodedSymbol = urlencode($symbol);

        $requests = [
            'et' => ['url' => 'https://economictimes.indiatimes.com/rssfeedsdefault.cms?q=' . $encodedSymbol],
            'mc' => ['url' => 'https://www.moneycontrol.com/rss/buzzingstocks.xml'],
            'bs' => ['url' => 'https://www.business-standard.com/rss/markets-106.rss'],
            'fe' => ['url' => 'https://www.financialexpress.com/feed/'],
        ];

        $raws  = ParallelFetcher::fetchRaw($requests, 12);
        $items = [];
        $seen  = [];

        // Keywords to filter relevant articles
        $kw1 = strtolower($symbol);
        $kw2 = strtolower(explode(' ', $companyName)[0]);
        $keywords = array_filter([$kw1, $kw2], function($k) { return strlen($k) >= 3; });

        foreach ($raws as $source => $raw) {
            if (!$raw || strlen($raw) < 100) continue;
            $parsed = $this->parseRss($raw, $source);
            foreach ($parsed as $item) {
                $haystack = strtolower($item['title'] . ' ' . ($item['description'] ?? ''));
                $relevant = false;
                foreach ($keywords as $kw) {
                    if (strpos($haystack, $kw) !== false) {
                        $relevant = true;
                        break;
                    }
                }
                if (!$relevant) continue;
                $key = md5($item['title']);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $items[]    = $item;
                }
            }
        }

        // Fallback: if fewer than 3 relevant articles, return any market news
        if (count($items) < 3) {
            foreach ($raws as $source => $raw) {
                if (!$raw || strlen($raw) < 100) continue;
                $parsed = $this->parseRss($raw, $source);
                foreach ($parsed as $item) {
                    $key = md5($item['title']);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $items[]    = $item;
                    }
                }
                if (count($items) >= 10) break;
            }
        }

        // Sort by date desc
        usort($items, function($a, $b) {
            return strtotime($b['published_at'] ?? '0') - strtotime($a['published_at'] ?? '0');
        });

        error_log('[NewsApi] ' . $symbol . ' fetched ' . count($items) . ' items');

        return array_slice($items, 0, NEWS_MAX);
    }

    private function parseRss(string $xml, string $source = ''): array {
        $items = [];
        try {
            libxml_use_internal_errors(true);
            $feed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
            if (!$feed) return [];

            $channel = isset($feed->channel) ? $feed->channel : $feed;
            foreach ($channel->item ?? [] as $item) {
                $title = trim((string)($item->title ?? ''));
                if (!$title) continue;

                $link       = trim((string)($item->link ?? ''));
                $pubDate    = trim((string)($item->pubDate ?? ''));
                $desc       = trim(strip_tags((string)($item->description ?? '')));
                $sourceName = trim((string)($item->source ?? ''));
                if (!$sourceName) $sourceName = $this->sourceName($source);

                $items[] = [
                    'title'        => $title,
                    'source'       => $sourceName,
                    'link'         => $link,
                    'description'  => substr($desc, 0, 200),
                    'published_at' => $pubDate ? date('Y-m-d H:i:s', strtotime($pubDate)) : date('Y-m-d H:i:s'),
                ];
            }
        } catch (Throwable $e) {
            error_log('[NewsApi] parseRss error: ' . $e->getMessage());
        }
        return $items;
    }

    private function sourceName(string $key): string {
        if (strpos($key, 'et') === 0) return 'Economic Times';
        if (strpos($key, 'mc') === 0) return 'Moneycontrol';
        if (strpos($key, 'bs') === 0) return 'Business Standard';
        if (strpos($key, 'fe') === 0) return 'Financial Express';
        return 'Market News';
    }
}
