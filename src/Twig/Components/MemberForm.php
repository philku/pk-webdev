<?php

namespace App\Twig\Components;

use App\Entity\Member;
use App\Form\MemberType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class MemberForm extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;

    // Read-only (not writable) — prevents client-side ID manipulation.
    #[LiveProp]
    public ?Member $initialFormData = null;

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(MemberType::class, $this->initialFormData);
    }

    #[LiveAction]
    public function save(EntityManagerInterface $em): Response
    {
        $this->submitForm();

        /** @var Member $member */
        $member = $this->getForm()->getData();

        $em->persist($member);
        $em->flush();

        $this->addFlash('success', $this->initialFormData?->getId()
            ? 'Mitglied wurde aktualisiert.'
            : 'Mitglied wurde angelegt.'
        );

        return $this->redirectToRoute('app_club_planner');
    }
}
