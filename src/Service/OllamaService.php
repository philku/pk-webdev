<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Local LLM service via Ollama's HTTP API.
// Streams responses chunk by chunk as a Generator for SSE forwarding.
class OllamaService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'OLLAMA_URL')]
        private string $ollamaUrl,
        #[Autowire(env: 'OLLAMA_MODEL')]
        private string $model,
    ) {
    }

    // Generator that yields text chunks as they arrive from Ollama.
    // Enables real-time streaming — user sees text appear word by word.
    public function streamRecommendations(array $games, string $platform): \Generator
    {
        $prompt = $this->buildPrompt($games, $platform);

        $response = $this->httpClient->request('POST', $this->ollamaUrl . '/api/chat', [
            'json' => [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $this->getSystemPrompt()],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'stream' => true,
            ],
            'timeout' => 120,
        ]);

        // Ollama streams one JSON line per token:
        // {"message":{"content":"Hello"},"done":false}
        $stream = $response->toStream();
        $buffer = '';

        while (!feof($stream)) {
            $chunk = fread($stream, 8192);

            if ($chunk === false || $chunk === '') {
                continue;
            }

            $buffer .= $chunk;

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

                $content = $data['message']['content'] ?? '';
                if ($content !== '') {
                    yield $content;
                }

                if ($data['done'] ?? false) {
                    return;
                }
            }
        }
    }

    private function getSystemPrompt(): string
    {
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
