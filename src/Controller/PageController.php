<?php

namespace App\Controller;

use App\Form\ContactType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

class PageController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        return $this->render('page/home.html.twig');
    }

    #[Route('/tech-demos', name: 'app_demos')]
    public function demos(): Response
    {
        return $this->render('page/demos.html.twig');
    }

    #[Route('/datenschutz', name: 'app_datenschutz')]
    public function datenschutz(): Response
    {
        return $this->render('page/datenschutz.html.twig');
    }

    #[Route('/impressum', name: 'app_impressum')]
    public function impressum(): Response
    {
        return $this->render('page/impressum.html.twig');
    }

    #[Route('/contact', name: 'app_contact')]
    public function contact(Request $request, MailerInterface $mailer): Response
    {
        $form = $this->createForm(ContactType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $email = (new Email())
                ->from($data['email'])
                ->to('p.kuechau@gmx.de')
                ->subject('Kontaktanfrage von ' . $data['name'])
                ->text($data['message']);

            $mailer->send($email);

            $this->addFlash('success', 'Danke für deine Nachricht!');
            return $this->redirectToRoute('app_contact');
        }
        return $this->render('page/contact.html.twig', [
            'form' => $form,
        ]);
    }
}
