<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Service für die Ollama API (lokales LLM).
// Ollama läuft als HTTP-Server auf dem Host und bietet eine Chat-API an.
// Wir nutzen Streaming: statt auf die komplette Antwort zu warten,
// lesen wir den Response Chunk für Chunk und yielden die Textteile.
// So kann der Controller die Antwort als Server-Sent Events weiterleiten.
class OllamaService
{
    public function __construct(
        private HttpClientInterface $httpClient,

        // URL zum Ollama-Server. In DDEV: http://host.docker.internal:11434
        // (Docker Desktop löst das zum Host-Rechner auf).
        // In Produktion: http://localhost:11434
        #[Autowire(env: 'OLLAMA_URL')]
        private string $ollamaUrl,

        // Model-Name, konfigurierbar. Default: mistral (7B, schnell, gutes Deutsch).
        #[Autowire(env: 'OLLAMA_MODEL')]
        private string $model,
    ) {
    }

    // --- Streaming-Empfehlung ---
    // Generator-Funktion: yielded Text-Chunks sobald sie von Ollama kommen.
    // Der Controller liest diese Chunks und schickt sie als SSE zum Browser.
    //
    // Warum Generator statt array? Weil Ollama 5-30 Sekunden braucht.
    // Mit einem Generator kann der Controller jeden Chunk sofort weiterleiten,
    // statt auf die komplette Antwort zu warten. Der User sieht Text
    // Wort für Wort erscheinen — fühlt sich schnell und lebendig an.
    //
    // @param array $games Array von Game-Arrays (aus IgdbService::transformGame)
    // @param string $platform Gewählte Plattform (z.B. "PS5")
    // @return \Generator<string> Text-Chunks
    public function streamRecommendations(array $games, string $platform): \Generator
    {
        $prompt = $this->buildPrompt($games, $platform);

        // Ollama Chat API: POST /api/chat mit Messages-Array (wie OpenAI-Format).
        // stream: true = Ollama schickt pro Token eine JSON-Zeile.
        $response = $this->httpClient->request('POST', $this->ollamaUrl . '/api/chat', [
            'json' => [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $this->getSystemPrompt()],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'stream' => true,
            ],
            // Timeout hoch setzen — LLM-Antworten dauern.
            // Ohne das würde Symfony nach 4 Sekunden abbrechen.
            'timeout' => 120,
        ]);

        // Streaming: Den Response als Stream lesen.
        // Ollama schickt pro Token eine JSON-Zeile im Format:
        // {"message":{"content":"Hallo"},"done":false}
        // {"message":{"content":" Welt"},"done":false}
        // {"message":{"content":""},"done":true}
        //
        // toStream() gibt einen PHP-Stream zurück (wie fopen).
        // Wir lesen Zeile für Zeile und yielden den content-Teil.
        $stream = $response->toStream();

        // Puffer für unvollständige Zeilen. TCP kann Daten mitten in
        // einer JSON-Zeile splitten — der Puffer sammelt Fragmente
        // bis eine komplette Zeile (mit \n) da ist.
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
                if ($line === '') {
                    continue;
                }

                $data = json_decode($line, true);
                if ($data === null) {
                    continue;
                }

                // Text-Chunk yielden (wenn vorhanden)
                $content = $data['message']['content'] ?? '';
                if ($content !== '') {
                    yield $content;
                }

                // done: true = Ollama ist fertig
                if ($data['done'] ?? false) {
                    return;
                }
            }
        }
    }

    // --- System-Prompt: Definiert die Rolle und das Format ---
    // Getrennt vom User-Prompt, damit das LLM die Instruktionen
    // klar von den Game-Daten unterscheiden kann.
    private function getSystemPrompt(): string
    {
        // System-Prompt auf Englisch: Modelle verstehen englische Instruktionen
        // besser und produzieren dann saubereres Deutsch als Output.
        return <<<PROMPT
You are a gaming expert specialized in cooperative multiplayer games.
You MUST write your entire response in German (Deutsch). Use correct German grammar, spelling and natural phrasing.

Rules:
- Recommend exactly 3 to 5 games that have a coop mode (online or local)
- Each game gets its own heading with the game name
- Write 2-3 sentences explaining why the game matches the user's preferences
- Mention the available platforms for each game
- Do NOT recommend games the user already played
- Only recommend games available on the user's platform
- Be concise, no smalltalk or filler
PROMPT;
    }

    // --- User-Prompt: Enthält die konkreten Game-Daten ---
    // Das LLM bekommt Name + Genres jedes Games, damit es Muster
    // erkennen kann (z.B. "der User mag Shooter + Horror → Empfehle Left 4 Dead").
    private function buildPrompt(array $games, string $platform): string
    {
        $gameList = [];

        foreach ($games as $game) {
            $genres = implode(', ', $game['genres'] ?? []);
            $gameList[] = sprintf('- %s (%s)', $game['name'], $genres ?: 'keine Genre-Info');
        }

        $gamesText = implode("\n", $gameList);

        return <<<PROMPT
Ich spiele auf: {$platform}

Diese Spiele habe ich gespielt und mochte sie:
{$gamesText}

Was sollten wir als nächstes zusammen zocken?
PROMPT;
    }
}
