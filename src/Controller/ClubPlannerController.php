<?php

namespace App\Controller;

use App\Entity\Member;
use App\Form\MemberType;
use App\Repository\MemberRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
// Request und EntityManagerInterface bleiben für die delete()-Methode.
use Symfony\Component\Routing\Attribute\Route;

#[Route('/vereinsplaner')]
class ClubPlannerController extends AbstractController
{
    // ---------- Liste ----------
    #[Route('', name: 'app_club_planner')]
    public function index(MemberRepository $memberRepository, TeamRepository $teamRepository): Response
    {
        return $this->render('club_planner/index.html.twig', [
            'members' => $memberRepository->findAll(),
            'teams' => $teamRepository->findAll(),
        ]);
    }

    // ---------- Neues Mitglied erstellen ----------
    // Die Formular-Logik (Validierung, Speichern) steckt jetzt in der
    // Live Component "MemberForm". Der Controller liefert nur noch die
    // Seite mit dem leeren Formular aus.
    #[Route('/neu', name: 'app_club_planner_new')]
    public function new(): Response
    {
        $member = new Member();

        return $this->render('club_planner/form.html.twig', [
            'member' => $member,
            'form' => $this->createForm(MemberType::class, $member),
            'title' => 'Neues Mitglied',
        ]);
    }

    // ---------- Mitglied bearbeiten ----------
    // Auch hier: die eigentliche Logik steckt in der Live Component.
    // Der Controller liefert nur die Seite mit dem befüllten Formular.
    #[Route('/{id}/bearbeiten', name: 'app_club_planner_edit')]
    public function edit(Member $member): Response
    {
        return $this->render('club_planner/form.html.twig', [
            'member' => $member,
            'form' => $this->createForm(MemberType::class, $member),
            'title' => 'Mitglied bearbeiten',
        ]);
    }

    // ---------- Mitglied löschen ----------
    #[Route('/{id}/loeschen', name: 'app_club_planner_delete', methods: ['POST'])]
    public function delete(Member $member, Request $request, EntityManagerInterface $em): Response
    {
        // CSRF-Token prüfen — Schutz gegen Cross-Site Request Forgery.
        // Verhindert, dass jemand von einer fremden Seite aus einen Lösch-Request schickt.
        if ($this->isCsrfTokenValid('delete-' . $member->getId(), $request->request->get('_token'))) {
            $em->remove($member);
            $em->flush();

            $this->addFlash('success', 'Mitglied wurde gelöscht.');
        }

        return $this->redirectToRoute('app_club_planner');
    }
}
