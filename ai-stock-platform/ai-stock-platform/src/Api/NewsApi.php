<?php

class NewsApi {

    public function getNews(string $companyName, string $symbol): array {
        $queries = [
            $companyName . ' NSE stock',
            $symbol . ' NSE India',
        ];

        $requests = [];
        foreach ($queries as $i => $q) {
            $url = GNEWS_BASE . '?q=' . urlencode($q) . '&hl=en-IN&gl=IN&ceid=IN:en';
            $requests["q$i"] = ['url' => $url, 'headers' => ['Accept: application/rss+xml']];
        }

        $raws    = ParallelFetcher::fetchRaw($requests);
        $items   = [];
        $seen    = [];

        foreach ($raws as $raw) {
            if (!$raw) continue;
            $parsed = $this->parseRss($raw);
            foreach ($parsed as $item) {
                $key = md5($item['title']);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $items[]    = $item;
                }
            }
        }

        // Sort by date desc
        usort($items, fn($a, $b) => strtotime($b['published_at']) - strtotime($a['published_at']));
        return array_slice($items, 0, NEWS_MAX);
    }

    private function parseRss(string $xml): array {
        $items = [];
        try {
            libxml_use_internal_errors(true);
            $feed = simplexml_load_string($xml);
            if (!$feed) return [];
            foreach ($feed->channel->item ?? [] as $item) {
                $items[] = [
                    'title'        => (string)$item->title,
                    'source'       => (string)($item->source ?? ''),
                    'link'         => (string)$item->link,
                    'published_at' => date('Y-m-d H:i:s', strtotime((string)$item->pubDate)),
                ];
            }
        } catch (Throwable) {
            // silently skip
        }
        return $items;
    }
}
