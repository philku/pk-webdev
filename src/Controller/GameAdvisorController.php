<?php

namespace App\Controller;

use App\Service\IgdbService;
use App\Service\GeminiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/ki-game-berater')]
class GameAdvisorController extends AbstractController
{
    // Loads pre-selected games from IGDB (cached) for the landing page.
    // All interaction (platform, game selection, AI recommendation) happens client-side via Stimulus.
    #[Route('', name: 'app_game_advisor')]
    public function index(IgdbService $igdb): Response
    {
        try {
            $popularGames = $igdb->getPopularGames();
        } catch (\Exception) {
            $popularGames = [];
        }

        return $this->render('game_advisor/index.html.twig', [
            'popularGames' => $popularGames,
        ]);
    }

    // JSON endpoint for the search autocomplete. Min 2 chars to avoid wasteful API calls.
    #[Route('/api/search', name: 'app_game_advisor_search')]
    public function search(Request $request, IgdbService $igdb): JsonResponse
    {
        $query = trim($request->query->get('q', ''));

        if (mb_strlen($query) < 2) {
            return $this->json([]);
        }

        try {
            $results = $igdb->searchGames($query);
        } catch (\Exception) {
            $results = [];
        }

        return $this->json($results);
    }

    // Streams Gemini's recommendation as Server-Sent Events.
    // Fetches game details from IGDB, then pipes the LLM response chunk by chunk.
    #[Route('/api/recommend', name: 'app_game_advisor_recommend', methods: ['POST'])]
    public function recommend(Request $request, IgdbService $igdb, GeminiService $gemini): Response
    {
        $data = json_decode($request->getContent(), true);
        $gameIds = $data['gameIds'] ?? [];
        $platform = $data['platform'] ?? 'PC';
        $playerCount = $data['playerCount'] ?? 2;

        if (empty($gameIds)) {
            return $this->json(['error' => 'Keine Spiele ausgewählt'], 400);
        }

        $games = $igdb->getGamesById($gameIds);

        if (empty($games)) {
            return $this->json(['error' => 'Spiele konnten nicht geladen werden'], 500);
        }

        return new StreamedResponse(function () use ($games, $platform, $playerCount, $gemini) {
            // Disable nginx buffering — critical for SSE streaming in DDEV/nginx setups.
            header('X-Accel-Buffering: no');

            try {
                foreach ($gemini->streamRecommendations($games, $platform, $playerCount) as $chunk) {
                    echo 'data: ' . json_encode(['text' => $chunk]) . "\n\n";

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }

                echo 'data: ' . json_encode(['done' => true]) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            } catch (\Exception $e) {
                echo 'data: ' . json_encode(['error' => 'Gemini API ist nicht erreichbar. Bitte versuche es später erneut.']) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
        ]);
    }
}
