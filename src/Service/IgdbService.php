<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Service für die IGDB API (Twitch/Amazon Game Database).
// Authentifizierung: OAuth 2.0 Client Credentials Flow — identisch wie bei Spotify.
// IGDB nutzt eine eigene Abfragesprache (Apicalypse) statt Query-Parameter:
// Statt GET /games?search=elden+ring schickt man POST /games mit
// Body: 'search "Elden Ring"; fields name,cover; limit 10;'
class IgdbService
{
    // IGDB API und Twitch Token-URL
    private const API_URL = 'https://api.igdb.com/v4';
    private const TOKEN_URL = 'https://id.twitch.tv/oauth2/token';

    // Cover-Bilder Basis-URL — IGDB liefert nur den Pfad (z.B. //images.igdb.com/...),
    // wir brauchen https:// davor. t_cover_big = 264x374px, gute Größe für Cards.
    private const IMAGE_URL = 'https://images.igdb.com/igdb/image/upload/t_cover_big/';

    // Cache-Zeiten: Die Vorauswahl-Games sind fest im Code — deren Details
    // ändern sich praktisch nie (30 Tage). Suchergebnisse kurzlebiger (1 Stunde).
    private const CACHE_TTL = 2592000;      // 30 Tage
    private const SEARCH_CACHE_TTL = 3600;  // 1 Stunde

    // Felder die wir bei jeder Abfrage brauchen.
    // cover.image_id statt cover.url, weil wir die Bildgröße selbst steuern wollen.
    // game_modes.name = "Single player", "Multiplayer", "Co-operative" etc.
    private const GAME_FIELDS = 'name,cover.image_id,genres.name,platforms.name,aggregated_rating,rating,summary,game_modes.name';

    // Vorauswahl populärer Coop-Games, gruppiert nach Genre.
    // Nur IGDB-IDs — die Details (Cover, Name, etc.) holen wir per API und cachen sie.
    // IDs findet man über die IGDB-Website oder API.
    private const POPULAR_GAMES = [
        'Shooter' => [
            260780, // Call of Duty: Modern Warfare III
            250616, // Helldivers 2
            185258, // ARC Raiders
        ],
        'Sport' => [
            308698, // EA Sports FC 25
            308034, // NBA 2K25
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

        // Twitch Client Credentials — gleicher Flow wie bei Spotify.
        #[Autowire(env: 'IGDB_CLIENT_ID')]
        private string $clientId,

        #[Autowire(env: 'IGDB_CLIENT_SECRET')]
        private string $clientSecret,
    ) {
    }

    // --- Game-Suche für Autocomplete ---
    // Gibt max. 10 Ergebnisse zurück, vereinfacht für die Frontend-Anzeige.
    public function searchGames(string $query): array
    {
        // Suchbegriffe mit Anführungszeichen escapen — IGDB-Apicalypse-Syntax
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

    // --- Einzelnes Game laden (z.B. für Detail-Anzeige oder Prompt-Aufbau) ---
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

    // --- Mehrere Games auf einmal laden (für Prompt-Aufbau) ---
    // Nutzt getGame() pro ID — jedes einzeln gecacht.
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

    // --- Vorauswahl-Games laden (für die Quick-Pick Cards) ---
    // Gibt Games gruppiert nach Genre zurück, mit allen Details aus IGDB.
    public function getPopularGames(): array
    {
        return $this->cache->get('igdb_popular_games', function (ItemInterface $item) {
            $item->expiresAfter(self::CACHE_TTL);

            // Alle IDs aus allen Genres sammeln und in EINEM API-Call laden.
            // Vorher: 16 einzelne Requests (je ~300ms) = ~5 Sekunden.
            // Jetzt: 1 Request mit where id = (1,2,3,...) = ~300ms.
            $allIds = array_merge(...array_values(self::POPULAR_GAMES));
            $idList = implode(',', $allIds);

            $data = $this->apiRequest('/games', sprintf(
                'fields %s; where id = (%s); limit %d;',
                self::GAME_FIELDS,
                $idList,
                count($allIds)
            ));

            // API-Ergebnis als id → Game-Array indexieren für schnellen Zugriff
            $gamesById = [];
            foreach ($data as $game) {
                $gamesById[$game['id']] = $this->transformGame($game);
            }

            // In Genre-Gruppen aufteilen, Reihenfolge aus POPULAR_GAMES beibehalten
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

    // --- Rohes IGDB-Game in unser Format umwandeln ---
    // Einheitliches Array-Format für Frontend und OllamaService.
    private function transformGame(array $game): array
    {
        // Cover-URL zusammenbauen: IGDB gibt nur die image_id,
        // wir setzen die Basis-URL + gewünschte Größe davor.
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
            // aggregated_rating = Fachpresse-Score (0-100)
            'critic_rating' => isset($game['aggregated_rating']) ? round($game['aggregated_rating']) : null,
            // rating = User-Score (0-100)
            'user_rating' => isset($game['rating']) ? round($game['rating']) : null,
            'summary' => $game['summary'] ?? null,
        ];
    }

    // --- OAuth 2.0 Access Token (Client Credentials Flow) ---
    // Identisch zum Spotify-Pattern: POST mit client_id + client_secret,
    // Token cachen für knapp unter der Ablaufzeit.
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

            // Token-Ablaufzeit minus Puffer (gleich wie bei Spotify)
            $item->expiresAfter($data['expires_in'] - 100);

            return $data['access_token'];
        });
    }

    // --- API-Request an IGDB ---
    // IGDB nutzt POST für alles (!) — die Abfrage steht im Body (Apicalypse-Syntax).
    // Headers: Client-ID + Bearer Token (beides nötig, anders als bei Spotify).
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

        // 429 = Rate Limit → warten und nochmal
        if ($statusCode === 429 && $attempt < $maxRetries) {
            sleep(2 ** $attempt); // Exponentielles Backoff: 2s, 4s, 8s
            return $this->apiRequest($endpoint, $body, $attempt + 1);
        }

        // 401 = Token abgelaufen → Token-Cache löschen, neuen holen
        if ($statusCode === 401 && $attempt < $maxRetries) {
            $this->cache->delete('igdb_access_token');
            return $this->apiRequest($endpoint, $body, $attempt + 1);
        }

        throw new \RuntimeException(sprintf(
            'IGDB API Fehler: HTTP %d für %s',
            $statusCode,
            self::API_URL . $endpoint
        ));
    }
}
