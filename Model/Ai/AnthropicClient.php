<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Ai;

use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * Thin client for the Anthropic Messages API (POST /v1/messages), over Magento's built-in
 * HTTP client so the module needs no extra Composer dependency to run on any store.
 *
 * Uses structured outputs (output_config.format) so the model returns schema-valid JSON that
 * parses without heuristics. Never streams — callers are short, bounded admin requests.
 */
class AnthropicClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly CurlFactory $curlFactory
    ) {
    }

    /**
     * Send one user prompt and return the model's structured JSON output, decoded to an array.
     *
     * @param array<string, mixed> $schema JSON schema the response must conform to
     * @return array<string, mixed>
     * @throws \RuntimeException on transport, API, refusal, or parse failure
     */
    public function createStructured(
        string $apiKey,
        string $model,
        string $prompt,
        array $schema,
        int $maxTokens = 8000,
        int $timeout = 120
    ): array {
        if ($apiKey === '') {
            throw new \RuntimeException('No Claude API key is configured.');
        }

        $body = json_encode([
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $curl = $this->curlFactory->create();
        $curl->setOption(CURLOPT_TIMEOUT, $timeout);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, 15);
        $curl->addHeader('content-type', 'application/json');
        $curl->addHeader('x-api-key', $apiKey);
        $curl->addHeader('anthropic-version', self::API_VERSION);

        try {
            $curl->post(self::ENDPOINT, $body);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not reach the Claude API: ' . $e->getMessage());
        }

        $status = (int) $curl->getStatus();
        $raw = (string) $curl->getBody();
        $decoded = json_decode($raw, true);

        if ($status !== 200 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? '') : '';
            if ($status === 401) {
                $message = 'the API key was rejected (401)';
            }
            throw new \RuntimeException(
                'Claude API request failed (HTTP ' . $status . ')' . ($message ? ': ' . $message : '')
            );
        }

        if (($decoded['stop_reason'] ?? '') === 'refusal') {
            throw new \RuntimeException('The request was declined by the model.');
        }

        $text = '';
        foreach ((array) ($decoded['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text = (string) ($block['text'] ?? '');
                break;
            }
        }
        if ($text === '') {
            throw new \RuntimeException('The model returned an empty response.');
        }

        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            throw new \RuntimeException('The model response was not valid JSON.');
        }
        return $parsed;
    }
}
