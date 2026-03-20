<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class NhlControllerTest extends WebTestCase
{
    // Standings page (Twig template) loads without API call.
    public function testStandingsPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/nhl-standings');

        $this->assertResponseIsSuccessful();
    }

    // Standings proxy returns API data as JSON via MockHttpClient.
    public function testStandingsProxyReturnsJson(): void
    {
        $client = static::createClient();

        $mockResponse = new MockResponse(
            json_encode(['standings' => [['teamAbbrev' => ['default' => 'BOS']]]]),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );

        $client->getContainer()->set('http_client', new MockHttpClient($mockResponse));

        $client->request('GET', '/api/nhl/standings');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('standings', $data);
        $this->assertSame('BOS', $data['standings'][0]['teamAbbrev']['default']);
    }

    // Roster proxy returns team data as JSON.
    public function testRosterProxyReturnsJson(): void
    {
        $client = static::createClient();

        $mockResponse = new MockResponse(
            json_encode([
                'forwards' => [['firstName' => ['default' => 'David'], 'lastName' => ['default' => 'Pastrnak']]],
                'defensemen' => [],
                'goalies' => [],
            ]),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );

        $client->getContainer()->set('http_client', new MockHttpClient($mockResponse));

        $client->request('GET', '/api/nhl/roster/BOS');

        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('forwards', $data);
        $this->assertSame('Pastrnak', $data['forwards'][0]['lastName']['default']);
    }
}
