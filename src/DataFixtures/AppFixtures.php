<?php

namespace App\DataFixtures;

use App\Entity\Member;
use App\Entity\Team;
use App\Entity\Training;
use App\Entity\TrainingAttendance;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $teamA = new Team();
        $teamA->setName('Erste Mannschaft');
        $teamA->setSport('Fußball');

        $teamB = new Team();
        $teamB->setName('A-Jugend');
        $teamB->setSport('Fußball');

        $teamC = new Team();
        $teamC->setName('Lauftreff');
        $teamC->setSport('Leichtathletik');

        $manager->persist($teamA);
        $manager->persist($teamB);
        $manager->persist($teamC);

        $members = [
            ['Max Müller', 'max@example.com', '0171-1234567', 'Torwart', 'Spieler', $teamA],
            ['Lisa Schmidt', 'lisa@example.com', '0172-2345678', 'Stürmerin', 'Spieler', $teamA],
            ['Tom Weber', 'tom@example.com', null, 'Mittelfeld', 'Spieler', $teamA],
            ['Sarah Fischer', 'sarah@example.com', '0173-3456789', null, 'Trainer', $teamA],
            ['Jan Becker', 'jan@example.com', null, 'Verteidiger', 'Spieler', $teamA],
            ['Laura Klein', 'laura@example.com', '0174-4567890', 'Torwart', 'Spieler', $teamB],
            ['Felix Braun', 'felix@example.com', null, 'Stürmer', 'Spieler', $teamB],
            ['Marie Schulz', 'marie@example.com', null, 'Mittelfeld', 'Spieler', $teamB],
            ['Paul Richter', 'paul@example.com', '0175-5678901', null, 'Trainer', $teamB],
            ['Anna Hoffmann', 'anna@example.com', null, null, 'Mitglied', $teamC],
            ['Kai Wagner', 'kai@example.com', '0176-6789012', null, 'Mitglied', $teamC],
            ['Nina Lange', 'nina@example.com', null, null, 'Betreuer', $teamC],
        ];

        $membersByTeam = [];

        foreach ($members as [$name, $email, $phone, $position, $role, $team]) {
            $member = new Member();
            $member->setName($name);
            $member->setEmail($email);
            $member->setPhone($phone);
            $member->setPosition($position);
            $member->setRole($role);
            $member->setTeam($team);
            $member->setJoinedAt(new \DateTime('-' . rand(1, 36) . ' months'));

            $manager->persist($member);

            $teamId = spl_object_id($team);
            $membersByTeam[$teamId][] = $member;
        }

        $trainings = [
            [$teamA, -14, '18:00', 'Sportplatz Süd', 'Taktiktraining'],
            [$teamA, -7, '18:00', 'Sportplatz Süd', 'Spielvorbereitung'],
            [$teamA, 0, '18:30', 'Sporthalle Nord', 'Konditionstraining'],
            [$teamA, 7, '18:00', 'Sportplatz Süd', 'Freies Training'],
            [$teamB, -10, '16:30', 'Kunstrasenplatz', 'Techniktraining'],
            [$teamB, -3, '16:30', 'Kunstrasenplatz', 'Spielformen'],
        ];

        // Weighted distribution: ~60% present, ~25% absent, ~15% excused.
        $statuses = ['anwesend', 'anwesend', 'anwesend', 'anwesend', 'anwesend', 'anwesend',
                     'abwesend', 'abwesend', 'abwesend',
                     'entschuldigt', 'entschuldigt'];

        foreach ($trainings as [$team, $dayOffset, $time, $location, $description]) {
            $training = new Training();
            $training->setScheduledAt(new \DateTime("$dayOffset days $time"));
            $training->setLocation($location);
            $training->setDescription($description);
            $training->setTeam($team);

            $manager->persist($training);

            $teamId = spl_object_id($team);
            foreach ($membersByTeam[$teamId] ?? [] as $member) {
                $attendance = new TrainingAttendance();
                $attendance->setTraining($training);
                $attendance->setMember($member);
                $attendance->setStatus($statuses[array_rand($statuses)]);

                $manager->persist($attendance);
            }
        }

        $manager->flush();
    }
}
