<?php

namespace App\Tests\Controller;

use App\Service\GeminiService;
use App\Service\IgdbService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class GameAdvisorControllerTest extends WebTestCase
{
    // ==================== GAME SEARCH ====================

    // Search with valid query returns JSON results.
    public function testSearchReturnsResults(): void
    {
        $client = static::createClient();

        $igdbMock = $this->createMock(IgdbService::class);
        $igdbMock->method('searchGames')
            ->willReturn([
                ['id' => 1, 'name' => 'It Takes Two', 'cover' => 'https://example.com/cover.jpg'],
            ]);
        $igdbMock->method('getPopularGames')->willReturn([]);

        $client->getContainer()->set(IgdbService::class, $igdbMock);

        $client->request('GET', '/ki-game-berater/api/search?q=takes+two');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertCount(1, $data);
        $this->assertSame('It Takes Two', $data[0]['name']);
    }

    // Search with short query (< 2 chars) returns empty array — no API call needed.
    public function testSearchWithShortQueryReturnsEmpty(): void
    {
        $client = static::createClient();
        $client->request('GET', '/ki-game-berater/api/search?q=a');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame([], $data);
    }

    // ==================== AI RECOMMENDATION ====================

    // Recommend without games returns 400.
    public function testRecommendWithoutGamesReturns400(): void
    {
        $client = static::createClient();

        $client->request('POST', '/ki-game-berater/api/recommend', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['gameIds' => [], 'platform' => 'PC']));

        $this->assertResponseStatusCodeSame(400);
    }

    // Recommend with games returns SSE stream.
    public function testRecommendReturnsStream(): void
    {
        $client = static::createClient();

        $igdbMock = $this->createMock(IgdbService::class);
        $igdbMock->method('getGamesById')
            ->willReturn([
                ['id' => 1, 'name' => 'It Takes Two'],
            ]);
        $igdbMock->method('getPopularGames')->willReturn([]);

        // GeminiService::streamRecommendations() is a generator — mock yields two chunks.
        $geminiMock = $this->createMock(GeminiService::class);
        $geminiMock->method('streamRecommendations')
            ->willReturnCallback(function () {
                yield 'Here is';
                yield ' my recommendation';
            });

        $client->getContainer()->set(IgdbService::class, $igdbMock);
        $client->getContainer()->set(GeminiService::class, $geminiMock);

        $client->request('POST', '/ki-game-berater/api/recommend', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['gameIds' => [1], 'platform' => 'PC', 'playerCount' => 2]));

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            'text/event-stream',
            $client->getResponse()->headers->get('content-type'),
        );
    }
}
