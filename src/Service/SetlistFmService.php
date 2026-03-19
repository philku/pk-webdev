<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

// Service layer for the setlist.fm API. Handles caching, pagination, and delta updates.
class SetlistFmService
{
    private const METALLICA_MBID = '65f4f0c5-ef9e-490c-aee3-909e7ae6b2ab';
    private const BASE_URL = 'https://api.setlist.fm/rest/1.0';
    private const CACHE_TTL = 3600;          // 1 hour (per-page cache)
    private const FULL_CACHE_TTL = 2592000;  // 30 days (full concert cache)

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(env: 'SETLISTFM_API_KEY')]
        private string $apiKey,
    ) {
    }

    public function getSetlists(int $page = 1): array
    {
        $cacheKey = 'setlistfm_metallica_p' . $page;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($page) {
            $item->expiresAfter(self::CACHE_TTL);

            $response = $this->httpClient->request('GET', self::BASE_URL . '/artist/' . self::METALLICA_MBID . '/setlists', [
                'query' => ['p' => $page],
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'Accept' => 'application/json',
                ],
            ]);

            $data = $response->toArray();

            return [
                'total' => $data['total'] ?? 0,
                'page' => $data['page'] ?? 1,
                'itemsPerPage' => $data['itemsPerPage'] ?? 20,
                'setlists' => $data['setlist'] ?? [],
            ];
        });
    }

    public function getSetlist(string $setlistId): ?array
    {
        $cacheKey = 'setlistfm_setlist_' . $setlistId;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($setlistId) {
            $item->expiresAfter(self::CACHE_TTL);

            $response = $this->httpClient->request('GET', self::BASE_URL . '/setlist/' . $setlistId, [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'Accept' => 'application/json',
                ],
            ]);

            return $response->toArray();
        });
    }

    /**
     * Returns the full concert cache if available, null otherwise.
     * Uses the &$save trick to detect cache miss without storing anything.
     *
     * @return array{concerts: array, total: int}|null
     */
    public function getFullMapCacheIfAvailable(): ?array
    {
        $found = true;

        $result = $this->cache->get('setlistfm_metallica_all_map', function (ItemInterface $item, bool &$save) use (&$found) {
            $found = false;
            $save = false;
            return null;
        });

        return $found ? $result : null;
    }

    /**
     * Assembles the full cache from individual page caches (no API calls).
     * Called when the last page has been loaded during progressive loading.
     */
    public function buildFullMapCache(int $totalPages, int $total): void
    {
        $allConcerts = [];
        for ($page = 1; $page <= $totalPages; $page++) {
            $pageData = $this->getMapConcertsPage($page);
            array_push($allConcerts, ...$pageData['concerts']);
        }

        $this->refreshFullCache($allConcerts, $total);

        // Aggregate song play counts from per-page caches.
        $allCounts = [];
        for ($page = 1; $page <= $totalPages; $page++) {
            $pageCounts = $this->getSongCountsForPage($page);
            foreach ($pageCounts as $song => $count) {
                $allCounts[$song] = ($allCounts[$song] ?? 0) + $count;
            }
        }

        arsort($allCounts);
        $this->saveSongPlayCounts($allCounts);
    }

    /**
     * Delta check: compares cached total with current API total (1 API call).
     * - Same total → refresh TTL, return cached data
     * - Higher total → fetch new concerts, merge, update cache
     * - No cache → return null (progressive loading needed)
     *
     * @return array|null All concerts or null on cold start
     */
    public function checkForNewConcerts(): ?array
    {
        $cached = $this->getFullMapCacheIfAvailable();
        if ($cached === null) {
            return null;
        }

        $cachedTotal = $cached['total'];
        $cachedConcerts = $cached['concerts'];

        // Uncached API call for fresh total. On API failure, return cached data.
        try {
            $page1 = $this->fetchPage(1);
        } catch (\Throwable) {
            return $cachedConcerts;
        }
        $currentTotal = $page1['total'] ?? 0;

        if ($currentTotal === $cachedTotal) {
            $this->refreshFullCache($cachedConcerts, $cachedTotal);
            $this->refreshSongPlayCountsTtl();
            return $cachedConcerts;
        }

        if ($currentTotal > $cachedTotal) {
            $delta = $currentTotal - $cachedTotal;
            $deltaSetlists = $this->fetchDeltaSetlists($page1, $delta);
            $newConcerts = $this->transformSetlistsForMap($deltaSetlists);

            $allConcerts = array_merge($newConcerts, $cachedConcerts);
            $this->refreshFullCache($allConcerts, $currentTotal);

            $newSongCounts = $this->extractSongCounts($deltaSetlists);
            $existingCounts = $this->getSongPlayCounts();

            foreach ($newSongCounts as $song => $count) {
                $existingCounts[$song] = ($existingCounts[$song] ?? 0) + $count;
            }
            arsort($existingCounts);
            $this->saveSongPlayCounts($existingCounts);

            return $allConcerts;
        }

        // Total decreased (shouldn't happen) — invalidate cache.
        $this->cache->delete('setlistfm_metallica_all_map');
        return null;
    }

    /**
     * Fetches raw setlist data for new (delta) concerts.
     * Page 1 is already available from the total check.
     */
    private function fetchDeltaSetlists(array $page1Data, int $delta): array
    {
        $allSetlists = $page1Data['setlist'] ?? [];
        $itemsPerPage = $page1Data['itemsPerPage'] ?? 20;

        if ($delta <= count($allSetlists)) {
            return array_slice($allSetlists, 0, $delta);
        }

        $pagesNeeded = (int) ceil($delta / $itemsPerPage);
        for ($page = 2; $page <= $pagesNeeded; $page++) {
            $pageData = $this->fetchPage($page);
            array_push($allSetlists, ...($pageData['setlist'] ?? []));
        }

        return array_slice($allSetlists, 0, $delta);
    }

    /**
     * Extracts song names and counts from raw setlist data (no caching).
     *
     * @return array<string, int>
     */
    private function extractSongCounts(array $setlists): array
    {
        $counts = [];
        foreach ($setlists as $setlist) {
            foreach ($setlist['sets']['set'] ?? [] as $set) {
                foreach ($set['song'] ?? [] as $song) {
                    $name = $song['name'] ?? '';
                    if ($name === '') {
                        continue;
                    }
                    $normalized = $this->normalizeSongName($name);
                    $counts[$normalized] = ($counts[$normalized] ?? 0) + 1;
                }
            }
        }
        return $counts;
    }

    // Cache helpers: delete + re-create (Symfony cache has no "touch TTL" method).

    private function refreshFullCache(array $concerts, int $total): void
    {
        $this->cache->delete('setlistfm_metallica_all_map');
        $this->cache->get('setlistfm_metallica_all_map', function (ItemInterface $item) use ($concerts, $total) {
            $item->expiresAfter(self::FULL_CACHE_TTL);
            return ['concerts' => $concerts, 'total' => $total];
        });
    }

    private function refreshSongPlayCountsTtl(): void
    {
        $counts = $this->getSongPlayCounts();
        if (empty($counts)) {
            return;
        }
        $this->saveSongPlayCounts($counts);
    }

    private function saveSongPlayCounts(array $counts): void
    {
        $this->cache->delete('setlistfm_song_play_counts');
        $this->cache->get('setlistfm_song_play_counts', function (ItemInterface $item) use ($counts) {
            $item->expiresAfter(self::FULL_CACHE_TTL);
            return $counts;
        });
    }

    private function getSongCountsForPage(int $page): array
    {
        $found = true;

        $result = $this->cache->get('setlistfm_songs_page_' . $page, function (ItemInterface $item, bool &$save) use (&$found) {
            $found = false;
            $save = false;
            return [];
        });

        return $found ? $result : [];
    }

    /**
     * Fetches one page of concert data in map format (coordinates, venue, etc.).
     * Each page is cached individually for progressive loading.
     *
     * @return array{concerts: array, page: int, totalPages: int, total: int}
     */
    public function getMapConcertsPage(int $page): array
    {
        $cacheKey = 'setlistfm_map_page_' . $page;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($page) {
            $item->expiresAfter(21600); // 6 hours

            $result = $this->fetchPage($page);

            $total = $result['total'] ?? 0;
            $itemsPerPage = $result['itemsPerPage'] ?? 20;
            $totalPages = (int) ceil($total / $itemsPerPage);

            $concerts = $this->transformSetlistsForMap($result['setlist'] ?? []);

            // Cache song counts as a side effect while raw data is available
            // (transformSetlistsForMap discards song info).
            $this->cacheSongCountsForPage($page, $result['setlist'] ?? []);

            return [
                'concerts' => $concerts,
                'page' => $page,
                'totalPages' => $totalPages,
                'total' => $total,
            ];
        });
    }

    private function cacheSongCountsForPage(int $page, array $setlists): void
    {
        $cacheKey = 'setlistfm_songs_page_' . $page;

        $this->cache->get($cacheKey, function (ItemInterface $item) use ($setlists) {
            $item->expiresAfter(21600);

            $counts = [];
            foreach ($setlists as $setlist) {
                foreach ($setlist['sets']['set'] ?? [] as $set) {
                    foreach ($set['song'] ?? [] as $song) {
                        $name = $song['name'] ?? '';
                        if ($name === '') {
                            continue;
                        }
                        $normalized = $this->normalizeSongName($name);
                        $counts[$normalized] = ($counts[$normalized] ?? 0) + 1;
                    }
                }
            }

            return $counts;
        });
    }

    // Filters concerts without coordinates (can't be placed on map).
    private function transformSetlistsForMap(array $setlists): array
    {
        $concerts = [];

        foreach ($setlists as $setlist) {
            $venue = $setlist['venue'] ?? [];
            $city = $venue['city'] ?? [];
            $coords = $city['coords'] ?? [];

            if (!empty($coords['lat']) && !empty($coords['long'])) {
                $concerts[] = [
                    'id' => $setlist['id'],
                    'lat' => (float) $coords['lat'],
                    'lng' => (float) $coords['long'],
                    'date' => $setlist['eventDate'] ?? '',
                    'venue' => $venue['name'] ?? 'Unbekannt',
                    'city' => $city['name'] ?? '',
                    'country' => $city['country']['name'] ?? '',
                    'tour' => $setlist['tour']['name'] ?? '',
                    'songCount' => $this->countSongs($setlist),
                ];
            }
        }

        return $concerts;
    }

    // Direct API call without cache. Retries on 429 with exponential backoff.
    private function fetchPage(int $page): array
    {
        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $response = $this->httpClient->request('GET', self::BASE_URL . '/artist/' . self::METALLICA_MBID . '/setlists', [
                'query' => ['p' => $page],
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'Accept' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                return $response->toArray();
            }

            if ($statusCode === 429 && $attempt < $maxRetries) {
                sleep(pow(2, $attempt));
                continue;
            }

            return $response->toArray();
        }

        return [];
    }

    /**
     * Aggregated song play counts built as a side effect of full cache assembly.
     *
     * @return array<string, int>
     */
    public function getSongPlayCounts(): array
    {
        $found = true;

        $result = $this->cache->get('setlistfm_song_play_counts', function (ItemInterface $item, bool &$save) use (&$found) {
            $found = false;
            $save = false;
            return [];
        });

        return $found ? $result : [];
    }

    // Normalizes song names for comparison (strips parenthetical suffixes, lowercases).
    private function normalizeSongName(string $name): string
    {
        $name = preg_replace('/\s*\(.*?\)\s*/', '', $name);
        return mb_strtolower(trim($name));
    }

    private function countSongs(array $setlist): int
    {
        $count = 0;
        foreach ($setlist['sets']['set'] ?? [] as $set) {
            $count += count($set['song'] ?? []);
        }

        return $count;
    }
}
