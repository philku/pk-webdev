<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Service für die Google Gemini API (Cloud LLM).
// Ersetzt den OllamaService — statt eines lokalen Modells nutzen wir
// Gemini 3.1 Flash Lite über Googles REST API.
//
// Authentifizierung: API-Key als Query-Parameter (kein OAuth nötig).
// Streaming: ?alt=sse aktiviert Server-Sent Events — selbes Prinzip wie bei Ollama,
// aber anderes Response-Format.
class GeminiService
{
    // Basis-URL der Gemini REST API.
    // Der Modellname wird dynamisch eingefügt.
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(
        private HttpClientInterface $httpClient,

        // API-Key aus Google AI Studio (https://aistudio.google.com/apikey).
        // Kein OAuth, kein Token-Refresh — einfach Key als Query-Parameter.
        #[Autowire(env: 'GEMINI_API_KEY')]
        private string $apiKey,

        // Modellname, konfigurierbar über .env.
        // Default: gemini-3.1-flash-lite-preview (schnell, günstig, gutes Deutsch).
        #[Autowire(env: 'GEMINI_MODEL')]
        private string $model,
    ) {
    }

    // --- Streaming-Empfehlung ---
    // Generator-Funktion: yielded Text-Chunks sobald sie von Gemini kommen.
    // Gleiche Schnittstelle wie OllamaService — der Controller muss nicht
    // wissen, welches LLM dahinter steckt.
    //
    // @param array $games Array von Game-Arrays (aus IgdbService::transformGame)
    // @param string $platform Gewählte Plattform (z.B. "PS5")
    // @return \Generator<string> Text-Chunks
    public function streamRecommendations(array $games, string $platform, int $playerCount = 2): \Generator
    {
        $prompt = $this->buildPrompt($games, $platform, $playerCount);

        // Gemini REST API: POST mit ?alt=sse für Server-Sent Events.
        // Der API-Key kommt als Query-Parameter — simpler als OAuth.
        $url = self::API_URL . $this->model . ':streamGenerateContent?alt=sse&key=' . $this->apiKey;

        $response = $this->httpClient->request('POST', $url, [
            'json' => [
                // System-Prompt: getrennt von den User-Inhalten.
                // Gemini nutzt ein eigenes Format (nicht "messages" wie OpenAI/Ollama).
                'system_instruction' => [
                    'parts' => [
                        ['text' => $this->getSystemPrompt()],
                    ],
                ],
                // User-Nachricht: die eigentlichen Game-Daten + Plattform.
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
            ],
            // Timeout hoch setzen — LLM-Antworten dauern.
            'timeout' => 120,
        ]);

        // Streaming: Den Response als Stream lesen.
        // Gemini SSE schickt Events im Format:
        //   data: {"candidates":[{"content":{"parts":[{"text":"Hallo"}]}}]}
        //
        // Jedes "data: " Präfix enthält ein JSON-Objekt.
        // Der Text steckt in candidates[0].content.parts[0].text
        $stream = $response->toStream();

        // Puffer für unvollständige Zeilen (TCP kann mitten in einer Zeile splitten)
        $buffer = '';

        while (!feof($stream)) {
            $chunk = fread($stream, 8192);

            if ($chunk === false || $chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            // Puffer Zeile für Zeile verarbeiten
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newlinePos);
                $buffer = substr($buffer, $newlinePos + 1);

                $line = trim($line);

                // SSE-Zeilen die mit "data: " beginnen enthalten die JSON-Daten.
                // Andere Zeilen (leere Zeilen, "event:" etc.) überspringen.
                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }

                // "data: " Präfix entfernen → reines JSON
                $json = substr($line, 6);

                $data = json_decode($json, true);
                if ($data === null) {
                    continue;
                }

                // Text aus der Gemini-Antwortstruktur extrahieren:
                // candidates → erstes Element → content → parts → erstes Element → text
                $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if ($text !== '') {
                    yield $text;
                }
            }
        }
    }

    // --- System-Prompt: Definiert die Rolle und das Format ---
    // Auf Englisch, weil LLMs englische Instruktionen besser verstehen
    // und dann saubereres Deutsch produzieren.
    private function getSystemPrompt(): string
    {
        return <<<PROMPT
You are a gaming expert specialized in cooperative multiplayer games.
You MUST write your entire response in German (Deutsch). Use correct German grammar, spelling and natural phrasing.
Write interesting responses and not just generic recommmendations.

Rules:
- Recommend exactly 3 to 5 games that support coop for the specified number of players
- Each game gets its own heading with the game name
- Write 2-3 sentences explaining why the game matches the user's preferences
- Do NOT recommend games the user already played
- Only recommend games available on the user's platform
- Be concise, no smalltalk or filler
PROMPT;
    }

    // --- User-Prompt: Enthält die konkreten Game-Daten ---
    // Das LLM bekommt Name + Genres jedes Games, damit es Muster
    // erkennen kann (z.B. "der User mag Shooter + Horror → Empfehle Left 4 Dead").
    private function buildPrompt(array $games, string $platform, int $playerCount): string
    {
        $gameList = [];

        foreach ($games as $game) {
            $genres = implode(', ', $game['genres'] ?? []);
            $gameList[] = sprintf('- %s (%s)', $game['name'], $genres ?: 'keine Genre-Info');
        }

        $gamesText = implode("\n", $gameList);

        return <<<PROMPT
Ich spiele auf: {$platform}
Wir sind {$playerCount} Spieler.

Diese Spiele habe ich gespielt und mochte sie:
{$gamesText}

Was sollten wir als nächstes zusammen zocken?
PROMPT;
    }
}
