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

    // Proxy für die NHL Standings API — umgeht CORS-Einschränkungen.
    // React fetcht /api/nhl/standings statt direkt api-web.nhle.com.
    #[Route('/api/nhl/standings', name: 'api_nhl_standings')]
    public function standings(): JsonResponse
    {
        $response = $this->httpClient->request('GET', 'https://api-web.nhle.com/v1/standings/now');

        return new JsonResponse($response->toArray(), $response->getStatusCode());
    }

    // Proxy für die NHL Roster API — lädt den Kader eines Teams.
    #[Route('/api/nhl/roster/{abbrev}', name: 'api_nhl_roster')]
    public function roster(string $abbrev): JsonResponse
    {
        $response = $this->httpClient->request('GET', sprintf(
            'https://api-web.nhle.com/v1/roster/%s/current',
            $abbrev,
        ));

        return new JsonResponse($response->toArray(), $response->getStatusCode());
    }
}
