# Starline OpenApi for Laravel

Разработчик: [Alexander Tischenko](http://alex-tisch.ru).

A compact Laravel client for the [StarLine OpenAPI](https://developer.starline.ru/) —
vehicle state, remote commands, events, GPS tracks. Built on top of
[starline-openapi-php](https://github.com/cruide/starline-openapi-php), using
Guzzle for HTTP and Laravel Cache for token storage.

- Laravel **12**, PHP **>= 8.2**
- Full SLID auth chain with token caching (Laravel cache)
- Automatic re-authentication on HTTP 401
- Captcha and SMS support (with pluggable OCR)
- Typed models: `Device`, `DeviceState`, `UserInfo`
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
use Cruide\StarlineLaravel\Facades\Starline;

// Profile + devices (typed DTOs)
$info = Starline::user()->info();   // UserInfo
$userId = Starline::user()->id();   // int

$devices = Starline::user()->devices(); // Device[]
foreach ($devices as $device) {
    echo $device->alias();   // "Car A"
    echo $device->isOnline() ? 'online' : 'offline';

    // Live state
    $state = Starline::devices()->state($device->id()); // DeviceState
    echo $state->isArmed() ? 'armed' : 'disarmed';
    echo $state->interiorTemperature();  // °C
    echo $state->batteryVoltage();       // V
    echo $state->latitude(), $state->longitude(); // GPS
    echo $state->mileage();              // km
}

// Commands
Starline::devices()->arm($deviceId);
Starline::devices()->disarm($deviceId);
Starline::devices()->startEngine($deviceId);
Starline::devices()->stopEngine($deviceId);
Starline::devices()->setParam($deviceId, ['webasto' => ['start' => true]]);

// Events & tracks
$events = Starline::devices()->events($deviceId, $periodStart, $periodEnd);
$track  = Starline::devices()->ways($deviceId, $begin, $end);
$types  = Starline::devices()->eventTypes();

// Any endpoint from the docs
Starline::get('/json/v3/device/'.$deviceId.'/data');
Starline::post('/json/v1/device/'.$deviceId.'/set_param', ['security' => ['arm' => true]]);
```

Or via dependency injection:

```php
use Cruide\StarlineLaravel\Client;

class StarlineController
{
    public function __construct(private Client $starline)
    {
    }
}
```

## How authentication works (SLID)

| Step | Request | Payload | Result |
|------|---------|---------|--------|
| 1 | `GET id.starline.ru/apiV3/application/getCode` | `appId`, `secret = md5(appSecret)` | app code |
| 2 | `GET id.starline.ru/apiV3/application/getToken` | `appId`, `secret = md5(appSecret + code)` | app token |
| 3 | `POST id.starline.ru/apiV3/user/login` | `login`, `pass = sha1(password)`, header `token: appToken` | user_token |
| 4 | `POST developer.starline.ru/json/v2/auth.slid` | JSON: `{"slid_token": "..."}` | `slnet` cookie + `user_id` |

All further requests carry `Cookie: slnet=...`. App/SLID tokens and `slnet`
are cached; on a 401 the session is flushed and the chain runs once again
automatically. `md5`/`sha1` hashing is mandated by the StarLine protocol.

## API reference

### Facade / Client

| Method | Description |
|--------|-------------|
| `user(): UserApi` | User-related methods |
| `devices(): DeviceApi` | Device-related methods |
| `authenticate(bool $force = false): void` | Run the SLID chain |
| `setUserId(int $id): void` | Override auto-detected user_id |
| `get($path, $query): array` | Raw authenticated GET |
| `post($path, $json): array` | Raw authenticated POST |
| `request($method, $path, $query, $json): array` | Generic authenticated request |

### UserApi (via `Starline::user()`)

| Method | Returns | Description |
|--------|---------|-------------|
| `id()` | `int` | Current user ID |
| `info()` | `UserInfo` | Profile + devices |
| `devices()` | `Device[]` | All user devices |

### DeviceApi (via `Starline::devices()`)

| Method | Returns | Description |
|--------|---------|-------------|
| `state($id)` | `DeviceState` | Live device state |
| `arm($id)` | `array` | Arm security |
| `disarm($id)` | `array` | Disarm security |
| `startEngine($id)` | `array` | Remote engine start |
| `stopEngine($id)` | `array` | Stop engine |
| `setParam($id, $params)` | `array` | Arbitrary command |
| `events($id, $start, $end)` | `array` | Event history |
| `ways($id, $begin, $end)` | `array` | GPS track |
| `eventTypes()` | `array` | All event types |
| `eventType($id)` | `array` | Single event type description |
| `details($id)` | `array` | Detailed device info |
| `position($id)` | `array` | Last known position (deprecated) |
| `list()` | `Device[]` | Alias for `user()->devices()` |

### Models

| Model | Key getters |
|-------|-------------|
| `Device` | `id()`, `type()`, `alias()`, `imei()`, `isOnline()`, `raw()` |
| `DeviceState` | `isArmed()`, `isEngineRunning()`, `interiorTemperature()`, `engineTemperature()`, `batteryVoltage()`, `gsmBalance()`, `latitude()`, `longitude()`, `mileage()`, `updatedAt()`, `raw()` |
| `UserInfo` | `id()`, `name()`, `email()`, `devices()`, `sharedDevices()`, `raw()` |

All model getters return `null` for missing fields (safe across firmware versions).

## Error handling

| Exception | Meaning |
|-----------|---------|
| `Cruide\StarlineApi\Exceptions\StarlineAuthException` | Bad credentials or expired tokens |
| `Cruide\StarlineApi\Exceptions\StarlineApiException` | API error / HTTP >= 400 (`getRaw()`) |
| `Cruide\StarlineApi\Exceptions\StarlineAuthCaptchaException` | Captcha or SMS required |
| `Cruide\StarlineApi\Exceptions\StarlineException` | Base class for all errors |

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT
