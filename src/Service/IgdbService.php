<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// IGDB API service (Twitch/Amazon Game Database).
// Auth: OAuth 2.0 Client Credentials. Query language: Apicalypse (POST body, not query params).
class IgdbService
{
    private const API_URL = 'https://api.igdb.com/v4';
    private const TOKEN_URL = 'https://id.twitch.tv/oauth2/token';
    private const IMAGE_URL = 'https://images.igdb.com/igdb/image/upload/t_cover_big/';

    private const CACHE_TTL = 2592000;        // 30 days
    private const SEARCH_CACHE_TTL = 3600;    // 1 hour

    private const GAME_FIELDS = 'name,cover.image_id,genres.name,platforms.name,aggregated_rating,rating,summary,game_modes.name';

    // Curated popular coop games by genre (IGDB IDs).
    private const POPULAR_GAMES = [
        'Shooter' => [
            260780, // Call of Duty: Modern Warfare III
            250616, // Helldivers 2
            185258, // ARC Raiders
        ],
        'Sport' => [
            353848, // EA Sports FC 26
            353901, // NBA 2K26
            11198,  // Rocket League
        ],
        'RPG' => [
            325591, // Elden Ring Nightreign
            125165, // Diablo IV
            119171, // Baldur's Gate III
        ],
        'Horror' => [
            132516, // Phasmophobia
            18866,  // Dead by Daylight
            212089, // Lethal Company
        ],
        'Adventure' => [
            135243, // It Takes Two
            36897,  // A Way Out
            303811, // Astro Bot
        ],
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        #[Autowire(env: 'IGDB_CLIENT_ID')]
        private string $clientId,
        #[Autowire(env: 'IGDB_CLIENT_SECRET')]
        private string $clientSecret,
    ) {
    }

    public function searchGames(string $query): array
    {
        $safeQuery = addslashes($query);

        return $this->cache->get('igdb_search_' . md5($query), function (ItemInterface $item) use ($safeQuery) {
            $item->expiresAfter(self::SEARCH_CACHE_TTL);

            $data = $this->apiRequest('/games', sprintf(
                'search "%s"; fields %s; limit 10;',
                $safeQuery,
                self::GAME_FIELDS
            ));

            return array_map([$this, 'transformGame'], $data);
        });
    }

    public function getGame(int $id): ?array
    {
        return $this->cache->get('igdb_game_' . $id, function (ItemInterface $item) use ($id) {
            $item->expiresAfter(self::CACHE_TTL);

            $data = $this->apiRequest('/games', sprintf(
                'fields %s; where id = %d;',
                self::GAME_FIELDS,
                $id
            ));

            if (empty($data)) {
                return null;
            }

            return $this->transformGame($data[0]);
        });
    }

    public function getGamesById(array $ids): array
    {
        $games = [];

        foreach ($ids as $id) {
            $game = $this->getGame((int) $id);
            if ($game !== null) {
                $games[] = $game;
            }
        }

        return $games;
    }

    // Loads popular games grouped by genre. Single API call with all IDs.
    public function getPopularGames(): array
    {
        return $this->cache->get('igdb_popular_games', function (ItemInterface $item) {
            $item->expiresAfter(self::CACHE_TTL);

            $allIds = array_merge(...array_values(self::POPULAR_GAMES));
            $idList = implode(',', $allIds);

            $data = $this->apiRequest('/games', sprintf(
                'fields %s; where id = (%s); limit %d;',
                self::GAME_FIELDS,
                $idList,
                count($allIds)
            ));

            $gamesById = [];
            foreach ($data as $game) {
                $gamesById[$game['id']] = $this->transformGame($game);
            }

            $result = [];
            foreach (self::POPULAR_GAMES as $genre => $ids) {
                $games = [];
                foreach ($ids as $id) {
                    if (isset($gamesById[$id])) {
                        $games[] = $gamesById[$id];
                    }
                }
                $result[$genre] = $games;
            }

            return $result;
        });
    }

    // Normalizes raw IGDB data into a consistent format for frontend and LLM prompt.
    private function transformGame(array $game): array
    {
        $coverUrl = null;
        if (isset($game['cover']['image_id'])) {
            $coverUrl = self::IMAGE_URL . $game['cover']['image_id'] . '.jpg';
        }

        return [
            'id' => $game['id'],
            'name' => $game['name'],
            'cover' => $coverUrl,
            'genres' => array_column($game['genres'] ?? [], 'name'),
            'platforms' => array_column($game['platforms'] ?? [], 'name'),
            'game_modes' => array_column($game['game_modes'] ?? [], 'name'),
            'critic_rating' => isset($game['aggregated_rating']) ? round($game['aggregated_rating']) : null,
            'user_rating' => isset($game['rating']) ? round($game['rating']) : null,
            'summary' => $game['summary'] ?? null,
        ];
    }

    // OAuth 2.0 Client Credentials — token cached just under expiry time.
    private function getAccessToken(): string
    {
        return $this->cache->get('igdb_access_token', function (ItemInterface $item) {
            $response = $this->httpClient->request('POST', self::TOKEN_URL, [
                'body' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'client_credentials',
                ],
            ]);

            $data = $response->toArray();
            $item->expiresAfter($data['expires_in'] - 100);

            return $data['access_token'];
        });
    }

    // IGDB uses POST for all queries — the Apicalypse query goes in the body.
    // Retries on 429 (rate limit) and 401 (expired token).
    private function apiRequest(string $endpoint, string $body, int $attempt = 1): array
    {
        $maxRetries = 3;
        $token = $this->getAccessToken();

        $response = $this->httpClient->request('POST', self::API_URL . $endpoint, [
            'headers' => [
                'Client-ID' => $this->clientId,
                'Authorization' => 'Bearer ' . $token,
            ],
            'body' => $body,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 200) {
            return $response->toArray();
        }

        if ($statusCode === 429 && $attempt < $maxRetries) {
            sleep(2 ** $attempt);
            return $this->apiRequest($endpoint, $body, $attempt + 1);
        }

        if ($statusCode === 401 && $attempt < $maxRetries) {
            $this->cache->delete('igdb_access_token');
            return $this->apiRequest($endpoint, $body, $attempt + 1);
        }

        throw new \RuntimeException(sprintf('IGDB API error: HTTP %d for %s', $statusCode, self::API_URL . $endpoint));
    }
}
