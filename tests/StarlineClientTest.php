<?php namespace StarlineApi\Tests;

use Cruide\StarlineApi\Models\Device;
use Cruide\StarlineApi\Models\DeviceState;
use Cruide\StarlineApi\Models\UserInfo;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Orchestra\Testbench\TestCase;
use Cruide\StarlineApi\Exceptions\StarlineApiException;
use Cruide\StarlineApi\Exceptions\StarlineAuthException;
use Cruide\StarlineApi\Exceptions\StarlineException;
use StarlineApi\StarlineClient;
use StarlineApi\StarlineServiceProvider;
use StarlineApi\Storage\CacheTokenStorage;

class StarlineClientTest extends TestCase
{
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->history = [];
    }

    protected function getPackageProviders($app): array
    {
        return [StarlineServiceProvider::class];
    }

    private function client(array $responses, ?CacheTokenStorage $storage = null): StarlineClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $httpClient = new Client(['handler' => $stack, 'http_errors' => false]);

        return new StarlineClient(
            [
                'app_id' => 123,
                'app_secret' => 'secret',
                'login' => 'user@example.com',
                'password' => 'password',
                'timeout' => 5,
            ],
            $storage ?? new CacheTokenStorage($this->app['cache']->store('array')),
            $httpClient,
        );
    }

    private function storage(): CacheTokenStorage
    {
        return new CacheTokenStorage($this->app['cache']->store('array'));
    }

    public function test_full_auth_chain_and_user_info(): void
    {
        $client = $this->client([
            new Response(200, [], (string) json_encode(['state' => 1, 'desc' => ['code' => 'abc']])),
            new Response(200, [], (string) json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])),
            new Response(200, [], (string) json_encode(['state' => 1, 'desc' => ['user_token' => 'hash:42']])),
            new Response(200, ['Set-Cookie' => 'slnet=SLNET123; Path=/'], (string) json_encode(['user_id' => 42])),
            new Response(200, [], (string) json_encode(['code' => 200, 'codestring' => 'OK', 'desc' => ['id' => 42, 'user' => 'test']])),
        ]);

        $info = $client->user()->info();

        $this->assertInstanceOf(UserInfo::class, $info);
        $this->assertSame(42, $info->id());
        $this->assertCount(5, $this->history);

        $this->assertStringContainsString('secret=' . md5('secret'), (string) $this->history[0]['request']->getUri());
        $this->assertStringContainsString('secret=' . md5('secretabc'), (string) $this->history[1]['request']->getUri());
        $this->assertStringContainsString('pass=' . sha1('password'), (string) $this->history[2]['request']->getBody());
        $this->assertStringEndsWith('/json/v2/auth.slid', $this->history[3]['request']->getUri()->getPath());
        $this->assertStringContainsString('"slid_token":"hash:42"', (string) $this->history[3]['request']->getBody());
        $this->assertSame('slnet=SLNET123', $this->history[4]['request']->getHeaderLine('Cookie'));
    }

    public function test_reauthenticates_on_401(): void
    {
        $storage = $this->storage();
        $storage->set('starline.slnet', 'OLD');
        $storage->set('starline.user_id', '42');
        $storage->set('starline.user_token', 'hash:42');

        $client = $this->client([
            new Response(401),
            new Response(200, [], (string) json_encode(['state' => 1, 'desc' => ['code' => 'abc']])),
            new Response(200, [], (string) json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])),
            new Response(200, [], (string) json_encode(['state' => 1, 'desc' => ['user_token' => 'hash:42']])),
            new Response(200, ['Set-Cookie' => 'slnet=NEW; Path=/'], (string) json_encode(['user_id' => 42])),
            new Response(200, [], (string) json_encode(['code' => 200, 'desc' => ['ok' => true]])),
        ], $storage);

        $state = $client->devices()->state(42);

        $this->assertInstanceOf(DeviceState::class, $state);
        $this->assertSame('NEW', $storage->get('starline.slnet'));
        $this->assertCount(6, $this->history);
    }

    public function test_disarm(): void
    {
        $storage = $this->storage();
        $storage->set('starline.slnet', 'SL');
        $storage->set('starline.user_id', '7');

        $client = $this->client([
            new Response(200, [], (string) json_encode(['code' => 200, 'desc' => ['state' => 1]])),
        ], $storage);

        $client->devices()->disarm(7);

        $request = $this->history[0]['request'];
        $this->assertStringEndsWith('/json/v1/device/7/set_param', $request->getUri()->getPath());
        $this->assertSame(['security' => ['arm' => false]], json_decode((string) $request->getBody(), true));
    }

    public function test_stop_engine(): void
    {
        $storage = $this->storage();
        $storage->set('starline.slnet', 'SL');
        $storage->set('starline.user_id', '7');

        $client = $this->client([
            new Response(200, [], (string) json_encode(['code' => 200, 'desc' => ['state' => 1]])),
        ], $storage);

        $client->devices()->stopEngine(7);

        $request = $this->history[0]['request'];
        $this->assertStringEndsWith('/json/v1/device/7/set_param', $request->getUri()->getPath());
        $this->assertSame(['engine' => ['stop' => true]], json_decode((string) $request->getBody(), true));
    }

    public function test_raw_get(): void
    {
        $storage = $this->storage();
        $storage->set('starline.slnet', 'SL');
        $storage->set('starline.user_id', '7');

        $client = $this->client([
            new Response(200, [], (string) json_encode(['code' => 200, 'desc' => ['data' => 'value']])),
        ], $storage);

        $result = $client->get('/json/v1/custom', ['key' => 'val']);

        $this->assertSame(['data' => 'value'], $result);

        $request = $this->history[0]['request'];
        $this->assertStringEndsWith('/json/v1/custom', $request->getUri()->getPath());
        $this->assertStringContainsString('key=val', (string) $request->getUri()->getQuery());
    }

    public function test_user_id_from_slid_token_fallback(): void
    {
        $storage = $this->storage();
        $storage->set('starline.slnet', 'SL');
        $storage->set('starline.user_token', 'hash:99');

        $client = $this->client([], $storage);

        $this->assertSame(99, $client->user()->id());
    }

    public function test_api_exception_on_error_envelope(): void
    {
        $storage = $this->storage();
        $storage->set('starline.slnet', 'SL');
        $storage->set('starline.user_id', '7');

        $client = $this->client([
            new Response(500, [], (string) json_encode(['code' => 500, 'codestring' => 'Internal Error'])),
        ], $storage);

        $this->expectException(StarlineApiException::class);
        $client->devices()->state(7);
    }

    public function test_auth_exception_on_bad_credentials(): void
    {
        $this->expectException(StarlineAuthException::class);

        $this->client([
            new Response(200, [], (string) json_encode(['state' => 0, 'desc' => ['message' => 'Bad credentials']])),
        ])->user()->info();
    }

    public function test_missing_config_throws_exception(): void
    {
        $this->expectException(StarlineException::class);

        new StarlineClient(
            ['app_id' => '', 'app_secret' => '', 'login' => '', 'password' => ''],
            $this->storage(),
        );
    }

    public function test_authenticate_returns_and_caches_token(): void
    {
        $storage = $this->storage();
        $storage->set('starline.slnet', 'CACHED_SLNET');

        $client = $this->client([], $storage);

        $client->authenticate();
        $this->assertSame('CACHED_SLNET', $storage->get('starline.slnet'));
        $this->assertCount(0, $this->history);
    }

    public function test_device_list(): void
    {
        $storage = $this->storage();
        $storage->set('starline.slnet', 'SL');
        $storage->set('starline.user_id', '42');

        $client = $this->client([
            new Response(200, [], (string) json_encode([
                'code' => 200,
                'desc' => [
                    'id' => 42,
                    'devices' => [
                        ['device_id' => 1, 'alias' => 'Car A', 'device_type' => 'S96 v2', 'imei' => '123', 'online' => true],
                        ['device_id' => 2, 'alias' => 'Car B', 'device_type' => 'E96', 'imei' => '456', 'online' => false],
                    ],
                ],
            ])),
        ], $storage);

        $devices = $client->user()->devices();

        $this->assertCount(2, $devices);
        $this->assertInstanceOf(Device::class, $devices[0]);
        $this->assertSame(1, $devices[0]->id());
        $this->assertSame('Car A', $devices[0]->alias());
        $this->assertTrue($devices[0]->isOnline());
        $this->assertSame('S96 v2', $devices[0]->type());
        $this->assertFalse($devices[1]->isOnline());
    }

    public function test_device_state(): void
    {
        $storage = $this->storage();
        $storage->set('starline.slnet', 'SL');
        $storage->set('starline.user_id', '42');

        $client = $this->client([
            new Response(200, [], (string) json_encode([
                'code' => 200,
                'desc' => [
                    'device_id' => 1,
                    'security' => ['arm' => true],
                    'engine' => ['running' => true],
                    'interior_temp' => 22.5,
                    'gps' => ['lat' => 55.7558, 'lon' => 37.6173],
                    'battery' => ['voltage' => 12.6],
                    'timestamp' => 1696000000,
                ],
            ])),
        ], $storage);

        $state = $client->devices()->state(1);

        $this->assertInstanceOf(DeviceState::class, $state);
        $this->assertTrue($state->isArmed());
        $this->assertTrue($state->isEngineRunning());
        $this->assertSame(22.5, $state->interiorTemperature());
        $this->assertSame(55.7558, $state->latitude());
        $this->assertSame(37.6173, $state->longitude());
        $this->assertSame(12.6, $state->batteryVoltage());
        $this->assertSame(1696000000, $state->updatedAt());
    }

    public function test_events(): void
    {
        $storage = $this->storage();
        $storage->set('starline.slnet', 'SL');
        $storage->set('starline.user_id', '42');

        $client = $this->client([
            new Response(200, [], (string) json_encode([
                'code' => 200,
                'desc' => ['events' => []],
            ])),
        ], $storage);

        $result = $client->devices()->events(1, 1696000000, 1696100000);

        $this->assertSame(['events' => []], $result);

        $request = $this->history[0]['request'];
        $this->assertStringEndsWith('/json/v2/device/1/events', $request->getUri()->getPath());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame(1696000000, $body['period_start']);
        $this->assertSame(1696100000, $body['period_end']);
    }

    public function test_ways(): void
    {
        $storage = $this->storage();
        $storage->set('starline.slnet', 'SL');
        $storage->set('starline.user_id', '42');

        $client = $this->client([
            new Response(200, [], (string) json_encode([
                'code' => 200,
                'desc' => ['ways' => []],
            ])),
        ], $storage);

        $result = $client->devices()->ways(1, 1696000000, 1696100000);

        $this->assertSame(['ways' => []], $result);

        $request = $this->history[0]['request'];
        $this->assertStringEndsWith('/json/v1/device/1/ways', $request->getUri()->getPath());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame(1696000000, $body['begin']);
        $this->assertSame(1696100000, $body['end']);
    }

    public function test_event_types(): void
    {
        $storage = $this->storage();
        $storage->set('starline.slnet', 'SL');
        $storage->set('starline.user_id', '42');

        $client = $this->client([
            new Response(200, [], (string) json_encode([
                'code' => 200,
                'desc' => ['types' => [['id' => 1, 'name' => 'ignition']]],
            ])),
        ], $storage);

        $result = $client->devices()->eventTypes();

        $this->assertSame(['types' => [['id' => 1, 'name' => 'ignition']]], $result);

        $request = $this->history[0]['request'];
        $this->assertStringEndsWith('/json/v3/library/events', $request->getUri()->getPath());
    }

    public function test_device_details(): void
    {
        $storage = $this->storage();
        $storage->set('starline.slnet', 'SL');
        $storage->set('starline.user_id', '42');

        $client = $this->client([
            new Response(200, [], (string) json_encode([
                'code' => 200,
                'desc' => ['model' => 'S96 v2'],
            ])),
        ], $storage);

        $result = $client->devices()->details(1);

        $this->assertSame(['model' => 'S96 v2'], $result);

        $request = $this->history[0]['request'];
        $this->assertStringEndsWith('/json/v1/device/1/details', $request->getUri()->getPath());
    }

    public function test_user_id_from_config(): void
    {
        $storage = $this->storage();

        $client = new StarlineClient(
            [
                'app_id' => 123,
                'app_secret' => 'secret',
                'login' => 'user@example.com',
                'password' => 'password',
                'user_id' => 99,
            ],
            $storage,
        );

        $this->assertSame('99', $storage->get('starline.user_id'));
    }
}
