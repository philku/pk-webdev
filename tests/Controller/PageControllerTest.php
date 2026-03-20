<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PageControllerTest extends WebTestCase
{
    // Data provider: each yield is [URL, expected status code].
    public static function publicUrlProvider(): iterable
    {
        // Static pages
        yield 'Startseite' => ['/', 200];
        yield 'Tech Demos' => ['/tech-demos', 200];

        // Club planner — lists and forms (no ID needed)
        yield 'Vereinsplaner' => ['/vereinsplaner', 200];
        yield 'Mitglied anlegen' => ['/vereinsplaner/neu', 200];
        yield 'Trainings' => ['/vereinsplaner/trainings', 200];
        yield 'Training anlegen' => ['/vereinsplaner/trainings/neu', 200];

        // Demos — main pages (no external API call on page load)
        yield 'Metallica' => ['/metallica', 200];
        yield 'Game Advisor' => ['/ki-game-berater', 200];
        yield 'NHL Standings' => ['/nhl-standings', 200];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('publicUrlProvider')]
    public function testPublicPages(string $url, int $expectedStatusCode): void
    {
        $client = static::createClient();
        $client->request('GET', $url);
        $this->assertResponseStatusCodeSame($expectedStatusCode);
    }
}
