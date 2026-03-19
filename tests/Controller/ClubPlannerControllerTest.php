<?php

namespace App\Tests\Controller;

use App\Entity\Member;
use App\Entity\Team;
use App\Entity\Training;
use App\Entity\TrainingAttendance;
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
    // Es müssen genug Mitglieder existieren (mind. 8), damit das Lösch-Limit
    // (mind. 7 gesamt + mind. 2 pro Team) nicht greift.
    public function testDeleteMemberWithValidCsrf(): void
    {
        $team = $this->createTeam();
        $team2 = $this->createTeam('A-Jugend', 'Fußball');
        // 8 Mitglieder erstellen — nach dem Löschen eines bleiben 7 (= Minimum).
        // "AAA" sortiert alphabetisch ganz nach vorne → erscheint auf Seite 1 der Pagination.
        $member = $this->createMember($team, 'AAA Löschkandidat', 'delete@test.de');
        for ($i = 1; $i <= 4; $i++) {
            $this->createMember($team, "Spieler $i", "spieler$i@test.de");
        }
        for ($i = 5; $i <= 7; $i++) {
            $this->createMember($team2, "Spieler $i", "spieler$i@test.de");
        }
        $memberId = $member->getId();

        $crawler = $this->client->request('GET', '/vereinsplaner');
        $deleteForm = $crawler->filter('form[action$="/' . $memberId . '/loeschen"]');
        $csrfToken = $deleteForm->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/vereinsplaner/' . $memberId . '/loeschen', [
            '_token' => $csrfToken,
        ]);

        $this->assertResponseRedirects('/vereinsplaner');

        $this->em->clear();
        $deleted = $this->em->getRepository(Member::class)->find($memberId);
        $this->assertNull($deleted, 'Mitglied sollte nach dem Löschen nicht mehr in der DB sein');
    }

    // Prüft: Lösch-Limit greift — bei genau 7 Mitgliedern kann keins gelöscht werden.
    public function testDeleteMemberBlockedByTotalLimit(): void
    {
        $team = $this->createTeam();
        $team2 = $this->createTeam('A-Jugend', 'Fußball');
        // "AAA" → erscheint auf Seite 1.
        $member = $this->createMember($team, 'AAA Testmitglied', 'aaa@test.de');
        for ($i = 1; $i <= 3; $i++) {
            $this->createMember($team, "Spieler $i", "spieler$i@test.de");
        }
        for ($i = 4; $i <= 6; $i++) {
            $this->createMember($team2, "Spieler $i", "spieler$i@test.de");
        }
        $memberId = $member->getId();

        $crawler = $this->client->request('GET', '/vereinsplaner');
        $deleteForm = $crawler->filter('form[action$="/' . $memberId . '/loeschen"]');
        $csrfToken = $deleteForm->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/vereinsplaner/' . $memberId . '/loeschen', [
            '_token' => $csrfToken,
        ]);

        $this->assertResponseRedirects('/vereinsplaner');

        // Mitglied darf NICHT gelöscht sein — Limit greift.
        $this->em->clear();
        $stillExists = $this->em->getRepository(Member::class)->find($memberId);
        $this->assertNotNull($stillExists, 'Mitglied darf nicht gelöscht werden wenn nur 7 existieren');
    }

    // Prüft: Lösch-Limit greift — Team mit nur 2 Mitgliedern kann keins verlieren.
    public function testDeleteMemberBlockedByTeamLimit(): void
    {
        $team = $this->createTeam();
        $team2 = $this->createTeam('A-Jugend', 'Fußball');
        // "AAA" → erscheint auf Seite 1. Nur 2 im Team — Minimum.
        $member = $this->createMember($team, 'AAA Testmitglied', 'aaa@test.de');
        $this->createMember($team, 'BBB Teamkollege', 'bbb@test.de');
        // Genug Mitglieder insgesamt (> 7), damit nur das Team-Limit greift.
        for ($i = 1; $i <= 7; $i++) {
            $this->createMember($team2, "Spieler $i", "spieler$i@test.de");
        }
        $memberId = $member->getId();

        $crawler = $this->client->request('GET', '/vereinsplaner');
        $deleteForm = $crawler->filter('form[action$="/' . $memberId . '/loeschen"]');
        $csrfToken = $deleteForm->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/vereinsplaner/' . $memberId . '/loeschen', [
            '_token' => $csrfToken,
        ]);

        $this->assertResponseRedirects('/vereinsplaner');

        $this->em->clear();
        $stillExists = $this->em->getRepository(Member::class)->find($memberId);
        $this->assertNotNull($stillExists, 'Mitglied darf nicht gelöscht werden wenn Team nur 2 Mitglieder hat');
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

    // Hilfsmethode: Erstellt ein Training in der Test-DB.
    private function createTraining(Team $team, string $date = '2026-03-20 18:00'): Training
    {
        $training = new Training();
        $training->setScheduledAt(new \DateTime($date));
        $training->setLocation('Sportplatz Süd');
        $training->setTeam($team);
        $this->em->persist($training);
        $this->em->flush();

        return $training;
    }

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

    // Prüft: Training erstellen über Formular-Submit.
    // submitForm() liest das CSRF-Token automatisch aus dem gerenderten Formular —
    // wir müssen es nicht manuell extrahieren.
    public function testCreateTrainingViaForm(): void
    {
        $team = $this->createTeam();
        $member = $this->createMember($team);

        // Formularseite laden
        $this->client->request('GET', '/vereinsplaner/trainings/neu');

        // Formular ausfüllen und absenden.
        // 'training[...]' entspricht dem Symfony Form-Name (TrainingType → 'training').
        $this->client->submitForm('Speichern', [
            'training[scheduledAt]' => '2026-04-01T18:00',
            'training[location]' => 'Sportplatz Nord',
            'training[description]' => 'Taktiktraining',
            'training[team]' => $team->getId(),
        ]);

        // Erwartung: Redirect zur Trainingsliste nach erfolgreichem Speichern.
        $this->assertResponseRedirects('/vereinsplaner/trainings');

        // Prüfen: Training ist in der DB.
        $training = $this->em->getRepository(Training::class)->findOneBy([
            'location' => 'Sportplatz Nord',
        ]);
        $this->assertNotNull($training, 'Training sollte in der DB gespeichert sein');

        // Prüfen: Anwesenheitseinträge wurden automatisch für jedes Team-Mitglied erstellt.
        // Der Controller erstellt pro Member einen TrainingAttendance-Eintrag (Default: 'abwesend').
        $attendances = $this->em->getRepository(TrainingAttendance::class)->findBy([
            'training' => $training,
        ]);
        $this->assertCount(1, $attendances, 'Für jedes Mitglied sollte ein Anwesenheitseintrag existieren');
        $this->assertSame('abwesend', $attendances[0]->getStatus());
    }

    // Prüft: Training bearbeiten — Formular lädt mit bestehenden Daten.
    public function testEditTrainingFormLoads(): void
    {
        $team = $this->createTeam();
        $training = $this->createTraining($team);

        $this->client->request('GET', '/vereinsplaner/trainings/' . $training->getId() . '/bearbeiten');

        $this->assertResponseIsSuccessful();
    }

    // Prüft: Training bearbeiten — Änderungen werden gespeichert.
    public function testEditTrainingViaForm(): void
    {
        $team = $this->createTeam();
        $training = $this->createTraining($team);
        $trainingId = $training->getId();

        $this->client->request('GET', '/vereinsplaner/trainings/' . $trainingId . '/bearbeiten');

        // Formular absenden mit neuen Daten.
        // Team-Feld ist im Edit-Modus nicht enthalten (include_team: false).
        $this->client->submitForm('Speichern', [
            'training[scheduledAt]' => '2026-04-15T19:00',
            'training[location]' => 'Halle 3',
            'training[description]' => 'Ausdauertraining',
        ]);

        $this->assertResponseRedirects('/vereinsplaner/trainings');

        // Prüfen: Daten sind aktualisiert.
        $this->em->clear();
        $updated = $this->em->getRepository(Training::class)->find($trainingId);
        $this->assertSame('Halle 3', $updated->getLocation());
    }

    // Prüft: Training löschen mit gültigem CSRF-Token.
    // Es müssen genug Trainings existieren (mind. 4), damit das Lösch-Limit
    // (mind. 3 pro Team) nicht greift.
    public function testDeleteTrainingWithValidCsrf(): void
    {
        $team = $this->createTeam();
        // 4 Trainings erstellen — nach dem Löschen eines bleiben 3 (= Minimum).
        $this->createTraining($team, '2026-03-18 18:00');
        $this->createTraining($team, '2026-03-19 18:00');
        $this->createTraining($team, '2026-03-20 18:00');
        $training = $this->createTraining($team, '2026-03-21 18:00');
        $trainingId = $training->getId();

        $crawler = $this->client->request('GET', '/vereinsplaner/trainings');
        $deleteForm = $crawler->filter('form[action$="/' . $trainingId . '/loeschen"]');
        $csrfToken = $deleteForm->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/vereinsplaner/trainings/' . $trainingId . '/loeschen', [
            '_token' => $csrfToken,
        ]);

        $this->assertResponseRedirects('/vereinsplaner/trainings');

        $this->em->clear();
        $deleted = $this->em->getRepository(Training::class)->find($trainingId);
        $this->assertNull($deleted, 'Training sollte nach dem Löschen nicht mehr in der DB sein');
    }

    // Prüft: Lösch-Limit greift — bei genau 3 Trainings kann keins gelöscht werden.
    public function testDeleteTrainingBlockedByLimit(): void
    {
        $team = $this->createTeam();
        $this->createTraining($team, '2026-03-18 18:00');
        $this->createTraining($team, '2026-03-19 18:00');
        $training = $this->createTraining($team, '2026-03-20 18:00');
        $trainingId = $training->getId();

        $crawler = $this->client->request('GET', '/vereinsplaner/trainings');
        $deleteForm = $crawler->filter('form[action$="/' . $trainingId . '/loeschen"]');
        $csrfToken = $deleteForm->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/vereinsplaner/trainings/' . $trainingId . '/loeschen', [
            '_token' => $csrfToken,
        ]);

        $this->assertResponseRedirects('/vereinsplaner/trainings');

        // Training darf NICHT gelöscht sein — Limit greift.
        $this->em->clear();
        $stillExists = $this->em->getRepository(Training::class)->find($trainingId);
        $this->assertNotNull($stillExists, 'Training darf nicht gelöscht werden wenn Team nur 3 Trainings hat');
    }

    // Prüft: Training bearbeiten mit ungültiger ID gibt 404.
    public function testEditNonExistentTrainingReturns404(): void
    {
        $this->client->request('GET', '/vereinsplaner/trainings/99999/bearbeiten');

        $this->assertResponseStatusCodeSame(404);
    }

    // Prüft: Training löschen mit ungültigem CSRF löscht nicht.
    public function testDeleteTrainingWithInvalidCsrfDoesNotDelete(): void
    {
        $team = $this->createTeam();
        $training = $this->createTraining($team);
        $trainingId = $training->getId();

        $this->client->request('POST', '/vereinsplaner/trainings/' . $trainingId . '/loeschen', [
            '_token' => 'ungueltig',
        ]);

        $this->assertResponseRedirects('/vereinsplaner/trainings');

        $this->em->clear();
        $stillExists = $this->em->getRepository(Training::class)->find($trainingId);
        $this->assertNotNull($stillExists, 'Training darf bei ungültigem CSRF nicht gelöscht werden');
    }

    // ==================== ANWESENHEIT ====================

    // Prüft: Anwesenheitsseite lädt für ein Training mit Mitgliedern.
    public function testAttendancePageLoads(): void
    {
        $team = $this->createTeam();
        $member = $this->createMember($team);
        $training = $this->createTraining($team);

        // Anwesenheitseintrag erstellen (wie der Controller es beim Training-Erstellen tut)
        $attendance = new TrainingAttendance();
        $attendance->setTraining($training);
        $attendance->setMember($member);
        $this->em->persist($attendance);
        $this->em->flush();

        $this->client->request('GET', '/vereinsplaner/trainings/' . $training->getId() . '/anwesenheit');

        $this->assertResponseIsSuccessful();
    }

    // Prüft: Anwesenheit speichern — Status wird korrekt aktualisiert.
    public function testUpdateAttendanceStatus(): void
    {
        $team = $this->createTeam();
        $member = $this->createMember($team);
        $training = $this->createTraining($team);

        $attendance = new TrainingAttendance();
        $attendance->setTraining($training);
        $attendance->setMember($member);
        $this->em->persist($attendance);
        $this->em->flush();

        // Status von 'abwesend' (Default) auf 'anwesend' ändern.
        // Das Formular schickt status[memberId] = 'anwesend'.
        $this->client->request('POST', '/vereinsplaner/trainings/' . $training->getId() . '/anwesenheit', [
            'status' => [
                $member->getId() => 'anwesend',
            ],
        ]);

        $this->assertResponseRedirects('/vereinsplaner/trainings/' . $training->getId() . '/anwesenheit');

        // Prüfen: Status wurde aktualisiert.
        $this->em->clear();
        $updated = $this->em->getRepository(TrainingAttendance::class)->findOneBy([
            'training' => $training->getId(),
            'member' => $member->getId(),
        ]);
        $this->assertSame('anwesend', $updated->getStatus());
    }

    // Prüft: Anwesenheitsseite mit ungültiger Training-ID gibt 404.
    public function testAttendanceNonExistentTrainingReturns404(): void
    {
        $this->client->request('GET', '/vereinsplaner/trainings/99999/anwesenheit');

        $this->assertResponseStatusCodeSame(404);
    }

    // ==================== TRAININGSQUOTE ====================

    // Prüft: Trainingsquote-Seite lädt mit gültigem Team.
    public function testTrainingQuoteLoads(): void
    {
        $team = $this->createTeam();

        $this->client->request('GET', '/vereinsplaner/trainings/quote/' . $team->getId());

        $this->assertResponseIsSuccessful();
    }

    // Prüft: Trainingsquote mit ungültiger Team-ID gibt 404.
    public function testTrainingQuoteNonExistentTeamReturns404(): void
    {
        $this->client->request('GET', '/vereinsplaner/trainings/quote/99999');

        $this->assertResponseStatusCodeSame(404);
    }
}
