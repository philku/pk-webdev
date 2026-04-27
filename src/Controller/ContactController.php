<?php

namespace App\Controller;

use App\Form\ContactType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

class ContactController extends AbstractController
{
    #[Route('/contact', name: 'app_contact')]
    public function contact(Request $request, MailerInterface $mailer, RateLimiterFactory $contactFormLimiter): Response
    {
        $form = $this->createForm(ContactType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // Prevent contact form spam (3 submits per hour)
            $limiter = $contactFormLimiter->create($request->getClientIp());
            if (!$limiter->consume()->isAccepted()) {
                $this->addFlash('success', 'Danke für deine Nachricht!');
                return $this->redirectToRoute('app_contact');
            }

            $data = $form->getData();

            // Check for bots
            if (!empty($data['website'])) {
                $this->addFlash('success', 'Danke für deine Nachricht!');
                return $this->redirectToRoute('app_contact');
            }

            $email = (new Email())
                ->from('p.kuechau@gmx.de')
                ->replyTo($data['email'])
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
