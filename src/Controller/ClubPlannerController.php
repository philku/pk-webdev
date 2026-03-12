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
    #[Route('/neu', name: 'app_club_planner_new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $member = new Member();

        // createForm() erstellt das Formular aus unserer MemberType-Klasse
        // und verknüpft es mit dem leeren Member-Objekt.
        $form = $this->createForm(MemberType::class, $member);

        // handleRequest() prüft: Wurde das Formular abgeschickt?
        // Wenn ja, schreibt es die Werte automatisch in $member.
        $form->handleRequest($request);

        // isSubmitted() = wurde es abgeschickt?
        // isValid() = sind alle Validierungsregeln (#[Assert\...]) erfüllt?
        if ($form->isSubmitted() && $form->isValid()) {
            // persist() = "Doctrine, merk dir dieses Objekt"
            $em->persist($member);
            // flush() = "Jetzt ab in die Datenbank"
            $em->flush();

            // Flash Message = einmalige Erfolgsmeldung, die nach dem Redirect angezeigt wird
            $this->addFlash('success', 'Mitglied wurde angelegt.');

            return $this->redirectToRoute('app_club_planner');
        }

        return $this->render('club_planner/form.html.twig', [
            'form' => $form,
            'title' => 'Neues Mitglied',
        ]);
    }

    // ---------- Mitglied bearbeiten ----------
    // {id} in der URL = Symfony holt automatisch den Member mit dieser ID aus der DB
    #[Route('/{id}/bearbeiten', name: 'app_club_planner_edit')]
    public function edit(Member $member, Request $request, EntityManagerInterface $em): Response
    {
        // $member ist schon aus der DB geladen (wegen {id} im Route).
        // Das Formular wird mit den existierenden Werten befüllt.
        $form = $this->createForm(MemberType::class, $member);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Kein persist() nötig! Doctrine "trackt" den Member schon,
            // weil er aus der DB geladen wurde. flush() reicht.
            $em->flush();

            $this->addFlash('success', 'Mitglied wurde aktualisiert.');

            return $this->redirectToRoute('app_club_planner');
        }

        return $this->render('club_planner/form.html.twig', [
            'form' => $form,
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
