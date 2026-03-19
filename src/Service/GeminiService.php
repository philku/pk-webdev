<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Google Gemini API service. Streams LLM responses as SSE via REST API.
// Auth: API key as query parameter (no OAuth required).
class GeminiService
{
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'GEMINI_API_KEY')]
        private string $apiKey,
        #[Autowire(env: 'GEMINI_MODEL')]
        private string $model,
    ) {
    }

    // Generator that yields text chunks as they arrive from Gemini.
    // Same interface as OllamaService — controller is LLM-agnostic.
    public function streamRecommendations(array $games, string $platform, int $playerCount = 2): \Generator
    {
        $prompt = $this->buildPrompt($games, $platform, $playerCount);
        $url = self::API_URL . $this->model . ':streamGenerateContent?alt=sse&key=' . $this->apiKey;

        $response = $this->httpClient->request('POST', $url, [
            'json' => [
                'system_instruction' => [
                    'parts' => [
                        ['text' => $this->getSystemPrompt()],
                    ],
                ],
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
            ],
            'timeout' => 120,
        ]);

        $stream = $response->toStream();
        // Buffer for incomplete lines (TCP can split mid-line).
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

                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }

                $json = substr($line, 6);
                $data = json_decode($json, true);
                if ($data === null) {
                    continue;
                }

                $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if ($text !== '') {
                    yield $text;
                }
            }
        }
    }

    // System prompt in English — LLMs follow English instructions more reliably
    // while still producing clean German output.
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

    // Builds the user prompt with game names + genres so the LLM can detect taste patterns.
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
