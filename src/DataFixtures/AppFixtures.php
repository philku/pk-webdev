<?php

namespace App\DataFixtures;

use App\Entity\Member;
use App\Entity\Team;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // --- Teams erstellen ---
        $teamA = new Team();
        $teamA->setName('Erste Mannschaft');
        $teamA->setSport('Fußball');

        $teamB = new Team();
        $teamB->setName('A-Jugend');
        $teamB->setSport('Fußball');

        $teamC = new Team();
        $teamC->setName('Lauftreff');
        $teamC->setSport('Leichtathletik');

        // persist() = "Doctrine, merk dir dieses Objekt, das soll in die DB."
        // Es wird aber noch NICHT gespeichert — das passiert erst bei flush().
        $manager->persist($teamA);
        $manager->persist($teamB);
        $manager->persist($teamC);

        // --- Members erstellen ---
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
        }

        // flush() = "Jetzt alles auf einmal in die DB schreiben."
        // Doctrine sammelt alle persist()-Aufrufe und macht dann EIN großes INSERT.
        $manager->flush();
    }
}
