<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class NhlController extends AbstractController
{
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    #[Route('/nhl-standings', name: 'app_nhl_standings')]
    public function index(): Response
    {
        return $this->render('nhl/index.html.twig');
    }

    // CORS proxy for the NHL API — React fetches from here instead of api-web.nhle.com.
    #[Route('/api/nhl/standings', name: 'api_nhl_standings')]
    public function standings(): JsonResponse
    {
        $response = $this->httpClient->request('GET', 'https://api-web.nhle.com/v1/standings/now');

        return new JsonResponse($response->toArray(), $response->getStatusCode());
    }

    #[Route('/api/nhl/roster/{abbrev}', name: 'api_nhl_roster')]
    public function roster(string $abbrev): JsonResponse
    {
        $response = $this->httpClient->request('GET', sprintf(
            'https://api-web.nhle.com/v1/roster/%s/current',
            $abbrev,
        ));

        return new JsonResponse($response->toArray(), $response->getStatusCode());
    }

    #[Route('/nhl-standings/team/{abbrev}', name: 'app_nhl_team_detail')]
    public function teamDetail(): Response
    {
        return $this->render('nhl/index.html.twig');
    }
}
