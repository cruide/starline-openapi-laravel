<?php namespace StarlineApi;

use Cruide\StarlineApi\Api\DeviceApi;
use Cruide\StarlineApi\Api\UserApi;
use Cruide\StarlineApi\Auth\Authenticator;
use Cruide\StarlineApi\Auth\OcrInterface;
use Cruide\StarlineApi\StarlineApi as BaseStarlineApi;
use GuzzleHttp\Client;
use StarlineApi\Exceptions\StarlineException;
use StarlineApi\Http\GuzzleHttpClient;
use StarlineApi\Storage\CacheTokenStorage;

class StarlineClient
{
    private BaseStarlineApi $api;

    private const REQUIRED_KEYS = ['app_id', 'app_secret', 'login', 'password'];

    public function __construct(array $config, CacheTokenStorage $storage, ?Client $httpClient = null)
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (empty($config[$key])) {
                throw new StarlineException("Starline config key [{$key}] is missing.");
            }
        }

        $http = new GuzzleHttpClient((int) ($config['timeout'] ?? 30), $httpClient);

        $this->api = new BaseStarlineApi(
            $config['app_id'],
            $config['app_secret'],
            $config['login'],
            $config['password'],
            $http,
            $storage
        );

        if (! empty($config['user_id'])) {
            $this->api->setUserId((int) $config['user_id']);
        }
    }

    public function user(): UserApi
    {
        return $this->api->user();
    }

    public function devices(): DeviceApi
    {
        return $this->api->devices();
    }

    public function authenticator(): Authenticator
    {
        return $this->api->authenticator();
    }

    public function authenticate(bool $force = false): void
    {
        $this->api->authenticate($force);
    }

    public function authenticateWithSlidToken(string $slidToken): void
    {
        $this->api->authenticateWithSlidToken($slidToken);
    }

    public function authenticateWithCaptcha(string $captchaSid, string $captchaCode): void
    {
        $this->api->authenticateWithCaptcha($captchaSid, $captchaCode);
    }

    public function authenticateWithSms(string $smsCode): void
    {
        $this->api->authenticateWithSms($smsCode);
    }

    public function authenticateWithCaptchaAuto(OcrInterface $ocr): void
    {
        $this->api->authenticateWithCaptchaAuto($ocr);
    }

    public function setUserId(int $userId): void
    {
        $this->api->setUserId($userId);
    }

    public function setOcr(OcrInterface $ocr): void
    {
        $this->api->setOcr($ocr);
    }

    public function get(string $path, array $query = []): array
    {
        return $this->api->get($path, $query);
    }

    public function post(string $path, array $json = []): array
    {
        return $this->api->post($path, $json);
    }

    public function request(string $method, string $path, array $query = [], ?array $json = null): array
    {
        return $this->api->request($method, $path, $query, $json);
    }

    public function api(): BaseStarlineApi
    {
        return $this->api;
    }
}
