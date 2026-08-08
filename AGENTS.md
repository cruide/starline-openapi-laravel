# AGENTS.md

## Package identity

- **Laravel 12 package** (library, not an app). Distributed as a Composer package.
- Root namespace: `StarlineApi\` (PSR-4 mapped to `src/`).
- Tests use Orch estra Testbench (simulates a Laravel app for a package). The test class extends `Orchestra\Testbench\TestCase`, not PHPUnit's `TestCase`.
- The `composer.json` name is `kengois/starline-api`. The README mentions `cruide/starline-openapi-laravel` — these refer to the same package.

## Commands

```bash
composer install
vendor/bin/phpunit
```

There is no lint, typecheck, or CI config in this repo.

## Testing

- Tests live in `tests/StarlineClientTest.php` — a single test file.
- Tests inject a `GuzzleHttp\Handler\MockHandler` via `HandlerStack` to simulate HTTP. They never hit a real StarLine server.
- The history middleware captures every request for assertion of headers, URIs, and body.
- Use `Orchestra\Testbench\TestCase::getPackageProviders()` to register `StarlineServiceProvider`.
- The `array` cache store is used in tests (no real cache needed).

## Architecture

### Entry point

`src/StarlineClient.php` is the single client class. Everything flows through it. The facade (`StarlineApi\Facades\Starline`) proxies to it via the container key `StarlineClient::class`.

### SLID auth chain (non-obvious)

Auth is a mandatory 4-step chain using two separate hosts:

| Step | Host | Endpoint | Hashing |
|------|------|----------|---------|
| 1 | `id.starline.ru` | `/apiV3/application/getCode` | `secret = md5(appSecret)` |
| 2 | `id.starline.ru` | `/apiV3/application/getToken` | `secret = md5(appSecret + code)` |
| 3 | `id.starline.ru` | `/apiV3/user/login` | `pass = sha1(password)`, `Authorization: Bearer {appToken}` |
| 4 | `developer.starline.ru` | `/json/v2/auth.slid` | form-encoded `slid` + `appId` → returns `slnet` cookie |

All data requests go to `developer.starline.ru` with `Cookie: slnet={token}`.

- `md5` and `sha1` are mandated by StarLine's protocol — do not replace with stronger hashes.
- The response envelope on `id.starline.ru` is `{"state":1,"desc":{...}}`; `state` must be 1.
- The response envelope on `developer.starline.ru` is `{"code":200,"codestring":"OK","desc":{...}}`.

### Caching

Tokens (`app_token`, `slid_token`, `user_id`, `slnet`) are cached in Laravel's cache via `CacheTokenStorage`. On HTTP 401, the cache is flushed and the full auth chain re-runs automatically (single retry).

### Exceptions

| Exception | Parent | When |
|-----------|--------|------|
| `StarlineException` | `\RuntimeException` | Base; also thrown for missing config keys |
| `StarlineAuthException` | `StarlineException` | Auth chain failure (bad creds, no slnet cookie, state != 1) |
| `StarlineApiException` | `StarlineException` | API returns error envelope or HTTP >= 400. Has `getApiCode()` and `getRaw()` methods |

### Config

Users publish the config via `php artisan vendor:publish --tag=starline-config`. The source file is `config/starline.php`. Required env vars: `STARLINE_APP_SECRET`, `STARLINE_LOGIN`, `STARLINE_PASSWORD`. `STARLINE_APP_ID`, `STARLINE_USER_ID`, cache settings, and endpoint URLs are optional.

### No generated code, no migrations, no codegen

This is a straightforward API client with no code generation step.
