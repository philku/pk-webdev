<?php

namespace App\Tests\Controller;

use App\Entity\Member;
use App\Entity\Team;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ClubPlannerControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    // setUp() läuft VOR jedem einzelnen Test.
    // Wir erstellen den Client einmal und holen uns darüber den EntityManager.
    // In Symfony 8 darf createClient() nur einmal pro Test aufgerufen werden —
    // deshalb speichern wir den Client als Property und nutzen ihn in allen Methoden.
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();

        // Tabellen leeren — damit jeder Test mit sauberem Zustand startet.
        // Reihenfolge wichtig: erst Member (hat FK auf Team), dann Team.
        $this->em->getConnection()->executeStatement('DELETE FROM training_attendance');
        $this->em->getConnection()->executeStatement('DELETE FROM training');
        $this->em->getConnection()->executeStatement('DELETE FROM member');
        $this->em->getConnection()->executeStatement('DELETE FROM team');
    }

    // Hilfsmethode: Erstellt ein Team in der Test-DB und gibt es zurück.
    private function createTeam(string $name = 'Erste Mannschaft', string $sport = 'Fußball'): Team
    {
        $team = new Team();
        $team->setName($name);
        $team->setSport($sport);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    // Hilfsmethode: Erstellt ein Mitglied in der Test-DB.
    private function createMember(Team $team, string $name = 'Max Mustermann', string $email = 'max@test.de'): Member
    {
        $member = new Member();
        $member->setName($name);
        $member->setEmail($email);
        $member->setRole('Spieler');
        $member->setTeam($team);
        $this->em->persist($member);
        $this->em->flush();

        return $member;
    }

    // ==================== MITGLIEDERLISTE ====================

    // Prüft: Vereinsplaner-Seite lädt und zeigt die Überschrift.
    public function testIndexPageLoads(): void
    {
        $this->client->request('GET', '/vereinsplaner');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Vereinsplaner');
    }

    // ==================== MITGLIED ERSTELLEN ====================

    // Prüft: Das "Neues Mitglied"-Formular wird korrekt angezeigt.
    public function testNewMemberFormLoads(): void
    {
        $this->client->request('GET', '/vereinsplaner/neu');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Neues Mitglied');
    }

    // ==================== MITGLIED BEARBEITEN ====================

    // Prüft: Edit-Seite lädt korrekt für ein existierendes Mitglied.
    public function testEditMemberFormLoads(): void
    {
        $team = $this->createTeam();
        $member = $this->createMember($team);

        $this->client->request('GET', '/vereinsplaner/' . $member->getId() . '/bearbeiten');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Mitglied bearbeiten');
    }

    // Prüft: Edit-Seite mit ungültiger ID gibt 404.
    public function testEditNonExistentMemberReturns404(): void
    {
        $this->client->request('GET', '/vereinsplaner/99999/bearbeiten');

        $this->assertResponseStatusCodeSame(404);
    }

    // ==================== MITGLIED LÖSCHEN ====================

    // Prüft: Löschen mit gültigem CSRF-Token entfernt das Mitglied
    // und leitet zurück zur Mitgliederliste.
    public function testDeleteMemberWithValidCsrf(): void
    {
        $team = $this->createTeam();
        $member = $this->createMember($team);
        $memberId = $member->getId();

        // Mitgliederliste laden — die Live Component rendert für jedes Mitglied
        // ein Lösch-Formular mit eingebettetem CSRF-Token.
        // Wir extrahieren das Token aus dem HTML, genau wie ein echter Browser.
        $crawler = $this->client->request('GET', '/vereinsplaner');
        $deleteForm = $crawler->filter('form[action$="/' . $memberId . '/loeschen"]');
        $csrfToken = $deleteForm->filter('input[name="_token"]')->attr('value');

        // POST-Request mit dem extrahierten CSRF-Token simulieren.
        $this->client->request('POST', '/vereinsplaner/' . $memberId . '/loeschen', [
            '_token' => $csrfToken,
        ]);

        // Erwartung: Redirect (302) zurück zur Mitgliederliste.
        $this->assertResponseRedirects('/vereinsplaner');

        // Prüfen: Mitglied ist wirklich aus der DB gelöscht.
        $this->em->clear();
        $deleted = $this->em->getRepository(Member::class)->find($memberId);
        $this->assertNull($deleted, 'Mitglied sollte nach dem Löschen nicht mehr in der DB sein');
    }

    // Prüft: Löschen OHNE gültiges CSRF-Token löscht NICHT —
    // der CSRF-Schutz funktioniert.
    public function testDeleteMemberWithInvalidCsrfDoesNotDelete(): void
    {
        $team = $this->createTeam();
        $member = $this->createMember($team);
        $memberId = $member->getId();

        // Absichtlich falsches Token schicken.
        $this->client->request('POST', '/vereinsplaner/' . $memberId . '/loeschen', [
            '_token' => 'ungueltig',
        ]);

        // Redirect passiert trotzdem (Controller gibt immer Redirect zurück).
        $this->assertResponseRedirects('/vereinsplaner');

        // Aber: Mitglied ist NICHT gelöscht — CSRF-Schutz hat gegriffen.
        $this->em->clear();
        $stillExists = $this->em->getRepository(Member::class)->find($memberId);
        $this->assertNotNull($stillExists, 'Mitglied darf bei ungültigem CSRF nicht gelöscht werden');
    }

    // ==================== TRAININGS ====================

    // Prüft: Trainingsliste lädt korrekt.
    public function testTrainingsPageLoads(): void
    {
        $this->client->request('GET', '/vereinsplaner/trainings');

        $this->assertResponseIsSuccessful();
    }

    // Prüft: "Neues Training"-Formular wird angezeigt.
    public function testNewTrainingFormLoads(): void
    {
        $this->client->request('GET', '/vereinsplaner/trainings/neu');

        $this->assertResponseIsSuccessful();
    }

    // ==================== TRAININGSQUOTE ====================

    // Prüft: Trainingsquote-Seite lädt mit gültigem Team.
    public function testTrainingQuoteLoads(): void
    {
        $team = $this->createTeam();

        $this->client->request('GET', '/vereinsplaner/trainings/quote/' . $team->getId());

        $this->assertResponseIsSuccessful();
    }
}
