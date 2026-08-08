<?php namespace StarlineApi\Facades;

use Illuminate\Support\Facades\Facade;
use StarlineApi\StarlineClient;

/**
 * @method static string authenticate(bool $force = false)
 * @method static int userId()
 * @method static array userInfo()
 * @method static array deviceData(int|string $deviceId)
 * @method static array setParam(int|string $deviceId, array $params)
 * @method static array arm(int|string $deviceId)
 * @method static array disarm(int|string $deviceId)
 * @method static array startEngine(int|string $deviceId)
 * @method static array stopEngine(int|string $deviceId)
 * @method static array get(string $path, array $query = [])
 * @method static array post(string $path, array $json = [])
 *
 * @see \StarlineApi\StarlineClient
 */
class Starline extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StarlineClient::class;
    }
}