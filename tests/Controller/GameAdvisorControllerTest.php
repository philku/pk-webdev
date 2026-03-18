<?php

namespace App\Tests\Controller;

use App\Service\GeminiService;
use App\Service\IgdbService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class GameAdvisorControllerTest extends WebTestCase
{
    // ==================== GAME-SUCHE ====================

    // Prüft: Suche mit gültigem Query gibt JSON-Ergebnisse zurück.
    public function testSearchReturnsResults(): void
    {
        $client = static::createClient();

        $igdbMock = $this->createMock(IgdbService::class);
        $igdbMock->method('searchGames')
            ->willReturn([
                ['id' => 1, 'name' => 'It Takes Two', 'cover' => 'https://example.com/cover.jpg'],
            ]);
        // getPopularGames() wird nicht aufgerufen, muss aber gemockt sein
        // falls der Service im Container registriert wird.
        $igdbMock->method('getPopularGames')->willReturn([]);

        $client->getContainer()->set(IgdbService::class, $igdbMock);

        $client->request('GET', '/ki-game-berater/api/search?q=takes+two');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertCount(1, $data);
        $this->assertSame('It Takes Two', $data[0]['name']);
    }

    // Prüft: Suche mit zu kurzem Query (< 2 Zeichen) gibt leeres Array zurück.
    // Kein API-Call nötig — der Controller fängt das vorher ab.
    public function testSearchWithShortQueryReturnsEmpty(): void
    {
        $client = static::createClient();
        $client->request('GET', '/ki-game-berater/api/search?q=a');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame([], $data);
    }

    // ==================== KI-EMPFEHLUNG ====================

    // Prüft: Empfehlung ohne Spiele gibt 400 zurück.
    public function testRecommendWithoutGamesReturns400(): void
    {
        $client = static::createClient();

        $client->request('POST', '/ki-game-berater/api/recommend', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['gameIds' => [], 'platform' => 'PC']));

        $this->assertResponseStatusCodeSame(400);
    }

    // Prüft: Empfehlung mit Spielen gibt SSE-Stream zurück.
    public function testRecommendReturnsStream(): void
    {
        $client = static::createClient();

        $igdbMock = $this->createMock(IgdbService::class);
        $igdbMock->method('getGamesById')
            ->willReturn([
                ['id' => 1, 'name' => 'It Takes Two'],
            ]);
        $igdbMock->method('getPopularGames')->willReturn([]);

        // GeminiService::streamRecommendations() ist ein Generator.
        // Wir mocken ihn so, dass er zwei Chunks yieldet.
        $geminiMock = $this->createMock(GeminiService::class);
        $geminiMock->method('streamRecommendations')
            ->willReturnCallback(function () {
                yield 'Hier ist';
                yield ' meine Empfehlung';
            });

        $client->getContainer()->set(IgdbService::class, $igdbMock);
        $client->getContainer()->set(GeminiService::class, $geminiMock);

        $client->request('POST', '/ki-game-berater/api/recommend', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['gameIds' => [1], 'platform' => 'PC', 'playerCount' => 2]));

        $this->assertResponseIsSuccessful();
        // Content-Type enthält ggf. charset-Suffix, daher assertStringContainsString.
        $this->assertStringContainsString(
            'text/event-stream',
            $client->getResponse()->headers->get('content-type'),
        );
    }
}
