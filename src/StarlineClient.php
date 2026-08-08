<?php namespace StarlineApi;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use StarlineApi\Exceptions\StarlineApiException;
use StarlineApi\Exceptions\StarlineAuthException;
use StarlineApi\Exceptions\StarlineException;
use StarlineApi\Storage\CacheTokenStorage;

/**
 * StarLine OpenAPI client.
 *
 * Implements the SLID auth flow:
 *   1. GET  {id}/apiV3/application/getCode   secret = md5(appSecret)
 *   2. GET  {id}/apiV3/application/getToken  secret = md5(appSecret + code)
 *   3. POST {id}/apiV3/user/login            pass = sha1(password), Bearer appToken
 *   4. POST {api}/json/v2/auth.slid          form: slid + appId -> cookie "slnet"
 *
 * All data requests are authenticated with the "slnet" cookie.
 *
 * @author Alexander Tischenko <http://alex-tisch.ru>
 * @see https://developer.starline.ru/
 */
class StarlineClient
{
    private const ID_HOST_KEYS = ['app_id', 'app_secret', 'login', 'password', 'id_url', 'api_url'];

    private readonly Client $http;

    /**
     * @param array<string, mixed> $config Merged "starline" config.
     */
    public function __construct(
        private readonly array $config,
        private readonly CacheTokenStorage $storage,
        ?Client $http = null,
    ) {
        foreach (self::ID_HOST_KEYS as $key) {
            if (empty($this->config[$key])) {
                throw new StarlineException("Starline config key [{$key}] is missing.");
            }
        }

        $this->http = $http ?? new Client([
            'timeout' => (int) ($config['timeout'] ?? 30),
            'http_errors' => false,
            'headers' => ['User-Agent' => 'starline-api-laravel/1.0'],
        ]);
    }

    /*
    |--------------------------------------------------------------------
    | Public API
    |--------------------------------------------------------------------
    */

    /** Run the full SLID chain (or reuse cached tokens). Returns slnet. */
    public function authenticate(bool $force = false): string
    {
        if (! $force && $slnet = $this->storage->get('slnet')) {
            return $slnet;
        }

        $appToken = $this->appToken($force);
        $slid = $this->slidToken($appToken, $force);
        $slnet = $this->exchangeSlid($slid);

        $this->storage->set('slnet', $slnet);

        return $slnet;
    }

    /** Current user id (auto-detected during login, cached). */
    public function userId(): int
    {
        foreach ([$this->config['user_id'] ?? null, $this->storage->get('user_id')] as $id) {
            if ($id !== null && ctype_digit((string) $id)) {
                return (int) $id;
            }
        }

        $this->authenticate();

        if ($id = $this->storage->get('user_id')) {
            return (int) $id;
        }

        // Fallback: the SLID token has the "<hash>:<user_id>" format.
        $slid = (string) $this->storage->get('slid_token');

        if (str_contains($slid, ':') && ctype_digit($suffix = substr($slid, strrpos($slid, ':') + 1))) {
            $this->storage->set('user_id', $suffix);

            return (int) $suffix;
        }

        throw new StarlineAuthException('Unable to detect user_id. Set STARLINE_USER_ID.');
    }

    /** User profile + device list. GET /json/v1/user/{id}/user_info */
    public function userInfo(): array
    {
        return $this->get(sprintf('/json/v1/user/%d/user_info', $this->userId()));
    }

    /** Live device state. GET /json/v3/device/{id}/data */
    public function deviceData(int|string $deviceId): array
    {
        return $this->get(sprintf('/json/v3/device/%s/data', $deviceId));
    }

    /** Send a command. POST /json/v1/device/{id}/set_param */
    public function setParam(int|string $deviceId, array $params): array
    {
        return $this->post(sprintf('/json/v1/device/%s/set_param', $deviceId), $params);
    }

    public function arm(int|string $deviceId): array
    {
        return $this->setParam($deviceId, ['security' => ['arm' => true]]);
    }

    public function disarm(int|string $deviceId): array
    {
        return $this->setParam($deviceId, ['security' => ['arm' => false]]);
    }

    public function startEngine(int|string $deviceId): array
    {
        return $this->setParam($deviceId, ['engine' => ['start' => true]]);
    }

    public function stopEngine(int|string $deviceId): array
    {
        return $this->setParam($deviceId, ['engine' => ['stop' => true]]);
    }

    /** Raw GET against the API host (auto re-auth on 401). */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    /** Raw POST against the API host (auto re-auth on 401). */
    public function post(string $path, array $json = []): array
    {
        return $this->request('POST', $path, ['json' => $json]);
    }

    /*
    |--------------------------------------------------------------------
    | Auth chain
    |--------------------------------------------------------------------
    */

    /** Steps 1-2: one-time app code, then the application token. */
    private function appToken(bool $force): string
    {
        if (! $force && $token = $this->storage->get('app_token')) {
            return $token;
        }

        $secret = (string) $this->config['app_secret'];

        $code = $this->idGet('/apiV3/application/getCode', [
            'appId' => $this->config['app_id'],
            'secret' => md5($secret),
        ])['code'];

        $token = $this->idGet('/apiV3/application/getToken', [
            'appId' => $this->config['app_id'],
            'secret' => md5($secret.$code),
        ])['token'];

        $this->storage->set('app_token', $token);

        return $token;
    }

    /** Step 3: user login; password is SHA-1 hashed client-side. */
    private function slidToken(string $appToken, bool $force): string
    {
        if (! $force && $token = $this->storage->get('slid_token')) {
            return $token;
        }

        $desc = $this->idRequest('POST', '/apiV3/user/login', [
            'headers' => ['Authorization' => 'Bearer '.$appToken],
            'form_params' => [
                'login' => $this->config['login'],
                'pass' => sha1((string) $this->config['password']),
            ],
        ]);

        $userId = $desc['user_id'] ?? $desc['userId'] ?? null;

        if ($userId !== null) {
            $this->storage->set('user_id', (string) $userId);
        }

        $this->storage->set('slid_token', $token = (string) $desc['user_token']);

        return $token;
    }

    /** Step 4: exchange the SLID token for the "slnet" session cookie. */
    private function exchangeSlid(string $slid): string
    {
        $jar = new CookieJar();

        $response = $this->http->post($this->config['api_url'].'/json/v2/auth.slid', [
            'cookies' => $jar,
            'form_params' => [
                'slid' => $slid,
                'appId' => $this->config['app_id'],
            ],
        ]);

        foreach ($jar->toArray() as $cookie) {
            if ($cookie['Name'] === 'slnet' && $cookie['Value'] !== '') {
                return (string) $cookie['Value'];
            }
        }

        throw new StarlineAuthException(
            'auth.slid did not return the "slnet" cookie (HTTP '.$response->getStatusCode().').',
        );
    }

    /*
    |--------------------------------------------------------------------
    | HTTP layer
    |--------------------------------------------------------------------
    */

    /**
     * Authenticated request with a single automatic re-auth retry on 401.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed> The "desc" part of the response.
     */
    private function request(string $method, string $path, array $options, bool $retry = true): array
    {
        $response = $this->send(
            $method,
            $this->config['api_url'].'/'.ltrim($path, '/'),
            $options + ['headers' => ['Cookie' => 'slnet='.$this->authenticate()]],
        );

        if ($response->getStatusCode() === 401 && $retry) {
            $this->storage->flush();

            return $this->request($method, $path, $options, false);
        }

        return $this->unwrap($response);
    }

    /** GET against id.starline.ru; returns the "desc" envelope. */
    private function idGet(string $path, array $query): array
    {
        return $this->idRequest('GET', $path, ['query' => $query]);
    }

    /**
     * Request against id.starline.ru with the {"state":1,"desc":{...}} envelope.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function idRequest(string $method, string $path, array $options): array
    {
        $response = $this->send($method, $this->config['id_url'].$path, $options);
        $data = $this->decode($response);

        if (($data['state'] ?? null) !== 1) {
            throw new StarlineAuthException(sprintf(
                '%s failed: %s',
                $path,
                $data['desc']['message'] ?? 'unknown error (HTTP '.$response->getStatusCode().')',
            ));
        }

        return $data['desc'] ?? [];
    }

    /**
     * Unwrap the developer.starline.ru envelope: {"code":200,"codestring":"OK",...}.
     *
     * @return array<string, mixed>
     */
    private function unwrap(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();

        if ($status === 401 || $status === 403) {
            throw new StarlineAuthException('Unauthorized (HTTP '.$status.').');
        }

        $data = $this->decode($response);
        $code = isset($data['code']) ? (int) $data['code'] : $status;

        if ($status >= 400 || $code >= 400) {
            throw new StarlineApiException(
                sprintf('[%d] %s', $code, $data['codestring'] ?? 'API error'),
                $code,
                $data,
            );
        }

        return $data['desc'] ?? $data;
    }

    private function send(string $method, string $url, array $options): ResponseInterface
    {
        try {
            return $this->http->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new StarlineApiException('HTTP request failed: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new StarlineApiException('Invalid JSON response: '.mb_substr($body, 0, 200));
        }

        return $data;
    }
}