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

// #[AsLiveComponent] = Diese Klasse ist ein Live Component.
// Live Components können sich selbst neu rendern, ohne die ganze Seite neu zu laden.
// Symfony erkennt sie automatisch und verbindet sie mit dem passenden Twig-Template.
#[AsLiveComponent]
class MemberForm extends AbstractController
{
    // DefaultActionTrait stellt die Standard-Action bereit, die bei jedem
    // Re-Render aufgerufen wird (z.B. wenn ein Feld sich ändert).
    use DefaultActionTrait;

    // ComponentWithFormTrait bringt die gesamte Formular-Logik mit:
    // - formValues: speichert die aktuellen Feld-Werte
    // - validatedFields: merkt sich, welche Felder schon geprüft wurden
    // - isValidated: ob das ganze Formular validiert wurde
    // - submitForm(): schickt die Werte an den Server zur Validierung
    use ComponentWithFormTrait;

    // LiveProp = ein Property, das zwischen Browser und Server synchronisiert wird.
    // writable: false (Standard) = der Wert kommt nur vom Server, nicht vom Browser.
    // Das ist wichtig für die Member-ID: die soll der User nicht manipulieren können.
    #[LiveProp]
    public ?Member $initialFormData = null;

    // Diese Methode wird von ComponentWithFormTrait verlangt (abstract).
    // Sie erstellt das Symfony-Formular und verknüpft es mit dem Member-Objekt.
    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(MemberType::class, $this->initialFormData);
    }

    // #[LiveAction] = eine Methode, die vom Browser aus aufgerufen werden kann.
    // Wird getriggert wenn der User auf "Speichern" klickt.
    // Rückgabetyp Response = damit Symfony den Redirect ausführt.
    #[LiveAction]
    public function save(EntityManagerInterface $em): Response
    {
        // submitForm(true) = Formular absenden UND alle Felder validieren.
        // Wenn Fehler da sind, wirft es eine Exception und das Formular
        // wird mit Fehlermeldungen neu gerendert (kein Redirect).
        $this->submitForm();

        // getForm()->getData() gibt uns das fertige Member-Objekt zurück,
        // in das die Formularwerte automatisch eingetragen wurden.
        /** @var Member $member */
        $member = $this->getForm()->getData();

        $em->persist($member);
        $em->flush();

        // Nach erfolgreichem Speichern: Flash-Message setzen und zurück zur Liste.
        $this->addFlash('success', $this->initialFormData?->getId()
            ? 'Mitglied wurde aktualisiert.'
            : 'Mitglied wurde angelegt.'
        );

        // return statt nur Aufruf — Live Components brauchen den Response-Rückgabewert,
        // damit der Browser den Redirect tatsächlich ausführt.
        return $this->redirectToRoute('app_club_planner');
    }
}
