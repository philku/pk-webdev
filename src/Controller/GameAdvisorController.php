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
    // ---------- Hauptseite ----------
    // Lädt die Vorauswahl-Games aus IGDB (gecacht) und rendert die Seite.
    // Die ganze Interaktion (Plattform-Wahl, Game-Auswahl, KI-Empfehlung)
    // passiert clientseitig im Stimulus Controller.
    #[Route('', name: 'app_game_advisor')]
    public function index(IgdbService $igdb): Response
    {
        // Vorauswahl-Games laden — beim ersten Aufruf 1 API-Call,
        // danach 30 Tage aus dem Cache.
        try {
            $popularGames = $igdb->getPopularGames();
        } catch (\Exception) {
            $popularGames = [];
        }

        return $this->render('game_advisor/index.html.twig', [
            'popularGames' => $popularGames,
        ]);
    }

    // ---------- Game-Suche (Autocomplete) ----------
    // JSON-Endpoint für das Suchfeld im Frontend.
    // Minimum 2 Zeichen, sonst leeres Array (spart API-Calls).
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

    // ---------- KI-Empfehlung (SSE Stream) ----------
    // Liest die ausgewählten Game-IDs + Plattform aus dem Request,
    // holt die Game-Details aus IGDB (gecacht), und streamt die
    // Gemini-Antwort als Server-Sent Events zum Browser.
    //
    // SSE-Format: Jede Nachricht ist eine Zeile "data: {...}\n\n"
    // Der Browser liest das mit fetch + ReadableStream.
    #[Route('/api/recommend', name: 'app_game_advisor_recommend', methods: ['POST'])]
    public function recommend(Request $request, IgdbService $igdb, GeminiService $gemini): Response
    {
        // JSON-Body lesen (Stimulus schickt Content-Type: application/json)
        $data = json_decode($request->getContent(), true);
        $gameIds = $data['gameIds'] ?? [];
        $platform = $data['platform'] ?? 'PC';
        $playerCount = $data['playerCount'] ?? 2;

        if (empty($gameIds)) {
            return $this->json(['error' => 'Keine Spiele ausgewählt'], 400);
        }

        // Game-Details aus IGDB laden (jedes einzeln gecacht)
        $games = $igdb->getGamesById($gameIds);

        if (empty($games)) {
            return $this->json(['error' => 'Spiele konnten nicht geladen werden'], 500);
        }

        // StreamedResponse: Symfony schickt die Response nicht auf einmal,
        // sondern ruft die Callback-Funktion auf, die stückweise Daten schreibt.
        // Solange der Callback läuft, bleibt die HTTP-Verbindung offen.
        return new StreamedResponse(function () use ($games, $platform, $playerCount, $gemini) {
            // X-Accel-Buffering: no — KRITISCH für DDEV/nginx.
            // Ohne diesen Header puffert nginx die gesamte Response
            // und der Client sieht nichts bis Ollama komplett fertig ist.
            // Das würde den ganzen Streaming-Effekt zunichtemachen.
            header('X-Accel-Buffering: no');

            try {
                // streamRecommendations() ist ein Generator:
                // Jeder yield gibt einen Text-Chunk (ein paar Wörter) zurück.
                foreach ($gemini->streamRecommendations($games, $platform, $playerCount) as $chunk) {
                    // SSE-Format: "data: {json}\n\n"
                    // Doppeltes \n ist der SSE-Standard — trennt Events voneinander.
                    echo 'data: ' . json_encode(['text' => $chunk]) . "\n\n";

                    // ob_flush() + flush() erzwingen das sofortige Senden.
                    // Ohne flush würde PHP die Daten in einem internen Puffer sammeln
                    // und erst am Ende der Response alles auf einmal senden.
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }

                // Done-Event: signalisiert dem Client dass der Stream fertig ist.
                echo 'data: ' . json_encode(['done' => true]) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            } catch (\Exception $e) {
                // Fehler als SSE-Event senden statt die Verbindung einfach abzubrechen.
                // So kann der Client eine Fehlermeldung anzeigen.
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
