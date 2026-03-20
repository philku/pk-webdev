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

    // Runs before each test — creates client and clears DB tables.
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();

        // Clear tables — order matters due to foreign keys.
        $this->em->getConnection()->executeStatement('DELETE FROM training_attendance');
        $this->em->getConnection()->executeStatement('DELETE FROM training');
        $this->em->getConnection()->executeStatement('DELETE FROM member');
        $this->em->getConnection()->executeStatement('DELETE FROM team');
    }

    // Helper: create a team in the test DB.
    private function createTeam(string $name = 'Erste Mannschaft', string $sport = 'Fußball'): Team
    {
        $team = new Team();
        $team->setName($name);
        $team->setSport($sport);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    // Helper: create a member in the test DB.
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

    // ==================== MEMBER LIST ====================

    public function testIndexPageLoads(): void
    {
        $this->client->request('GET', '/vereinsplaner');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Vereinsplaner');
    }

    // ==================== CREATE MEMBER ====================

    public function testNewMemberFormLoads(): void
    {
        $this->client->request('GET', '/vereinsplaner/neu');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Neues Mitglied');
    }

    // ==================== EDIT MEMBER ====================

    public function testEditMemberFormLoads(): void
    {
        $team = $this->createTeam();
        $member = $this->createMember($team);

        $this->client->request('GET', '/vereinsplaner/' . $member->getId() . '/bearbeiten');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Mitglied bearbeiten');
    }

    public function testEditNonExistentMemberReturns404(): void
    {
        $this->client->request('GET', '/vereinsplaner/99999/bearbeiten');

        $this->assertResponseStatusCodeSame(404);
    }

    // ==================== DELETE MEMBER ====================

    // Delete with valid CSRF token removes the member.
    // Requires enough members (min 8) so delete limits don't block.
    public function testDeleteMemberWithValidCsrf(): void
    {
        $team = $this->createTeam();
        $team2 = $this->createTeam('A-Jugend', 'Fußball');
        // 8 members — after deleting one, 7 remain (= minimum).
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
        $this->assertNull($deleted, 'Member should be deleted from DB');
    }

    // Delete limit: exactly 7 members — cannot delete any.
    public function testDeleteMemberBlockedByTotalLimit(): void
    {
        $team = $this->createTeam();
        $team2 = $this->createTeam('A-Jugend', 'Fußball');
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

        $this->em->clear();
        $stillExists = $this->em->getRepository(Member::class)->find($memberId);
        $this->assertNotNull($stillExists, 'Member must not be deleted when only 7 exist');
    }

    // Delete limit: team with only 2 members — cannot lose one.
    public function testDeleteMemberBlockedByTeamLimit(): void
    {
        $team = $this->createTeam();
        $team2 = $this->createTeam('A-Jugend', 'Fußball');
        $member = $this->createMember($team, 'AAA Testmitglied', 'aaa@test.de');
        $this->createMember($team, 'BBB Teamkollege', 'bbb@test.de');
        // Enough total members (> 7) so only team limit applies.
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
        $this->assertNotNull($stillExists, 'Member must not be deleted when team has only 2 members');
    }

    // Invalid CSRF token does not delete — CSRF protection works.
    public function testDeleteMemberWithInvalidCsrfDoesNotDelete(): void
    {
        $team = $this->createTeam();
        $member = $this->createMember($team);
        $memberId = $member->getId();

        $this->client->request('POST', '/vereinsplaner/' . $memberId . '/loeschen', [
            '_token' => 'ungueltig',
        ]);

        $this->assertResponseRedirects('/vereinsplaner');

        $this->em->clear();
        $stillExists = $this->em->getRepository(Member::class)->find($memberId);
        $this->assertNotNull($stillExists, 'Member must not be deleted with invalid CSRF token');
    }

    // ==================== TRAININGS ====================

    // Helper: create a training in the test DB.
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

    public function testTrainingsPageLoads(): void
    {
        $this->client->request('GET', '/vereinsplaner/trainings');

        $this->assertResponseIsSuccessful();
    }

    public function testNewTrainingFormLoads(): void
    {
        $this->client->request('GET', '/vereinsplaner/trainings/neu');

        $this->assertResponseIsSuccessful();
    }

    // Create training via form submit — also verifies attendance entries are auto-created.
    public function testCreateTrainingViaForm(): void
    {
        $team = $this->createTeam();
        $member = $this->createMember($team);

        $this->client->request('GET', '/vereinsplaner/trainings/neu');

        $this->client->submitForm('Speichern', [
            'training[scheduledAt]' => '2026-04-01T18:00',
            'training[location]' => 'Sportplatz Nord',
            'training[description]' => 'Taktiktraining',
            'training[team]' => $team->getId(),
        ]);

        $this->assertResponseRedirects('/vereinsplaner/trainings');

        $training = $this->em->getRepository(Training::class)->findOneBy([
            'location' => 'Sportplatz Nord',
        ]);
        $this->assertNotNull($training, 'Training should be saved in DB');

        // Attendance entries auto-created for each team member (default: abwesend).
        $attendances = $this->em->getRepository(TrainingAttendance::class)->findBy([
            'training' => $training,
        ]);
        $this->assertCount(1, $attendances, 'One attendance entry per member');
        $this->assertSame('abwesend', $attendances[0]->getStatus());
    }

    public function testEditTrainingFormLoads(): void
    {
        $team = $this->createTeam();
        $training = $this->createTraining($team);

        $this->client->request('GET', '/vereinsplaner/trainings/' . $training->getId() . '/bearbeiten');

        $this->assertResponseIsSuccessful();
    }

    // Edit training — changes are persisted.
    public function testEditTrainingViaForm(): void
    {
        $team = $this->createTeam();
        $training = $this->createTraining($team);
        $trainingId = $training->getId();

        $this->client->request('GET', '/vereinsplaner/trainings/' . $trainingId . '/bearbeiten');

        // Team field not included in edit mode (include_team: false).
        $this->client->submitForm('Speichern', [
            'training[scheduledAt]' => '2026-04-15T19:00',
            'training[location]' => 'Halle 3',
            'training[description]' => 'Ausdauertraining',
        ]);

        $this->assertResponseRedirects('/vereinsplaner/trainings');

        $this->em->clear();
        $updated = $this->em->getRepository(Training::class)->find($trainingId);
        $this->assertSame('Halle 3', $updated->getLocation());
    }

    // Delete training with valid CSRF — requires min 4 trainings (limit: 3 per team).
    public function testDeleteTrainingWithValidCsrf(): void
    {
        $team = $this->createTeam();
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
        $this->assertNull($deleted, 'Training should be deleted from DB');
    }

    // Delete limit: exactly 3 trainings — cannot delete any.
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

        $this->em->clear();
        $stillExists = $this->em->getRepository(Training::class)->find($trainingId);
        $this->assertNotNull($stillExists, 'Training must not be deleted when team has only 3 trainings');
    }

    public function testEditNonExistentTrainingReturns404(): void
    {
        $this->client->request('GET', '/vereinsplaner/trainings/99999/bearbeiten');

        $this->assertResponseStatusCodeSame(404);
    }

    // Invalid CSRF token does not delete training.
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
        $this->assertNotNull($stillExists, 'Training must not be deleted with invalid CSRF token');
    }

    // ==================== ATTENDANCE ====================

    // Attendance page loads for a training with members.
    public function testAttendancePageLoads(): void
    {
        $team = $this->createTeam();
        $member = $this->createMember($team);
        $training = $this->createTraining($team);

        $attendance = new TrainingAttendance();
        $attendance->setTraining($training);
        $attendance->setMember($member);
        $this->em->persist($attendance);
        $this->em->flush();

        $this->client->request('GET', '/vereinsplaner/trainings/' . $training->getId() . '/anwesenheit');

        $this->assertResponseIsSuccessful();
    }

    // Save attendance — status is correctly updated.
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

        $this->client->request('POST', '/vereinsplaner/trainings/' . $training->getId() . '/anwesenheit', [
            'status' => [
                $member->getId() => 'anwesend',
            ],
        ]);

        $this->assertResponseRedirects('/vereinsplaner/trainings/' . $training->getId() . '/anwesenheit');

        $this->em->clear();
        $updated = $this->em->getRepository(TrainingAttendance::class)->findOneBy([
            'training' => $training->getId(),
            'member' => $member->getId(),
        ]);
        $this->assertSame('anwesend', $updated->getStatus());
    }

    public function testAttendanceNonExistentTrainingReturns404(): void
    {
        $this->client->request('GET', '/vereinsplaner/trainings/99999/anwesenheit');

        $this->assertResponseStatusCodeSame(404);
    }

    // ==================== TRAINING QUOTE ====================

    public function testTrainingQuoteLoads(): void
    {
        $team = $this->createTeam();

        $this->client->request('GET', '/vereinsplaner/trainings/quote/' . $team->getId());

        $this->assertResponseIsSuccessful();
    }

    public function testTrainingQuoteNonExistentTeamReturns404(): void
    {
        $this->client->request('GET', '/vereinsplaner/trainings/quote/99999');

        $this->assertResponseStatusCodeSame(404);
    }
}
