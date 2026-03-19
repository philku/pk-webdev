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
    // Member list — rendered by the MemberSearch Live Component.
    #[Route('', name: 'app_club_planner')]
    public function index(): Response
    {
        return $this->render('club_planner/index.html.twig');
    }

    // Form logic handled by the MemberForm Live Component.
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

    #[Route('/{id}/bearbeiten', name: 'app_club_planner_edit')]
    public function edit(Member $member): Response
    {
        return $this->render('club_planner/form.html.twig', [
            'member' => $member,
            'form' => $this->createForm(MemberType::class, $member),
            'title' => 'Mitglied bearbeiten',
        ]);
    }

    // Demo limits: min 7 members total (pagination), min 2 per team.
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

    // ==================== TRAINING ====================

    // Trainings grouped by team. Pre-loads per team to avoid N+1 queries.
    #[Route('/trainings', name: 'app_trainings')]
    public function trainings(TeamRepository $teamRepo, TrainingRepository $trainingRepo): Response
    {
        $teams = $teamRepo->findAll();

        $trainingsByTeam = [];
        foreach ($teams as $team) {
            $trainingsByTeam[$team->getId()] = $trainingRepo->findByTeam($team);
        }

        return $this->render('club_planner/trainings.html.twig', [
            'teams' => $teams,
            'trainingsByTeam' => $trainingsByTeam,
        ]);
    }

    // Classic handleRequest flow (no Live Component — showcases both patterns).
    // Creates attendance records for all team members on save.
    #[Route('/trainings/neu', name: 'app_training_new')]
    public function trainingNew(Request $request, EntityManagerInterface $em): Response
    {
        $training = new Training();
        $form = $this->createForm(TrainingType::class, $training);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($training);

            // Create attendance record (default: absent) for each team member.
            foreach ($training->getTeam()->getMembers() as $member) {
                $attendance = new TrainingAttendance();
                $attendance->setTraining($training);
                $attendance->setMember($member);
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

    // Team field disabled on edit — changing it would invalidate attendance records.
    #[Route('/trainings/{id}/bearbeiten', name: 'app_training_edit')]
    public function trainingEdit(Training $training, Request $request, EntityManagerInterface $em): Response
    {
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

    // Demo limit: min 3 trainings per team (for meaningful attendance stats).
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

    // GET: shows attendance form with status dropdowns per member.
    // POST: saves updated statuses. Uses manual HTML form (no CollectionType).
    #[Route('/trainings/{id}/anwesenheit', name: 'app_training_attendance')]
    public function attendance(
        Training $training,
        Request $request,
        TrainingAttendanceRepository $attendanceRepo,
        EntityManagerInterface $em,
    ): Response {
        $attendances = $attendanceRepo->findByTraining($training);

        if ($request->isMethod('POST')) {
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

    // Attendance stats per member (aggregated in repository via DQL).
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
