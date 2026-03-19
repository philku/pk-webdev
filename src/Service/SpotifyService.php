<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Spotify Web API service (Client Credentials Flow — no user login needed).
class SpotifyService
{
    private const METALLICA_ID = '2ye2Wgw4gimLv2eAKyk1NB';
    private const API_URL = 'https://api.spotify.com/v1';
    private const TOKEN_URL = 'https://accounts.spotify.com/api/token';
    private const CACHE_TTL = 2592000; // 30 days

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        #[Autowire(env: 'SPOTIFY_CLIENT_ID')]
        private string $clientId,
        #[Autowire(env: 'SPOTIFY_CLIENT_SECRET')]
        private string $clientSecret,
    ) {
    }

    public function getArtist(): array
    {
        return $this->apiRequest('/artists/' . self::METALLICA_ID, 'spotify_artist');
    }

    // Studio albums sorted chronologically, with remastered duplicates filtered out.
    public function getAlbums(): array
    {
        return $this->cache->get('spotify_albums', function (ItemInterface $item) {
            $item->expiresAfter(self::CACHE_TTL);

            $albums = [];
            $offset = 0;

            do {
                $data = $this->apiRequest(
                    '/artists/' . self::METALLICA_ID . '/albums?include_groups=album&offset=' . $offset,
                    null
                );

                foreach ($data['items'] as $album) {
                    $albums[] = [
                        'id' => $album['id'],
                        'name' => $album['name'],
                        'release_date' => $album['release_date'],
                        'total_tracks' => $album['total_tracks'],
                        'image' => $album['images'][1]['url'] ?? $album['images'][0]['url'] ?? null,
                        'image_large' => $album['images'][0]['url'] ?? null,
                        'spotify_url' => $album['external_urls']['spotify'] ?? null,
                    ];
                }

                $limit = $data['limit'];
                $offset += $limit;
                $total = $data['total'];
            } while ($data['next'] !== null);

            usort($albums, fn($a, $b) => $a['release_date'] <=> $b['release_date']);

            // Deduplicate remastered/deluxe editions — keep earliest release per album name.
            $seen = [];
            $unique = [];
            foreach ($albums as $album) {
                $normalized = preg_replace('/\s*\(.*?(remaster|deluxe|expanded|bonus).*?\)\s*/i', '', $album['name']);
                $normalized = trim($normalized);

                if (!isset($seen[$normalized])) {
                    $seen[$normalized] = true;
                    $unique[] = $album;
                }
            }

            return $unique;
        });
    }

    public function getAlbum(string $id): array
    {
        return $this->cache->get('spotify_album_' . $id, function (ItemInterface $item) use ($id) {
            $item->expiresAfter(self::CACHE_TTL);

            $data = $this->apiRequest('/albums/' . $id, null);

            $tracks = [];
            foreach ($data['tracks']['items'] as $track) {
                $tracks[] = [
                    'number' => $track['track_number'],
                    'name' => $track['name'],
                    'duration_ms' => $track['duration_ms'],
                    'spotify_url' => $track['external_urls']['spotify'] ?? null,
                ];
            }

            return [
                'id' => $data['id'],
                'name' => $data['name'],
                'release_date' => $data['release_date'],
                'total_tracks' => $data['total_tracks'],
                'label' => $data['label'] ?? '',
                'image' => $data['images'][0]['url'] ?? null,
                'spotify_url' => $data['external_urls']['spotify'] ?? null,
                'tracks' => $tracks,
                'copyrights' => $data['copyrights'] ?? [],
            ];
        });
    }

    public function getTopTracks(): array
    {
        return $this->cache->get('spotify_top_tracks', function (ItemInterface $item) {
            $item->expiresAfter(self::CACHE_TTL);

            $data = $this->apiRequest('/artists/' . self::METALLICA_ID . '/top-tracks', null);

            $tracks = [];
            foreach ($data['tracks'] as $track) {
                $tracks[] = [
                    'name' => $track['name'],
                    'popularity' => $track['popularity'],
                    'album' => $track['album']['name'] ?? '',
                    'duration_ms' => $track['duration_ms'],
                    'spotify_url' => $track['external_urls']['spotify'] ?? null,
                ];
            }

            return $tracks;
        });
    }

    // OAuth 2.0 Client Credentials — token cached with safety margin before expiry.
    private function getAccessToken(): string
    {
        return $this->cache->get('spotify_access_token', function (ItemInterface $item) {
            $item->expiresAfter(3500); // 3500s of 3600s expiry

            $credentials = base64_encode($this->clientId . ':' . $this->clientSecret);

            $response = $this->httpClient->request('POST', self::TOKEN_URL, [
                'headers' => [
                    'Authorization' => 'Basic ' . $credentials,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => 'grant_type=client_credentials',
            ]);

            return $response->toArray()['access_token'];
        });
    }

    private function apiRequest(string $endpoint, ?string $cacheKey): array
    {
        if ($cacheKey !== null) {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($endpoint) {
                $item->expiresAfter(self::CACHE_TTL);
                return $this->doRequest($endpoint);
            });
        }

        return $this->doRequest($endpoint);
    }

    // HTTP request with retry logic for 429 (rate limit) and 401 (expired token).
    private function doRequest(string $endpoint, int $attempt = 1): array
    {
        $maxRetries = 3;
        $token = $this->getAccessToken();

        $url = str_starts_with($endpoint, 'https://') ? $endpoint : self::API_URL . $endpoint;

        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode === 200) {
            return $response->toArray();
        }

        if ($statusCode === 429 && $attempt < $maxRetries) {
            $retryAfter = (int) ($response->getHeaders(false)['retry-after'][0] ?? 1);
            sleep($retryAfter);
            return $this->doRequest($endpoint, $attempt + 1);
        }

        if ($statusCode === 401 && $attempt < $maxRetries) {
            $this->cache->delete('spotify_access_token');
            return $this->doRequest($endpoint, $attempt + 1);
        }

        throw new \RuntimeException(sprintf('Spotify API error: HTTP %d for %s', $statusCode, $url));
    }
}
