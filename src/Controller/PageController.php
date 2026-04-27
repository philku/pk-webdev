<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
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
}
