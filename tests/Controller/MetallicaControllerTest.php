<?php

namespace App\Tests\Controller;

use App\Service\SetlistFmService;
use App\Service\SpotifyService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class MetallicaControllerTest extends WebTestCase
{
    // ==================== CONCERTS API ====================

    // /api/concerts returns JSON when full cache exists.
    public function testConcertsApiReturnsJsonFromCache(): void
    {
        $client = static::createClient();

        $setlistFmMock = $this->createMock(SetlistFmService::class);
        $setlistFmMock->method('checkForNewConcerts')
            ->willReturn([
                ['venue' => 'Olympiastadion', 'city' => 'Berlin', 'date' => '2024-06-14'],
            ]);

        $client->getContainer()->set(SetlistFmService::class, $setlistFmMock);

        $client->request('GET', '/metallica/api/concerts');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['complete']);
        $this->assertCount(1, $data['concerts']);
    }

    // /api/concerts?page=1 returns paginated data (cold start).
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

    // Setlist detail page renders correctly with mocked data.
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

    // Unknown setlist ID returns 404.
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

    // Album detail page renders correctly with mocked Spotify data.
    public function testAlbumPageLoads(): void
    {
        $client = static::createClient();

        $spotifyMock = $this->createMock(SpotifyService::class);
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

    // Album page returns 404 when Spotify throws error.
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

    // /api/discography/stats returns top 10 songs as JSON.
    public function testDiscographyStatsReturnsJson(): void
    {
        $client = static::createClient();

        $setlistFmMock = $this->createMock(SetlistFmService::class);
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
