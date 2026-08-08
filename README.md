# StarlineApi for Laravel

Разработчик: [Alexander Tischenko](http://alex-tisch.ru).

A compact Laravel client for the [StarLine OpenAPI](https://developer.starline.ru/) —
vehicle state, remote commands, events. Built on Guzzle, no manual cURL.

- Laravel **12**, PHP **>= 8.2**
- Full SLID auth chain with token caching (Laravel cache)
- Automatic re-authentication on HTTP 401
- Facade + DI, publishable config
- Not affiliated with StarLine NPO. Use at your own risk.

## Installation

```bash
composer require cruide/starline-openapi-laravel
php artisan vendor:publish --tag=starline-config
```

Add credentials to `.env`:

```dotenv
STARLINE_APP_ID=123456
STARLINE_APP_SECRET=your-app-secret
STARLINE_LOGIN=user@example.com
STARLINE_PASSWORD=secret
# Optional
# STARLINE_USER_ID=
# STARLINE_CACHE_STORE=
# STARLINE_CACHE_TTL=86400
```

App ID / Secret Key are issued at <https://my.starline.ru/developer>.

## Usage

```php
use StarlineApi\Facades\Starline;

// Profile + devices
$info = Starline::userInfo();

foreach ($info['user']['devices'] ?? [] as $device) {
    $state = Starline::deviceData($device['device_id']);
    // raw array — inspect the structure for your device model
}

// Commands
Starline::startEngine($deviceId);
Starline::stopEngine($deviceId);
Starline::arm($deviceId);
Starline::disarm($deviceId);

// Any endpoint from the docs
Starline::get('/json/v3/device/'.$deviceId.'/data');
Starline::post('/json/v1/device/'.$deviceId.'/set_param', ['security' => ['arm' => true]]);
```

Or via dependency injection:

```php
use StarlineApi\StarlineClient;

class StarlineController
{
    public function __construct(private StarlineClient $starline)
    {
    }
}
```

## How authentication works (SLID)

| Step | Request | Payload | Result |
|------|---------|---------|--------|
| 1 | `GET id.starline.ru/apiV3/application/getCode` | `appId`, `secret = md5(appSecret)` | app code |
| 2 | `GET id.starline.ru/apiV3/application/getToken` | `appId`, `secret = md5(appSecret + code)` | app token |
| 3 | `POST id.starline.ru/apiV3/user/login` | `login`, `pass = sha1(password)`, `Bearer appToken` | SLID token + user_id |
| 4 | `POST developer.starline.ru/json/v2/auth.slid` | form: `slid`, `appId` | `slnet` cookie |

All further requests carry `Cookie: slnet=...`. App/SLID tokens and `slnet`
are cached; on a 401 the session is flushed and the chain runs once again
automatically. `md5`/`sha1` hashing is mandated by the StarLine protocol.

## API reference

| Method | Description |
|--------|-------------|
| `authenticate(bool $force = false): string` | Run the SLID chain, return slnet |
| `userId(): int` | Current user id (auto-detected, cached) |
| `userInfo(): array` | Profile + devices (`user_info`) |
| `deviceData($id): array` | Live state (`/json/v3/device/{id}/data`) |
| `setParam($id, array $params): array` | Command (`/json/v1/device/{id}/set_param`) |
| `arm / disarm / startEngine / stopEngine($id)` | Shortcut commands |
| `get($path, $query) / post($path, $json)` | Raw authenticated requests |

## Error handling

| Exception | Meaning |
|-----------|---------|
| `StarlineAuthException` | Bad credentials or expired tokens (after one automatic retry) |
| `StarlineApiException` | API error envelope / HTTP >= 400 (`getApiCode()`, `getRaw()`) |
| `StarlineException` | Misconfiguration, base class |

## Notes

- `deviceData()` returns the raw array: field names depend on the device model.
- Command payload shapes (`arm`, `startEngine`, ...) follow community clients;
  verify them against the live Swagger at developer.starline.ru if needed.
- To force a fresh login: `Starline::authenticate(true)`.

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT