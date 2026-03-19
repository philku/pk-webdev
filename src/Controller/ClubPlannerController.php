<?php

namespace App\Controller;

use App\Entity\Member;
use App\Entity\Team;
use App\Entity\Training;
use App\Entity\TrainingAttendance;
use App\Form\MemberType;
use App\Form\TrainingType;
use App\Repository\MemberRepository;
use App\Repository\TeamRepository;
use App\Repository\TrainingAttendanceRepository;
use App\Repository\TrainingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/vereinsplaner')]
class ClubPlannerController extends AbstractController
{
    // ---------- Liste ----------
    // Die Mitgliederliste wird jetzt von der Live Component "MemberSearch" gerendert.
    // Der Controller liefert nur noch die Seite aus — die Daten holt die Komponente selbst.
    #[Route('', name: 'app_club_planner')]
    public function index(): Response
    {
        return $this->render('club_planner/index.html.twig');
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
    // Lösch-Limits: Mind. 7 Mitglieder insgesamt (damit Pagination sichtbar bleibt)
    // und mind. 2 Mitglieder pro Team. Limits existieren zu Demo-Zwecken,
    // damit der Vereinsplaner immer genug Daten zum Anzeigen hat.
    #[Route('/{id}/loeschen', name: 'app_club_planner_delete', methods: ['POST'])]
    public function delete(Member $member, Request $request, EntityManagerInterface $em, MemberRepository $memberRepo): Response
    {
        if ($this->isCsrfTokenValid('delete-' . $member->getId(), $request->request->get('_token'))) {
            $totalMembers = $memberRepo->count([]);
            $teamMemberCount = $memberRepo->count(['team' => $member->getTeam()]);

            if ($totalMembers <= 7) {
                $this->addFlash('error', 'Löschen nicht möglich — es müssen mindestens 7 Mitglieder existieren (Demo-Limit).');
            } elseif ($teamMemberCount <= 2) {
                $this->addFlash('error', 'Löschen nicht möglich — es müssen mindestens 2 Mitglieder pro Team existieren (Demo-Limit).');
            } else {
                $em->remove($member);
                $em->flush();
                $this->addFlash('success', 'Mitglied wurde gelöscht.');
            }
        }

        return $this->redirectToRoute('app_club_planner');
    }

    // ==================== TRAININGSPLANER ====================

    // ---------- Trainingsliste (gruppiert nach Team) ----------
    // Zeigt alle Trainings, gruppiert nach ihrem Team.
    // findByTeam() liefert sie schon nach Datum sortiert (neueste zuerst).
    #[Route('/trainings', name: 'app_trainings')]
    public function trainings(TeamRepository $teamRepo, TrainingRepository $trainingRepo): Response
    {
        $teams = $teamRepo->findAll();

        // Trainings pro Team vorladen — verhindert N+1 Queries.
        // Ohne das würde Twig für jedes Team einen eigenen Query abfeuern.
        $trainingsByTeam = [];
        foreach ($teams as $team) {
            $trainingsByTeam[$team->getId()] = $trainingRepo->findByTeam($team);
        }

        return $this->render('club_planner/trainings.html.twig', [
            'teams' => $teams,
            'trainingsByTeam' => $trainingsByTeam,
        ]);
    }

    // ---------- Neues Training erstellen ----------
    // Klassischer handleRequest-Flow — bewusst KEIN Live Component,
    // damit im Portfolio beide Patterns sichtbar sind.
    // Nach dem Speichern: Für jedes Team-Mitglied automatisch einen
    // Anwesenheitseintrag erstellen (Default: 'abwesend').
    #[Route('/trainings/neu', name: 'app_training_new')]
    public function trainingNew(Request $request, EntityManagerInterface $em): Response
    {
        $training = new Training();
        $form = $this->createForm(TrainingType::class, $training);

        // handleRequest() liest die POST-Daten und befüllt das Training-Objekt.
        // isSubmitted() + isValid() prüft ob Daten da sind UND Validierung passt.
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($training);

            // Für jedes Mitglied im gewählten Team einen Anwesenheitseintrag erstellen.
            // Das ist der Moment wo die Pivot-Entity zum Einsatz kommt:
            // Jeder Member bekommt einen Eintrag mit Status 'abwesend'.
            // Der Trainer ändert den Status dann auf der Anwesenheits-Seite.
            foreach ($training->getTeam()->getMembers() as $member) {
                $attendance = new TrainingAttendance();
                $attendance->setTraining($training);
                $attendance->setMember($member);
                // Status ist per Default 'abwesend' (in der Entity definiert)
                $em->persist($attendance);
            }

            $em->flush();
            $this->addFlash('success', 'Training wurde erstellt.');

            return $this->redirectToRoute('app_trainings');
        }

        return $this->render('club_planner/training_form.html.twig', [
            'form' => $form,
            'title' => 'Neues Training',
        ]);
    }

    // ---------- Training bearbeiten ----------
    // Team-Feld ist hier nicht änderbar (include_team: false),
    // weil ein Team-Wechsel die Attendances ungültig machen würde.
    #[Route('/trainings/{id}/bearbeiten', name: 'app_training_edit')]
    public function trainingEdit(Training $training, Request $request, EntityManagerInterface $em): Response
    {
        // include_team: false → Team-Dropdown wird nicht angezeigt
        $form = $this->createForm(TrainingType::class, $training, [
            'include_team' => false,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Training wurde aktualisiert.');

            return $this->redirectToRoute('app_trainings');
        }

        return $this->render('club_planner/training_form.html.twig', [
            'form' => $form,
            'title' => 'Training bearbeiten',
            'training' => $training,
        ]);
    }

    // ---------- Training löschen ----------
    // Lösch-Limit: Mind. 3 Trainings pro Team, damit die Trainingsquote
    // sinnvolle Daten zeigen kann. Limit existiert zu Demo-Zwecken.
    #[Route('/trainings/{id}/loeschen', name: 'app_training_delete', methods: ['POST'])]
    public function trainingDelete(Training $training, Request $request, EntityManagerInterface $em, TrainingRepository $trainingRepo): Response
    {
        if ($this->isCsrfTokenValid('delete-training-' . $training->getId(), $request->request->get('_token'))) {
            $teamTrainingCount = count($trainingRepo->findByTeam($training->getTeam()));

            if ($teamTrainingCount <= 3) {
                $this->addFlash('error', 'Löschen nicht möglich — es müssen mindestens 3 Trainings pro Team existieren (Demo-Limit).');
            } else {
                $em->remove($training);
                $em->flush();
                $this->addFlash('success', 'Training wurde gelöscht.');
            }
        }

        return $this->redirectToRoute('app_trainings');
    }

    // ---------- Anwesenheit erfassen ----------
    // GET: Zeigt alle Mitglieder mit ihrem aktuellen Status (Select-Dropdown).
    // POST: Speichert die geänderten Status-Werte.
    // Kein Symfony CollectionType — wäre Overengineering für simple Select-Felder.
    // Stattdessen: manuelles HTML-Formular mit <select name="status[{memberId}]">.
    #[Route('/trainings/{id}/anwesenheit', name: 'app_training_attendance')]
    public function attendance(
        Training $training,
        Request $request,
        TrainingAttendanceRepository $attendanceRepo,
        EntityManagerInterface $em,
    ): Response {
        $attendances = $attendanceRepo->findByTraining($training);

        // POST: Status-Werte aus dem Formular speichern
        if ($request->isMethod('POST')) {
            // $statusData = ['memberId' => 'anwesend', 'memberId' => 'abwesend', ...]
            $statusData = $request->request->all('status');

            foreach ($attendances as $attendance) {
                $memberId = (string) $attendance->getMember()->getId();

                if (isset($statusData[$memberId])) {
                    $attendance->setStatus($statusData[$memberId]);
                }
            }

            $em->flush();
            $this->addFlash('success', 'Anwesenheit wurde gespeichert.');

            return $this->redirectToRoute('app_training_attendance', ['id' => $training->getId()]);
        }

        return $this->render('club_planner/attendance.html.twig', [
            'training' => $training,
            'attendances' => $attendances,
        ]);
    }

    // ---------- Trainingsquote pro Team ----------
    // Zeigt für jedes Mitglied: Wie viele Trainings, wie oft anwesend, Prozent.
    // Die Aggregation passiert im Repository (DQL mit COUNT, SUM CASE WHEN, GROUP BY).
    #[Route('/trainings/quote/{id}', name: 'app_training_quote')]
    public function quote(Team $team, TrainingAttendanceRepository $attendanceRepo): Response
    {
        $stats = $attendanceRepo->getAttendanceQuote($team);

        return $this->render('club_planner/quote.html.twig', [
            'team' => $team,
            'stats' => $stats,
        ]);
    }
}
