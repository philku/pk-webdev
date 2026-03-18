<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class NhlControllerTest extends WebTestCase
{
    // Prüft: Die NHL-Standings-Seite (Twig-Template) lädt ohne API-Call.
    public function testStandingsPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/nhl-standings');

        $this->assertResponseIsSuccessful();
    }

    // Prüft: Der Standings-Proxy gibt die API-Daten als JSON zurück.
    //
    // MockHttpClient ersetzt den echten HttpClient im Symfony-Container.
    // Statt einen Request an api-web.nhle.com zu schicken, gibt er
    // eine vordefinierte JSON-Antwort zurück. So testen wir unseren
    // Controller-Code ohne echten API-Call.
    public function testStandingsProxyReturnsJson(): void
    {
        $client = static::createClient();

        // Fake-Antwort definieren — minimale Struktur wie die echte NHL API.
        $mockResponse = new MockResponse(
            json_encode(['standings' => [['teamAbbrev' => ['default' => 'BOS']]]]),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );

        // MockHttpClient im Container registrieren.
        // Das überschreibt den echten HttpClient nur für diesen Test.
        $client->getContainer()->set('http_client', new MockHttpClient($mockResponse));

        $client->request('GET', '/api/nhl/standings');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        // Prüfen: Die Fake-Daten kommen korrekt durch.
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('standings', $data);
        $this->assertSame('BOS', $data['standings'][0]['teamAbbrev']['default']);
    }

    // Prüft: Der Roster-Proxy gibt Team-Daten als JSON zurück.
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
