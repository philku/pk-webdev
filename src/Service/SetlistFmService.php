<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

// Dieser Service kapselt alle Zugriffe auf die setlist.fm API.
// Statt überall direkt HTTP-Requests zu machen, ruft der Rest der App
// nur Methoden wie getSetlists() oder getSetlist() auf.
// Das nennt man "Service Layer" — eine saubere Trennschicht.
class SetlistFmService
{
    // Metallica's MusicBrainz-ID — damit wir immer den richtigen Artist treffen
    // und nicht Tribute-Bands wie "Black Metallica" bekommen.
    private const METALLICA_MBID = '65f4f0c5-ef9e-490c-aee3-909e7ae6b2ab';
    private const BASE_URL = 'https://api.setlist.fm/rest/1.0';

    // Cache-Dauer: 1 Stunde (3600 Sekunden).
    // Die setlist.fm-Daten ändern sich selten, also cachen wir die Antworten
    // um unnötige API-Calls zu sparen (Rate Limit schonen).
    private const CACHE_TTL = 3600;

    public function __construct(
        // HttpClientInterface = Symfony's eingebauter HTTP-Client.
        // Damit machen wir GET-Requests an externe APIs.
        // Symfony injected das automatisch (Dependency Injection).
        private HttpClientInterface $httpClient,

        // CacheInterface = Symfony's Cache-System.
        // Speichert API-Antworten temporär, damit wir nicht bei jedem
        // Seitenaufruf die API abfragen müssen.
        private CacheInterface $cache,

        // Der API-Key wird aus der .env(.local) geladen.
        // #[Autowire] mit env()-Syntax sagt Symfony:
        // "Hol den Wert der Umgebungsvariable SETLISTFM_API_KEY"
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(env: 'SETLISTFM_API_KEY')]
        private string $apiKey,
    ) {
    }

    /**
     * Holt eine Seite Metallica-Setlists von der API.
     * Gibt ein Array mit 'total', 'page', 'itemsPerPage' und 'setlists' zurück.
     */
    public function getSetlists(int $page = 1): array
    {
        // Cache-Key eindeutig pro Seite, damit Seite 1 und Seite 2
        // nicht dieselben gecachten Daten zurückgeben.
        $cacheKey = 'setlistfm_metallica_p' . $page;

        // $this->cache->get() prüft:
        // 1. Ist der Key im Cache? → Ja: gibt gecachte Daten zurück (kein API-Call)
        // 2. Nein: ruft die Callback-Funktion auf, speichert das Ergebnis im Cache
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($page) {
            // Wie lange der Cache-Eintrag gültig ist
            $item->expiresAfter(self::CACHE_TTL);

            // HTTP GET-Request an die setlist.fm API
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

    /**
     * Holt ein einzelnes Setlist-Detail anhand der Setlist-ID.
     */
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
     * Holt ALLE Setlists (alle Seiten) und gibt nur die Daten zurück,
     * die wir für die Karte brauchen: Koordinaten, Datum, Venue, Tour.
     * Das wird einmalig gecacht und dann für die Kartenansicht benutzt.
     *
     * @return array<int, array{lat: float, lng: float, date: string, venue: string, city: string, country: string, tour: string, id: string}>
     */
    public function getAllConcertsForMap(): array
    {
        return $this->cache->get('setlistfm_metallica_all_map', function (ItemInterface $item) {
            // Längerer Cache für die Gesamtliste (6 Stunden),
            // weil wir hier viele API-Calls machen müssen.
            $item->expiresAfter(21600);

            $concerts = [];
            $page = 1;
            $totalPages = 1;

            // Seite für Seite durchgehen bis alle Konzerte geladen sind.
            // Die API liefert 20 Setlists pro Seite.
            do {
                $result = $this->fetchPage($page);
                $totalPages = (int) ceil(($result['total'] ?? 0) / ($result['itemsPerPage'] ?? 20));

                foreach ($result['setlist'] ?? [] as $setlist) {
                    $venue = $setlist['venue'] ?? [];
                    $city = $venue['city'] ?? [];
                    $coords = $city['coords'] ?? [];

                    // Nur Konzerte mit Koordinaten auf die Karte setzen
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

                $page++;

                // Pause zwischen den Requests um das Rate Limit nicht zu sprengen.
                // setlist.fm erlaubt ca. 2 Requests/Sekunde.
                // 1 Sekunde ist sicherer als 0.5s — bei 100+ Seiten
                // summieren sich kleine Überschreitungen schnell.
                if ($page <= $totalPages) {
                    sleep(1);
                }
            } while ($page <= $totalPages);

            return $concerts;
        });
    }

    /**
     * Direkter API-Call ohne Cache (wird intern von getAllConcertsForMap benutzt).
     * Enthält Retry-Logik für 429 (Rate Limit) Responses.
     *
     * Warum Retry? setlist.fm erlaubt nur ~2 Requests/Sekunde.
     * Wenn wir 100+ Seiten laden, kann es passieren, dass wir
     * zwischendurch geblockt werden. Statt abzubrechen, warten wir
     * einfach kurz und versuchen es nochmal (bis zu 3 Versuche).
     */
    private function fetchPage(int $page): array
    {
        // Maximal 3 Versuche pro Seite
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

            // 200 = alles gut, Daten zurückgeben
            if ($statusCode === 200) {
                return $response->toArray();
            }

            // 429 = Rate Limit erreicht → warten und nochmal versuchen
            // Exponentielles Backoff: 2s, 4s, 8s — jedes Mal länger warten,
            // damit die API sich erholen kann.
            if ($statusCode === 429 && $attempt < $maxRetries) {
                $waitSeconds = pow(2, $attempt); // 2, 4, 8 Sekunden
                sleep($waitSeconds);
                continue;
            }

            // Anderer Fehler oder letzter Retry fehlgeschlagen → Exception
            return $response->toArray(); // wirft HttpException bei Fehler
        }

        return []; // Fallback, wird nie erreicht
    }

    /**
     * Zählt die Songs in einer Setlist (über alle Sets hinweg).
     */
    private function countSongs(array $setlist): int
    {
        $count = 0;
        foreach ($setlist['sets']['set'] ?? [] as $set) {
            $count += count($set['song'] ?? []);
        }

        return $count;
    }
}
