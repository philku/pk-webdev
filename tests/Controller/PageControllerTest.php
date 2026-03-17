<?php

// Namespace muss zur Ordnerstruktur passen: tests/Controller/ → App\Tests\Controller
namespace App\Tests\Controller;

// WebTestCase gibt uns einen simulierten Browser (Client) —
// damit können wir HTTP-Requests an unsere App schicken ohne echten Browser.
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PageControllerTest extends WebTestCase
{
    // Ein "Data Provider" liefert mehrere Testfälle an eine einzige Testmethode.
    // Jedes Array ist ein Testfall: [URL, erwarteter Status-Code].
    // So vermeiden wir, für jede Route eine eigene Methode zu schreiben.
    public static function publicUrlProvider(): iterable
    {
        // Statische Seiten
        yield 'Startseite' => ['/', 200];
        yield 'Über mich' => ['/ueber-mich', 200];
        yield 'Tech Demos' => ['/tech-demos', 200];
        yield 'Kontakt' => ['/kontakt', 200];

        // Vereinsplaner — Listen und Formulare (brauchen keine ID)
        yield 'Vereinsplaner' => ['/vereinsplaner', 200];
        yield 'Mitglied anlegen' => ['/vereinsplaner/neu', 200];
        yield 'Trainings' => ['/vereinsplaner/trainings', 200];
        yield 'Training anlegen' => ['/vereinsplaner/trainings/neu', 200];

        // Demos — Hauptseiten (keine externe API beim Seitenaufruf)
        yield 'Metallica' => ['/metallica', 200];
        yield 'Game Advisor' => ['/ki-game-berater', 200];
        yield 'NHL Standings' => ['/nhl-standings', 200];
    }

    // PHPUnit ruft diese Methode einmal pro yield im Data Provider auf.
    // #[DataProvider] verknüpft die Methode mit dem Provider oben.
    #[\PHPUnit\Framework\Attributes\DataProvider('publicUrlProvider')]
    public function testPublicPages(string $url, int $expectedStatusCode): void
    {
        // createClient() startet die Symfony-App im Test-Modus (APP_ENV=test)
        // und gibt uns einen Client zurück, mit dem wir Requests simulieren.
        $client = static::createClient();

        // Simuliert einen GET-Request an die URL — kein echter HTTP-Request,
        // sondern direkt durch den Symfony-Kernel geschickt.
        $client->request('GET', $url);

        // Prüft ob der Response-Status-Code dem erwarteten entspricht.
        // assertResponseStatusCodeSame() ist eine Symfony-Hilfsmethode
        // die bei Fehlern auch den Response-Body ausgibt — hilft beim Debuggen.
        $this->assertResponseStatusCodeSame($expectedStatusCode);
    }
}
