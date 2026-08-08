<?php namespace StarlineApi\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Orchestra\Testbench\TestCase;
use StarlineApi\StarlineClient;
use StarlineApi\StarlineServiceProvider;
use StarlineApi\Storage\CacheTokenStorage;

class StarlineClientTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    protected function getPackageProviders($app): array
    {
        return [StarlineServiceProvider::class];
    }

    /**
     * @param Response[] $responses
     */
    private function client(array $responses, ?CacheTokenStorage $storage = null): StarlineClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new StarlineClient(
            [
                'app_id' => 123,
                'app_secret' => 'secret',
                'login' => 'user@example.com',
                'password' => 'password',
                'id_url' => 'https://id.starline.ru',
                'api_url' => 'https://developer.starline.ru',
                'timeout' => 5,
            ],
            $storage ?? new CacheTokenStorage($this->app['cache']->store('array')),
            new Client(['handler' => $stack, 'http_errors' => false]),
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
            new Response(200, [], (string) json_encode(['state' => 1, 'desc' => ['user_token' => 'hash:42', 'user_id' => 42]])),
            new Response(200, ['Set-Cookie' => 'slnet=SLNET123; Path=/'], '{}'),
            new Response(200, [], (string) json_encode(['code' => 200, 'codestring' => 'OK', 'desc' => ['user' => ['id' => 42]]])),
        ]);

        $this->assertSame(['user' => ['id' => 42]], $client->userInfo());
        $this->assertCount(5, $this->history);

        // Step 1: secret = md5(appSecret)
        $this->assertStringContainsString('secret='.md5('secret'), (string) $this->history[0]['request']->getUri());
        // Step 2: secret = md5(appSecret + code)
        $this->assertStringContainsString('secret='.md5('secretabc'), (string) $this->history[1]['request']->getUri());
        // Step 3: pass = sha1(password), Bearer app token
        $this->assertStringContainsString('pass='.sha1('password'), (string) $this->history[2]['request']->getBody());
        $this->assertSame('Bearer app-token', $this->history[2]['request']->getHeaderLine('Authorization'));
        // Step 4: auth.slid
        $this->assertStringEndsWith('/json/v2/auth.slid', $this->history[3]['request']->getUri()->getPath());
        // Data request carries the slnet cookie
        $this->assertSame('slnet=SLNET123', $this->history[4]['request']->getHeaderLine('Cookie'));
    }

    public function test_reauthenticates_on_401(): void
    {
        $storage = $this->storage();
        $storage->set('slnet', 'OLD');
        $storage->set('user_id', '42');

        $client = $this->client([
            new Response(401),
            new Response(200, [], (string) json_encode(['state' => 1, 'desc' => ['code' => 'abc']])),
            new Response(200, [], (string) json_encode(['state' => 1, 'desc' => ['token' => 'app-token']])),
            new Response(200, [], (string) json_encode(['state' => 1, 'desc' => ['user_token' => 'hash:42', 'user_id' => 42]])),
            new Response(200, ['Set-Cookie' => 'slnet=NEW; Path=/'], '{}'),
            new Response(200, [], (string) json_encode(['code' => 200, 'desc' => ['ok' => true]])),
        ], $storage);

        $this->assertSame(['ok' => true], $client->deviceData(42));
        $this->assertSame('NEW', $storage->get('slnet'));
        $this->assertCount(6, $this->history);
    }

    public function test_set_param_posts_json(): void
    {
        $storage = $this->storage();
        $storage->set('slnet', 'SL');
        $storage->set('user_id', '7');

        $client = $this->client([
            new Response(200, [], (string) json_encode(['code' => 200, 'desc' => ['state' => 1]])),
        ], $storage);

        $client->arm(7);

        $request = $this->history[0]['request'];
        $this->assertStringEndsWith('/json/v1/device/7/set_param', $request->getUri()->getPath());
        $this->assertSame(['security' => ['arm' => true]], json_decode((string) $request->getBody(), true));
    }
}