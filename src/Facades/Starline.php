<?php namespace Cruide\StarlineLaravel\Facades;

use Cruide\StarlineLaravel\Client;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Cruide\StarlineApi\Api\UserApi user()
 * @method static \Cruide\StarlineApi\Api\DeviceApi devices()
 * @method static \Cruide\StarlineApi\Auth\Authenticator authenticator()
 * @method static void authenticate(bool $force = false)
 * @method static void authenticateWithSlidToken(string $slidToken)
 * @method static void authenticateWithCaptcha(string $captchaSid, string $captchaCode)
 * @method static void authenticateWithSms(string $smsCode)
 * @method static void authenticateWithCaptchaAuto(\Cruide\StarlineApi\Auth\OcrInterface $ocr)
 * @method static void setUserId(int $userId)
 * @method static void setOcr(\Cruide\StarlineApi\Auth\OcrInterface $ocr)
 * @method static array get(string $path, array $query = [])
 * @method static array post(string $path, array $json = [])
 * @method static array request(string $method, string $path, array $query = [], ?array $json = null)
 * @method static \Cruide\StarlineApi\StarlineApi api()
 *
 * @see \Cruide\StarlineLaravel\Client
 */
class Starline extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
