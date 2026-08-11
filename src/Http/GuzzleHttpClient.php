<?php namespace StarlineApi\Http;

use Cruide\StarlineApi\Exceptions\StarlineHttpException;
use Cruide\StarlineApi\Http\HttpClientInterface;
use Cruide\StarlineApi\Http\Response;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class GuzzleHttpClient implements HttpClientInterface
{
    private Client $http;

    public function __construct(int $timeout = 30, ?Client $client = null)
    {
        $this->http = $client ?? new Client([
            'timeout' => $timeout,
            'http_errors' => false,
            'headers' => ['User-Agent' => 'starline-api-laravel/1.0'],
        ]);
    }

    public function get(string $url, array $query = [], array $headers = []): Response
    {
        return $this->request('GET', $url, [
            'headers' => $headers,
            'query' => $query,
        ]);
    }

    public function postForm(string $url, array $data = [], array $headers = []): Response
    {
        return $this->request('POST', $url, [
            'headers' => $headers,
            'form_params' => $data,
        ]);
    }

    public function postJson(string $url, array $data = [], array $headers = []): Response
    {
        return $this->request('POST', $url, [
            'headers' => $headers,
            'json' => $data,
        ]);
    }

    private function request(string $method, string $url, array $options): Response
    {
        try {
            $guzzleResponse = $this->http->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new StarlineHttpException($e->getMessage(), 0, $e);
        }

        $parsedHeaders = [];

        foreach ($guzzleResponse->getHeaders() as $name => $values) {
            $parsedHeaders[strtolower($name)] = $values;
        }

        return new Response(
            $guzzleResponse->getStatusCode(),
            (string) $guzzleResponse->getBody(),
            $parsedHeaders
        );
    }
}
