<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Service für die Spotify Web API (Client Credentials Flow).
// Holt Album- und Track-Daten für Metallica.
// Authentifizierung: OAuth 2.0 server-to-server — kein User-Login nötig,
// weil wir nur öffentliche Artist-Daten lesen.
class SpotifyService
{
    // Metallica's Spotify-ID — eindeutig und fest, ändert sich nie.
    private const METALLICA_ID = '2ye2Wgw4gimLv2eAKyk1NB';

    // Spotify API Basis-URLs
    private const API_URL = 'https://api.spotify.com/v1';
    private const TOKEN_URL = 'https://accounts.spotify.com/api/token';

    // 24 Stunden Cache — Discography-Daten ändern sich quasi nie.
    // Deutlich länger als die setlist.fm-Daten (1h), weil Alben
    // nicht wie Konzerte ständig neue hinzukommen.
    private const CACHE_TTL = 86400;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,

        // Client-ID und Secret kommen aus .env(.local).
        // Werden beim Token-Request Base64-encoded zusammengefügt.
        #[Autowire(env: 'SPOTIFY_CLIENT_ID')]
        private string $clientId,

        #[Autowire(env: 'SPOTIFY_CLIENT_SECRET')]
        private string $clientSecret,
    ) {
    }

    // --- Artist-Info (Followers, Popularity, Genres, Bilder) ---
    public function getArtist(): array
    {
        return $this->apiRequest('/artists/' . self::METALLICA_ID, 'spotify_artist');
    }

    // --- Alle Studio-Alben, sortiert nach Release-Datum ---
    // include_groups=album filtert Singles, Compilations und Appears-On raus.
    // Spotify paginiert maximal 50 Items pro Request.
    public function getAlbums(): array
    {
        return $this->cache->get('spotify_albums', function (ItemInterface $item) {
            $item->expiresAfter(self::CACHE_TTL);

            $albums = [];
            $offset = 0;
            $limit = 50;

            // Spotify paginiert Alben — wir holen alle Seiten.
            // Bei Metallica sind es ~15 Studio-Alben, also 1 Request.
            do {
                $data = $this->apiRequest(
                    '/artists/' . self::METALLICA_ID . '/albums?include_groups=album&limit=' . $limit . '&offset=' . $offset,
                    null // Kein Einzel-Caching, wir cachen das Gesamtergebnis
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

                $offset += $limit;
                $total = $data['total'];
            } while ($offset < $total);

            // Nach Release-Datum sortieren (älteste zuerst = chronologisch)
            usort($albums, fn($a, $b) => $a['release_date'] <=> $b['release_date']);

            // Duplikate rausfiltern: Spotify listet Remastered-Versionen als eigene Alben.
            // Wir behalten pro Album-Name nur die früheste Version.
            $seen = [];
            $unique = [];
            foreach ($albums as $album) {
                // Normalisieren: "(Remastered)", "(Deluxe Edition)" etc. entfernen
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

    // --- Ein einzelnes Album mit allen Tracks ---
    public function getAlbum(string $id): array
    {
        return $this->cache->get('spotify_album_' . $id, function (ItemInterface $item) use ($id) {
            $item->expiresAfter(self::CACHE_TTL);

            $data = $this->apiRequest('/albums/' . $id, null);

            // Tracks aufbereiten
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

    // --- Top-Tracks nach Popularity ---
    // Spotify liefert maximal 10 Tracks pro Artist.
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

    // --- Access Token holen (Client Credentials Flow) ---
    // OAuth 2.0: POST an Spotify Token-URL mit client_id:client_secret
    // als Base64-encoded Authorization Header.
    // Token ist 1h gültig, wir cachen ihn für 3500s (knapp darunter).
    private function getAccessToken(): string
    {
        return $this->cache->get('spotify_access_token', function (ItemInterface $item) {
            // 3500s statt 3600s — Sicherheitspuffer, damit der Token
            // nicht genau dann abläuft, wenn wir ihn gerade benutzen.
            $item->expiresAfter(3500);

            // Base64-Encoding von "client_id:client_secret"
            // Das ist der Standard für HTTP Basic Auth im OAuth-Flow.
            $credentials = base64_encode($this->clientId . ':' . $this->clientSecret);

            $response = $this->httpClient->request('POST', self::TOKEN_URL, [
                'headers' => [
                    'Authorization' => 'Basic ' . $credentials,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => 'grant_type=client_credentials',
            ]);

            $data = $response->toArray();

            return $data['access_token'];
        });
    }

    // --- API-Request mit Bearer Token und Retry-Logik ---
    // Zwei Retry-Szenarien:
    //   429 = Rate Limit → warten und nochmal versuchen
    //   401 = Token abgelaufen → Token-Cache löschen, neuen holen, nochmal versuchen
    private function apiRequest(string $endpoint, ?string $cacheKey): array
    {
        // Wenn ein Cache-Key angegeben wurde, cachen wir das Ergebnis.
        // Bei null wird direkt der API-Call gemacht (für verschachtelte Calls).
        if ($cacheKey !== null) {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($endpoint) {
                $item->expiresAfter(self::CACHE_TTL);
                return $this->doRequest($endpoint);
            });
        }

        return $this->doRequest($endpoint);
    }

    // --- Der eigentliche HTTP-Request mit Retry-Logik ---
    private function doRequest(string $endpoint, int $attempt = 1): array
    {
        $maxRetries = 3;
        $token = $this->getAccessToken();

        // Volle URL oder nur Endpoint? (für Pagination-URLs die schon voll sind)
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

        // 429 = Rate Limit → warten und erneut versuchen
        if ($statusCode === 429 && $attempt < $maxRetries) {
            // Retry-After Header gibt an, wie lange wir warten sollen
            $retryAfter = (int) ($response->getHeaders(false)['retry-after'][0] ?? 1);
            sleep($retryAfter);
            return $this->doRequest($endpoint, $attempt + 1);
        }

        // 401 = Token abgelaufen → Cache löschen, neuen Token holen, nochmal versuchen
        if ($statusCode === 401 && $attempt < $maxRetries) {
            $this->cache->delete('spotify_access_token');
            return $this->doRequest($endpoint, $attempt + 1);
        }

        // Andere Fehler oder letzte Retry-Chance → Exception werfen lassen
        return $response->toArray();
    }
}
