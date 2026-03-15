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

    // Cache-Dauer für Einzelabfragen: 1 Stunde (3600 Sekunden).
    // Wird für einzelne Setlist-Detail-Seiten und Paginierung benutzt.
    private const CACHE_TTL = 3600;

    // Cache-Dauer für den Full-Cache: 30 Tage (2.592.000 Sekunden).
    // Der Full-Cache enthält ALLE ~2177 Konzerte zusammengebaut.
    // 30 Tage statt 6 Stunden, weil wir bei jedem Besuch einen
    // 1-API-Call-Check machen (total-Vergleich) und die TTL refreshen.
    // So bleibt der Cache ewig warm, solange die Seite besucht wird.
    private const FULL_CACHE_TTL = 2592000;

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
     * Prüft ob der volle Konzert-Cache existiert (alle Seiten zusammen).
     * Gibt ['concerts' => [...], 'total' => int] zurück wenn ja, null wenn nein.
     *
     * Trick: CacheInterface::get() ruft den Callback NUR bei Cache-Miss auf.
     * Über die &$found Variable erkennen wir ob es ein Hit oder Miss war.
     * Bei Miss setzen wir $save = false, damit nichts Sinnloses gecacht wird.
     * (Der bool &$save Parameter ist seit Symfony 6.2 verfügbar.)
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
     * Baut den vollen Cache aus den einzelnen Seiten-Caches zusammen.
     * Wird aufgerufen wenn die LETZTE Seite geladen wurde — dann existieren
     * alle Einzelseiten im Cache und wir können sie ohne API-Calls zusammensetzen.
     * Beim nächsten Besuch kommt dann alles auf einen Schlag aus diesem Cache.
     *
     * Cache-Struktur: ['concerts' => [...], 'total' => 2177]
     * Das total wird gespeichert damit checkForNewConcerts() es mit dem
     * aktuellen API-Wert vergleichen kann (Delta-Check).
     */
    public function buildFullMapCache(int $totalPages, int $total): void
    {
        // Alle Konzerte aus den Seiten-Caches zusammensammeln.
        // getMapConcertsPage() liest aus dem Cache (kein API-Call),
        // weil jede Seite bereits beim progressiven Laden gecacht wurde.
        $allConcerts = [];
        for ($page = 1; $page <= $totalPages; $page++) {
            $pageData = $this->getMapConcertsPage($page);
            array_push($allConcerts, ...$pageData['concerts']);
        }

        // Full-Cache mit 30 Tagen TTL speichern
        $this->refreshFullCache($allConcerts, $total);

        // Song Play Counts aus den per-page Song-Caches zusammenbauen.
        // Jede Seite hat ihre eigenen Song-Counts (gecacht in cacheSongCountsForPage),
        // hier aggregieren wir sie zu einem Gesamt-Ranking.
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
     * Prüft ob neue Konzerte dazugekommen sind und aktualisiert den Cache.
     * Braucht nur 1 API-Call (Seite 1) um das aktuelle total zu lesen.
     *
     * Ablauf:
     *   1. Full-Cache lesen → gespeichertes total holen
     *   2. 1 API-Call → aktuelles total von setlist.fm
     *   3a. Gleich → TTL auf frische 30 Tage zurücksetzen, Cache zurückgeben
     *   3b. Höher → Delta-Konzerte nachladen, vorne anhängen, neuen Cache speichern
     *   3c. Kein Cache → null (Progressive Loading nötig)
     *
     * @return array|null Alle Konzerte oder null bei Cold Start
     */
    public function checkForNewConcerts(): ?array
    {
        // Full-Cache lesen — gibt ['concerts' => [...], 'total' => 2177] oder null
        $cached = $this->getFullMapCacheIfAvailable();
        if ($cached === null) {
            return null; // Kein Cache vorhanden → Progressive Loading nötig
        }

        $cachedTotal = $cached['total'];
        $cachedConcerts = $cached['concerts'];

        // 1 API-Call: Seite 1 holen um aktuelles total zu prüfen.
        // fetchPage() ist uncached — wir brauchen frische Daten für den Vergleich.
        // Wenn die API down ist (Rate Limit, Timeout, 500er), geben wir einfach
        // die gecachten Daten zurück — besser alte Daten als gar keine.
        try {
            $page1 = $this->fetchPage(1);
        } catch (\Throwable) {
            return $cachedConcerts;
        }
        $currentTotal = $page1['total'] ?? 0;

        // --- Total gleich → Nichts Neues, nur TTL refreshen ---
        // Cache-Daten bleiben gleich, aber die 30-Tage-Frist startet neu.
        // So bleibt der Cache ewig warm, solange die Seite besucht wird.
        if ($currentTotal === $cachedTotal) {
            $this->refreshFullCache($cachedConcerts, $cachedTotal);
            $this->refreshSongPlayCountsTtl();
            return $cachedConcerts;
        }

        // --- Total höher → Neue Konzerte nachladen ---
        if ($currentTotal > $cachedTotal) {
            $delta = $currentTotal - $cachedTotal;

            // Neue Konzerte aus den rohen API-Daten extrahieren.
            // setlist.fm sortiert newest-first → neue Konzerte sind die ersten
            // $delta Einträge ab Seite 1. Bei > 20 neuen werden weitere Seiten geholt.
            $deltaSetlists = $this->fetchDeltaSetlists($page1, $delta);
            $newConcerts = $this->transformSetlistsForMap($deltaSetlists);

            // Neue Konzerte vorne anhängen (neueste zuerst)
            $allConcerts = array_merge($newConcerts, $cachedConcerts);

            // Full-Cache mit neuen Daten und frischer 30-Tage-TTL speichern
            $this->refreshFullCache($allConcerts, $currentTotal);

            // Song Play Counts mit den Songs aus den neuen Konzerten aktualisieren
            $newSongCounts = $this->extractSongCounts($deltaSetlists);
            $existingCounts = $this->getSongPlayCounts();

            foreach ($newSongCounts as $song => $count) {
                $existingCounts[$song] = ($existingCounts[$song] ?? 0) + $count;
            }
            arsort($existingCounts);
            $this->saveSongPlayCounts($existingCounts);

            return $allConcerts;
        }

        // Total ist gesunken (sollte bei Konzerten nicht passieren).
        // Sicherheitshalber: Cache invalidieren → Progressive Loading beim nächsten Mal.
        $this->cache->delete('setlistfm_metallica_all_map');
        return null;
    }

    /**
     * Holt die rohen Setlist-Daten für die neuen (Delta-)Konzerte.
     * Seite 1 haben wir schon (vom total-Check), bei > 20 neuen
     * werden weitere Seiten nachgeladen.
     *
     * @return array Rohe Setlist-Objekte (nur die $delta neuesten)
     */
    private function fetchDeltaSetlists(array $page1Data, int $delta): array
    {
        $allSetlists = $page1Data['setlist'] ?? [];
        $itemsPerPage = $page1Data['itemsPerPage'] ?? 20;

        // Wenn alle neuen Konzerte auf Seite 1 passen → fertig
        if ($delta <= count($allSetlists)) {
            return array_slice($allSetlists, 0, $delta);
        }

        // Mehr als eine Seite nötig → weitere Seiten nachladen.
        // Beispiel: 45 neue Konzerte → Seite 1 (20) + Seite 2 (20) + Seite 3 (5)
        $pagesNeeded = (int) ceil($delta / $itemsPerPage);
        for ($page = 2; $page <= $pagesNeeded; $page++) {
            $pageData = $this->fetchPage($page);
            array_push($allSetlists, ...($pageData['setlist'] ?? []));
        }

        return array_slice($allSetlists, 0, $delta);
    }

    /**
     * Extrahiert Song-Namen und Häufigkeiten aus rohen Setlist-Daten.
     * Gleiche Logik wie cacheSongCountsForPage(), aber ohne Caching —
     * wird für das Delta-Update der Song Play Counts benutzt.
     *
     * @return array<string, int> Song-Name (normalisiert) => Anzahl
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

    // --- Cache-Helfer: Löschen + Neu-Speichern ---
    // Symfony's CacheInterface hat keine "touch TTL"-Methode.
    // Daher: Key löschen → sofort neu schreiben mit denselben Daten + frischer TTL.
    // Nicht 100% atomar, aber für ein Portfolio-Projekt völlig ausreichend.

    /**
     * Speichert den Full-Cache (delete + re-create mit frischer 30-Tage-TTL).
     * Wird sowohl beim TTL-Refresh als auch beim Delta-Update benutzt.
     */
    private function refreshFullCache(array $concerts, int $total): void
    {
        $this->cache->delete('setlistfm_metallica_all_map');
        $this->cache->get('setlistfm_metallica_all_map', function (ItemInterface $item) use ($concerts, $total) {
            $item->expiresAfter(self::FULL_CACHE_TTL);
            return ['concerts' => $concerts, 'total' => $total];
        });
    }

    /**
     * Refresht nur die TTL des Song Play Counts Cache (Daten bleiben gleich).
     * Wird aufgerufen wenn total gleich ist — die Song-Daten ändern sich nicht,
     * aber die TTL soll wieder bei 30 Tagen starten.
     */
    private function refreshSongPlayCountsTtl(): void
    {
        $counts = $this->getSongPlayCounts();
        if (empty($counts)) {
            return;
        }
        $this->saveSongPlayCounts($counts);
    }

    /**
     * Speichert Song Play Counts im Cache (delete + re-create).
     * Wird von buildFullMapCache(), checkForNewConcerts() und refreshSongPlayCountsTtl() benutzt.
     */
    private function saveSongPlayCounts(array $counts): void
    {
        $this->cache->delete('setlistfm_song_play_counts');
        $this->cache->get('setlistfm_song_play_counts', function (ItemInterface $item) use ($counts) {
            $item->expiresAfter(self::FULL_CACHE_TTL);
            return $counts;
        });
    }

    /**
     * Liest die Song-Counts für eine einzelne Seite aus dem Cache.
     * Gibt leeres Array zurück wenn nicht gecacht.
     */
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
     * Holt EINE Seite Konzertdaten im Kartenformat (Koordinaten, Venue, etc.).
     * Jede Seite wird einzeln gecacht — so kann das Frontend Seite für Seite
     * laden und die Karte progressiv befüllen, statt 2 Minuten auf alles zu warten.
     *
     * @return array{concerts: array, page: int, totalPages: int, total: int}
     */
    public function getMapConcertsPage(int $page): array
    {
        // Jede Seite hat ihren eigenen Cache-Key.
        // Seite 1 wird beim ersten Aufruf gecacht, Seite 2 beim zweiten, usw.
        // So zahlt jeder Request nur für EINE API-Seite (~1 Sekunde),
        // nicht für alle 100+ Seiten auf einmal.
        $cacheKey = 'setlistfm_map_page_' . $page;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($page) {
            // 6 Stunden Cache wie vorher — Konzertdaten ändern sich selten
            $item->expiresAfter(21600);

            // Eine Seite von der setlist.fm API holen (mit Retry-Logik)
            $result = $this->fetchPage($page);

            // Gesamtzahl und Seiten berechnen — das Frontend braucht totalPages
            // um zu wissen, wie viele weitere Requests es machen muss.
            $total = $result['total'] ?? 0;
            $itemsPerPage = $result['itemsPerPage'] ?? 20;
            $totalPages = (int) ceil($total / $itemsPerPage);

            // Setlists ins Kartenformat umwandeln (nur Koordinaten + Metadaten)
            $concerts = $this->transformSetlistsForMap($result['setlist'] ?? []);

            // Song-Counts aus den Roh-Daten extrahieren und separat cachen.
            // Die Roh-Daten enthalten die Setlists mit Song-Namen — diese Info
            // geht bei transformSetlistsForMap() verloren. Daher cachen wir die
            // Song-Zählungen hier als Nebenprodukt, solange die Roh-Daten verfügbar sind.
            $this->cacheSongCountsForPage($page, $result['setlist'] ?? []);

            return [
                'concerts' => $concerts,
                'page' => $page,
                'totalPages' => $totalPages,
                'total' => $total,
            ];
        });
    }

    /**
     * Extrahiert Song-Counts aus den Roh-Setlists einer Seite und cacht sie separat.
     * Wird von getMapConcertsPage() aufgerufen, wenn die Roh-Daten verfügbar sind.
     */
    private function cacheSongCountsForPage(int $page, array $setlists): void
    {
        $cacheKey = 'setlistfm_songs_page_' . $page;

        // Falls schon gecacht (z.B. bei erneutem Aufruf), nichts tun
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

    /**
     * Wandelt rohe Setlist-Daten von der API ins kompakte Kartenformat um.
     * Wird von getMapConcertsPage() benutzt.
     * Filtert Konzerte ohne Koordinaten raus (können nicht auf die Karte).
     */
    private function transformSetlistsForMap(array $setlists): array
    {
        $concerts = [];

        foreach ($setlists as $setlist) {
            $venue = $setlist['venue'] ?? [];
            $city = $venue['city'] ?? [];
            $coords = $city['coords'] ?? [];

            // Nur Konzerte mit Koordinaten — ohne lat/lng kein Marker möglich
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

    /**
     * Direkter API-Call ohne Cache (wird intern von getMapConcertsPage benutzt).
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
     * Gibt die aggregierten Song Play Counts zurück.
     * Wird von buildFullMapCache() als Nebenprodukt aufgebaut,
     * wenn alle Konzertseiten geladen wurden.
     *
     * Ablauf:
     *   1. getMapConcertsPage() extrahiert Songs pro Seite → cacheSongCountsForPage()
     *   2. buildFullMapCache() aggregiert alle Seiten → setlistfm_song_play_counts
     *   3. Diese Methode liest nur noch den fertigen Cache
     *
     * @return array<string, int> Song-Name (normalisiert) => Anzahl
     */
    public function getSongPlayCounts(): array
    {
        // Liest nur aus dem Cache — wenn der noch nicht existiert
        // (Karte wurde noch nie komplett geladen), kommt ein leeres Array zurück.
        $found = true;

        $result = $this->cache->get('setlistfm_song_play_counts', function (ItemInterface $item, bool &$save) use (&$found) {
            $found = false;
            $save = false;
            return [];
        });

        return $found ? $result : [];
    }

    /**
     * Normalisiert Song-Namen für den Vergleich zwischen Spotify und setlist.fm.
     * Entfernt Klammer-Zusätze wie "(Remastered 2021)" und macht lowercase.
     */
    private function normalizeSongName(string $name): string
    {
        // Klammer-Zusätze entfernen: (Remastered), (Live), (2021 Remaster) etc.
        $name = preg_replace('/\s*\(.*?\)\s*/', '', $name);
        return mb_strtolower(trim($name));
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
