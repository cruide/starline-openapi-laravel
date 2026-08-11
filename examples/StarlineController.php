<?php

/**
 * Пример использования StarLine API через Dependency Injection в Laravel.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */

namespace App\Http\Controllers;

use Cruide\StarlineApi\Exceptions\StarlineApiException;
use Cruide\StarlineApi\Exceptions\StarlineAuthException;
use StarlineApi\StarlineClient;

class StarlineController
{
    public function __construct(private StarlineClient $starline)
    {
    }

    /**
     * Список устройств и их состояние.
     */
    public function index(): array
    {
        $result = [];

        foreach ($this->starline->user()->devices() as $device) {
            $state = $this->starline->devices()->state($device->id());

            $result[] = [
                'id'          => $device->id(),
                'alias'       => $device->alias(),
                'type'        => $device->type(),
                'online'      => $device->isOnline(),
                'armed'       => $state->isArmed(),
                'engine'      => $state->isEngineRunning(),
                'temperature' => $state->interiorTemperature(),
                'battery'     => $state->batteryVoltage(),
                'latitude'    => $state->latitude(),
                'longitude'   => $state->longitude(),
                'updated_at'  => $state->updatedAt(),
            ];
        }

        return $result;
    }

    /**
     * Состояние конкретного устройства.
     */
    public function state(int $deviceId): array
    {
        $state = $this->starline->devices()->state($deviceId);

        return $state->raw();
    }

    /**
     * Команда устройству.
     */
    public function command(int $deviceId, string $action): array
    {
        return match ($action) {
            'arm'         => $this->starline->devices()->arm($deviceId),
            'disarm'      => $this->starline->devices()->disarm($deviceId),
            'start'       => $this->starline->devices()->startEngine($deviceId),
            'stop'        => $this->starline->devices()->stopEngine($deviceId),
            default       => throw new \InvalidArgumentException('Unknown action'),
        };
    }

    /**
     * События устройства за последние 7 дней.
     */
    public function events(int $deviceId): array
    {
        try {
            return $this->starline->devices()->events(
                $deviceId,
                strtotime('-7 days'),
                time(),
                ['limit' => 100]
            );
        } catch (StarlineApiException $e) {
            return ['error' => $e->getMessage(), 'raw' => $e->getRaw()];
        } catch (StarlineAuthException $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
