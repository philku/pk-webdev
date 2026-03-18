<?php

namespace App\Tests\Controller;

use App\Service\SetlistFmService;
use App\Service\SpotifyService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class MetallicaControllerTest extends WebTestCase
{
    // ==================== KONZERTE API ====================

    // Prüft: /api/concerts gibt JSON zurück wenn der Full-Cache existiert.
    // Mock: checkForNewConcerts() gibt gecachte Konzertdaten zurück.
    public function testConcertsApiReturnsJsonFromCache(): void
    {
        $client = static::createClient();

        // Mock für SetlistFmService erstellen.
        // createMock() erzeugt ein Fake-Objekt das wie der echte Service aussieht,
        // aber keine echten API-Calls macht.
        $setlistFmMock = $this->createMock(SetlistFmService::class);

        // Wenn checkForNewConcerts() aufgerufen wird, soll es Fake-Daten zurückgeben
        // statt die setlist.fm API aufzurufen.
        $setlistFmMock->method('checkForNewConcerts')
            ->willReturn([
                ['venue' => 'Olympiastadion', 'city' => 'Berlin', 'date' => '2024-06-14'],
            ]);

        // Mock im Symfony-Container registrieren — überschreibt den echten Service.
        $client->getContainer()->set(SetlistFmService::class, $setlistFmMock);

        $client->request('GET', '/metallica/api/concerts');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['complete']);
        $this->assertCount(1, $data['concerts']);
    }

    // Prüft: /api/concerts mit ?page=1 gibt paginierte Daten zurück (Cold Start).
    public function testConcertsApiPaginatedRequest(): void
    {
        $client = static::createClient();

        $setlistFmMock = $this->createMock(SetlistFmService::class);
        $setlistFmMock->method('getMapConcertsPage')
            ->willReturn([
                'concerts' => [['venue' => 'Arena', 'city' => 'München']],
                'page' => 1,
                'totalPages' => 5,
                'total' => 100,
            ]);

        $client->getContainer()->set(SetlistFmService::class, $setlistFmMock);

        $client->request('GET', '/metallica/api/concerts?page=1');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(1, $data['page']);
        $this->assertArrayHasKey('concerts', $data);
    }

    // ==================== SETLIST DETAIL ====================

    // Prüft: Setlist-Detailseite rendert korrekt mit gemockten Daten.
    public function testSetlistPageLoads(): void
    {
        $client = static::createClient();

        $setlistFmMock = $this->createMock(SetlistFmService::class);
        $setlistFmMock->method('getSetlist')
            ->willReturn([
                'id' => 'abc123',
                'eventDate' => '14-06-2024',
                'venue' => ['name' => 'Olympiastadion', 'city' => ['name' => 'Berlin']],
                'sets' => ['set' => []],
            ]);

        $client->getContainer()->set(SetlistFmService::class, $setlistFmMock);

        $client->request('GET', '/metallica/setlist/abc123');

        $this->assertResponseIsSuccessful();
    }

    // Prüft: Setlist mit unbekannter ID gibt 404.
    public function testSetlistNotFoundReturns404(): void
    {
        $client = static::createClient();

        $setlistFmMock = $this->createMock(SetlistFmService::class);
        $setlistFmMock->method('getSetlist')->willReturn(null);

        $client->getContainer()->set(SetlistFmService::class, $setlistFmMock);

        $client->request('GET', '/metallica/setlist/ungueltig');

        $this->assertResponseStatusCodeSame(404);
    }

    // ==================== ALBUM DETAIL ====================

    // Prüft: Album-Detailseite rendert korrekt mit gemockten Spotify-Daten.
    public function testAlbumPageLoads(): void
    {
        $client = static::createClient();

        $spotifyMock = $this->createMock(SpotifyService::class);
        // Mock-Daten müssen der Struktur entsprechen, die SpotifyService::getAlbum()
        // zurückgibt — nicht der rohen Spotify-API. Der Service transformiert die Daten
        // zu einem flachen Array mit 'image', 'spotify_url', 'tracks' etc.
        $spotifyMock->method('getAlbum')
            ->willReturn([
                'name' => 'Master of Puppets',
                'release_date' => '1986-03-03',
                'total_tracks' => 8,
                'label' => 'Elektra',
                'image' => 'https://example.com/cover.jpg',
                'spotify_url' => 'https://open.spotify.com/album/123',
                'tracks' => [
                    ['number' => 1, 'name' => 'Battery', 'duration_ms' => 312000, 'spotify_url' => null],
                ],
                'copyrights' => [],
            ]);

        $client->getContainer()->set(SpotifyService::class, $spotifyMock);

        $client->request('GET', '/metallica/discography/album/some-spotify-id');

        $this->assertResponseIsSuccessful();
    }

    // Prüft: Album-Seite gibt 404 wenn Spotify den Fehler wirft.
    public function testAlbumNotFoundReturns404(): void
    {
        $client = static::createClient();

        $spotifyMock = $this->createMock(SpotifyService::class);
        $spotifyMock->method('getAlbum')
            ->willThrowException(new \RuntimeException('Not found'));

        $client->getContainer()->set(SpotifyService::class, $spotifyMock);

        $client->request('GET', '/metallica/discography/album/ungueltig');

        $this->assertResponseStatusCodeSame(404);
    }

    // ==================== SONG STATS ====================

    // Prüft: /api/discography/stats gibt Top-10-Songs als JSON zurück.
    public function testDiscographyStatsReturnsJson(): void
    {
        $client = static::createClient();

        $setlistFmMock = $this->createMock(SetlistFmService::class);

        // getSongPlayCounts() liefert ein assoziatives Array: 'Songname' => Anzahl
        $setlistFmMock->method('getSongPlayCounts')
            ->willReturn([
                'Enter Sandman' => 1200,
                'Master of Puppets' => 1100,
                'Nothing Else Matters' => 1050,
            ]);

        $client->getContainer()->set(SetlistFmService::class, $setlistFmMock);

        $client->request('GET', '/metallica/api/discography/stats');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertCount(3, $data['topPlayed']);
        $this->assertSame('Enter Sandman', $data['topPlayed'][0]['name']);
        $this->assertSame(1200, $data['topPlayed'][0]['count']);
    }
}
