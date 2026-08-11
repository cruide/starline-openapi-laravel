<?php

/**
 * Пример использования StarLine API в Laravel.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */

use Cruide\StarlineApi\Models\Device;
use Cruide\StarlineApi\Models\DeviceState;
use Cruide\StarlineApi\Models\UserInfo;
use Cruide\StarlineLaravel\Facades\Starline;

// ---------------------------------------------------------------------------
// 1. Авторизация (обычно ленивая — первый вызов API сам всё сделает)
// ---------------------------------------------------------------------------

Starline::authenticate();
Starline::authenticate(true);

$userId = Starline::user()->id();

// ---------------------------------------------------------------------------
// 2. Пользователь и его устройства
// ---------------------------------------------------------------------------

$info = Starline::user()->info();

foreach (Starline::user()->devices() as $device) {
    echo ' - ', $device->alias() ?? 'без имени', ', модель ', ($device->type() ?? '?'),
         ', id=', $device->id(), ', ', $device->isOnline() ? 'онлайн' : 'офлайн', "\n";
}

foreach (Starline::devices()->list() as $device) {
    // ...
}

foreach ($info->sharedDevices() as $device) {
    echo 'Расшарено: ', $device->alias(), "\n";
}

// ---------------------------------------------------------------------------
// 3. Текущее состояние устройства
// ---------------------------------------------------------------------------

$state = Starline::devices()->state($deviceId);

echo 'Охрана:        ', $state->isArmed() ? 'да' : 'нет', "\n";
echo 'Двигатель:     ', $state->isEngineRunning() ? 'работает' : 'остановлен', "\n";
echo 'Салон:         ', ($state->interiorTemperature() ?? '?'), " °C\n";
echo 'Двигатель:     ', ($state->engineTemperature() ?? '?'), " °C\n";
echo 'АКБ:           ', ($state->batteryVoltage() ?? '?'), " В\n";
echo 'Баланс SIM:    ', ($state->gsmBalance() ?? '?'), "\n";
echo 'Пробег:        ', ($state->mileage() ?? '?'), " км\n";
echo 'Координаты:    ', ($state->latitude() ?? '?'), ', ', ($state->longitude() ?? '?'), "\n";
echo 'Обновлено:     ', $state->updatedAt()
    ? date('Y-m-d H:i:s', $state->updatedAt())
    : '?', "\n";

$raw = $state->raw();

// ---------------------------------------------------------------------------
// 4. Команды устройству
// ---------------------------------------------------------------------------

Starline::devices()->arm($deviceId);
Starline::devices()->disarm($deviceId);
Starline::devices()->startEngine($deviceId);
Starline::devices()->stopEngine($deviceId);

Starline::devices()->setParam($deviceId, [
    'webasto'  => ['start' => true],
    'security' => ['arm' => true],
]);

// ---------------------------------------------------------------------------
// 5. События и история
// ---------------------------------------------------------------------------

$periodStart = strtotime('2026-08-01 00:00:00');
$periodEnd   = strtotime('2026-08-08 00:00:00');

$events = Starline::devices()->events($deviceId, $periodStart, $periodEnd);

foreach ($events['events'] ?? [] as $event) {
    echo date('Y-m-d H:i:s', $event['timestamp'] ?? 0),
         ' — ', $event['type'] ?? '?', "\n";
}

$allTypes = Starline::devices()->eventTypes();
$oneType  = Starline::devices()->eventType(307);

// ---------------------------------------------------------------------------
// 6. GPS-трек
// ---------------------------------------------------------------------------

$begin = strtotime('2026-08-01 00:00:00');
$end   = strtotime('2026-08-08 00:00:00');

$track = Starline::devices()->ways($deviceId, $begin, $end, [
    'split_way' => true,
    'dt_max'    => 2,
]);

echo 'Пробег: ', $track['mileage'] ?? 0, " км\n";

foreach ($track['way'] ?? [] as $segment) {
    if (($segment['type'] ?? '') === 'TRACK') {
        foreach ($segment['nodes'] ?? [] as $node) {
            echo date('H:i:s', $node['t']), ' — ', $node['x'], ', ', $node['y'], "\n";
        }
    }
}

// ---------------------------------------------------------------------------
// 7. Детальная информация и местоположение
// ---------------------------------------------------------------------------

$details  = Starline::devices()->details($deviceId);
$position = Starline::devices()->position($deviceId);

// ---------------------------------------------------------------------------
// 8. Произвольные запросы к API
// ---------------------------------------------------------------------------

$data = Starline::get('/json/v3/device/' . $deviceId . '/data', ['param' => 'value']);
$data = Starline::post('/json/v1/device/' . $deviceId . '/set_param', [
    'security' => ['arm' => true],
]);
$data = Starline::request('GET', '/json/v3/some/endpoint', ['filter' => 'active']);

// ---------------------------------------------------------------------------
// 9. Капча и SMS-подтверждение
// ---------------------------------------------------------------------------

use Cruide\StarlineApi\Auth\GdOcr;
use Cruide\StarlineApi\Exceptions\StarlineAuthCaptchaException;

Starline::setOcr(new GdOcr());
Starline::authenticate();

try {
    Starline::authenticate();
} catch (StarlineAuthCaptchaException $e) {
    if ($e->isCaptchaRequired()) {
        Starline::authenticateWithCaptcha($e->getCaptchaSid(), $codeFromUser);
    }
    if ($e->isSmsRequired()) {
        Starline::authenticateWithSms($smsCode);
    }
}

// ---------------------------------------------------------------------------
// 10. Авторизация через готовый StarLineID-токен
// ---------------------------------------------------------------------------

Starline::authenticateWithSlidToken('f6e706e17d41ce781b5166f09e782fd0:1663');

// ---------------------------------------------------------------------------
// 11. Явная установка user_id
// ---------------------------------------------------------------------------

Starline::setUserId(12345678);
