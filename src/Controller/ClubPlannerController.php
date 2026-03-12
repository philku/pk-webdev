<?php

namespace App\Controller;

use App\Repository\MemberRepository;
use App\Repository\TeamRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// Alle Routen in diesem Controller starten mit /vereinsplaner
#[Route('/vereinsplaner')]
class ClubPlannerController extends AbstractController
{
    // Mitgliederliste — die Hauptseite des Vereinsplaners
    #[Route('', name: 'app_club_planner')]
    public function index(MemberRepository $memberRepository, TeamRepository $teamRepository): Response
    {
        // findAll() — eine der "geschenkten" Repository-Methoden.
        // Holt alle Members als PHP-Objekte aus der DB.
        $members = $memberRepository->findAll();
        $teams = $teamRepository->findAll();

        // Die Variablen werden an das Twig-Template übergeben.
        // Im Template kannst du dann {{ members }} und {{ teams }} nutzen.
        return $this->render('club_planner/index.html.twig', [
            'members' => $members,
            'teams' => $teams,
        ]);
    }
}
