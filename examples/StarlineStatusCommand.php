<?php

/**
 * Пример Artisan-команды для работы со StarLine API.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */

namespace App\Console\Commands;

use Cruide\StarlineApi\Exceptions\StarlineApiException;
use Cruide\StarlineApi\Exceptions\StarlineAuthException;
use Illuminate\Console\Command;
use StarlineApi\StarlineClient;

class StarlineStatusCommand extends Command
{
    protected $signature = 'starline:status
                            {device? : ID устройства (если не указан — все)}
                            {--full : Показать полную информацию}';

    protected $description = 'Показать состояние устройств StarLine';

    public function handle(StarlineClient $starline): int
    {
        try {
            $devices = $starline->user()->devices();
        } catch (StarlineAuthException $e) {
            $this->error('Ошибка авторизации: ' . $e->getMessage());

            return self::FAILURE;
        }

        if (empty($devices)) {
            $this->warn('Нет устройств.');

            return self::SUCCESS;
        }

        $deviceId = $this->argument('device');

        if ($deviceId !== null) {
            $devices = array_filter($devices, fn ($d) => (string) $d->id() === (string) $deviceId);
        }

        foreach ($devices as $device) {
            try {
                $state = $starline->devices()->state($device->id());
            } catch (StarlineApiException $e) {
                $this->error("Ошибка устройства {$device->id()}: " . $e->getMessage());
                continue;
            }

            $this->line('');
            $this->info('=== ' . ($device->alias() ?? 'Устройство #' . $device->id()) . ' ===');
            $this->line('  ID:           ' . $device->id());
            $this->line('  Модель:       ' . ($device->type() ?? '?'));
            $this->line('  Статус:       ' . ($device->isOnline() ? 'онлайн' : 'офлайн'));
            $this->line('  Охрана:       ' . ($state->isArmed() ? 'включена' : 'выключена'));
            $this->line('  Двигатель:    ' . ($state->isEngineRunning() ? 'работает' : 'остановлен'));

            if ($this->option('full')) {
                $this->line('  Салон:        ' . ($state->interiorTemperature() ?? '?') . ' °C');
                $this->line('  Двигатель:    ' . ($state->engineTemperature() ?? '?') . ' °C');
                $this->line('  АКБ:          ' . ($state->batteryVoltage() ?? '?') . ' В');
                $this->line('  Баланс SIM:   ' . ($state->gsmBalance() ?? '?'));
                $this->line('  Пробег:       ' . ($state->mileage() ?? '?') . ' км');
                $this->line('  Координаты:   ' . ($state->latitude() ?? '?') . ', ' . ($state->longitude() ?? '?'));
                $this->line('  Обновлено:    ' . ($state->updatedAt()
                    ? date('Y-m-d H:i:s', $state->updatedAt())
                    : '?'));
            }
        }

        $this->line('');

        return self::SUCCESS;
    }
}
