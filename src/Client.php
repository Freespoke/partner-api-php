<?php

declare(strict_types=1);

namespace Freespoke\Partner;

use GuzzleHttp\Client as Guzzle;

/**
 * Default API base URL.
 */
const DEFAULT_BASE_URL = 'https://api.partners.freespoke.com';

/**
 * Client for the Freespoke Partner API REST gateway.
 */
class Client
{
    /** @var Guzzle */
    private Guzzle $client;

    /** @var string */
    private readonly string $apiKey;

    /**
     * @param Guzzle $httpClient Pre-configured HTTP client instance.
     * @param string $apiKey Bearer token value (without the "Bearer " prefix).
     */
    public function __construct(Guzzle $httpClient, string $apiKey)
    {
        $this->client = $httpClient;
        $this->apiKey = $apiKey;
    }

    /**
     * Build a client with default Guzzle configuration.
     *
     * @param string $apiKey Bearer token value (without the "Bearer " prefix).
     * @param string $baseURL Base URL for the Partner API.
     * @return Client
     */
    public static function create(string $apiKey, string $baseURL = DEFAULT_BASE_URL): Client
    {
        $httpClient = new Guzzle([
            'base_uri' => rtrim($baseURL, '/') . '/',
        ]);

        return new Client($httpClient, $apiKey);
    }

    /**
     * Fetch the current Partner API epoch for re-indexing checks.
     *
     * @return int
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \JsonException
     */
    public function getEpoch(): int
    {
        $data = $this->requestJson('GET', 'v1/content/epoch');

        $epoch = $data['epoch'] ?? null;
        if (is_int($epoch)) {
            return $epoch;
        }
        if (is_string($epoch) && is_numeric($epoch)) {
            return (int) $epoch;
        }

        return 0;
    }

    /**
     * Submit an article to the Partner API index.
     *
     * @param Article $article
     * @return IndexResult|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \JsonException
     */
    public function index(Article $article): ?IndexResult
    {
        $payload = $this->buildIndexPayload($article);

        $data = $this->requestJson('POST', 'v1/content', [
            'json' => $payload,
        ]);

        $errorMessage = $data['errorMessage'] ?? $data['error_message'] ?? null;
        if (is_string($errorMessage) && $errorMessage !== '') {
            return null;
        }

        $jobId = $data['jobId'] ?? $data['job_id'] ?? null;
        $workflowId = $data['workflowId'] ?? $data['workflow_id'] ?? null;
        if (!is_string($jobId) || $jobId === '' || !is_string($workflowId) || $workflowId === '') {
            return null;
        }

        $result = new IndexResult();
        $result->job_id = $jobId;
        $result->workflow_id = $workflowId;

        return $result;
    }

    /**
     * Fetch status for a previously submitted indexing job.
     *
     * @param string $job_id
     * @return IndexStatusResult|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \JsonException
     */
    public function getIndexStatus(string $job_id): ?IndexStatusResult
    {
        $data = $this->requestJson('GET', 'v1/job/' . rawurlencode($job_id));

        $job = $data['job'] ?? null;
        if (!is_array($job)) {
            return null;
        }

        $result = new IndexStatusResult();
        $result->job_id = (string) ($job['jobId'] ?? $job['job_id'] ?? $job_id);
        $result->status = (string) ($job['status'] ?? '');
        $result->error = $this->normalizeObject($job['error'] ?? []);
        $result->metadata = $this->normalizeObject($job['metadata'] ?? []);
        $result->result = $this->normalizeObject($job['result'] ?? []);
        $result->create_time = $this->parseTimestamp($job['createTime'] ?? $job['create_time'] ?? null);
        $result->update_time = $this->parseTimestamp($job['updateTime'] ?? $job['update_time'] ?? null);

        return $result;
    }

    private function requestJson(string $method, string $uri, array $options = []): array {
        $options['headers'] = array_merge(
            $options['headers'] ?? [],
            [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
            ]
        );

        $response = $this->client->request($method, $uri, $options);
        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    private function buildIndexPayload(Article $article): array {
        $payload = [
            'url' => $article->url ?? '',
            'title' => $article->title ?? '',
            'description' => $article->description ?? '',
            'content' => $article->content ?? '',
            'authors' => $this->formatAuthors($article->getAuthors()),
            'keywords' => $article->keywords ?? [],
        ];

        $publishTime = $article->publish_time ?? null;
        if ($publishTime instanceof \DateTimeInterface) {
            $payload['publish_time'] = $this->formatTimestamp($publishTime);
        }

        $imageUrl = $article->image_url ?? '';
        if (is_string($imageUrl) && $imageUrl !== '') {
            $payload['image_url'] = $imageUrl;
        }

        return $payload;
    }

    private function formatAuthors(array $authors): array {
        $formatted = [];

        foreach ($authors as $author) {
            if (!$author instanceof Person) {
                continue;
            }

            $entry = [];

            $id = $author->id ?? '';
            if (is_string($id) && $id !== '') {
                $entry['id'] = $id;
            }

            $name = $author->name ?? '';
            if (is_string($name) && $name !== '') {
                $entry['name'] = $name;
            }

            $url = $author->url ?? '';
            if (is_string($url) && $url !== '') {
                $entry['url'] = $url;
            }

            if (isset($author->bias)) {
                $entry['bias'] = (float) $author->bias;
            }

            $twitterId = $author->twitter_id ?? '';
            if (is_string($twitterId) && $twitterId !== '') {
                $entry['twitter_id'] = $twitterId;
            }

            $facebookId = $author->facebook_id ?? '';
            if (is_string($facebookId) && $facebookId !== '') {
                $entry['facebook_id'] = $facebookId;
            }

            if ($entry !== []) {
                $formatted[] = $entry;
            }
        }

        return $formatted;
    }

    private function formatTimestamp(\DateTimeInterface $time): string {
        return $time->format(DATE_RFC3339);
    }

    private function normalizeObject(mixed $value): array {
        return is_array($value) ? $value : [];
    }

    private function parseTimestamp(mixed $value): ?\DateTimeImmutable {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }
    }
}
