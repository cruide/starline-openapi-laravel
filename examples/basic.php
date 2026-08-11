<?php

/**
 * Пример использования StarLine API в Laravel.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */

use Cruide\StarlineApi\Models\Device;
use Cruide\StarlineApi\Models\DeviceState;
use Cruide\StarlineApi\Models\UserInfo;
use StarlineApi\Facades\Starline;

// ---------------------------------------------------------------------------
// 1. Авторизация (обычно ленивая — первый вызов API сам всё сделает)
// ---------------------------------------------------------------------------

// Принудительная авторизация (сбросить кэш и выполнить полную SLID-цепочку):
Starline::authenticate();
Starline::authenticate(true); // сбросить кэш и переавторизоваться заново

// Узнать user_id текущего пользователя:
$userId = Starline::user()->id();

// ---------------------------------------------------------------------------
// 2. Пользователь и его устройства
// ---------------------------------------------------------------------------

// Информация о пользователе + список устройств (типизированные DTO):
$info = Starline::user()->info(); // UserInfo
echo "Пользователь: ", ($info->name() ?? '?'), " (", ($info->email() ?? '?'), ")\n";

// Все устройства пользователя:
foreach (Starline::user()->devices() as $device) {
    echo ' - ', $device->alias() ?? 'без имени', ', модель ', ($device->type() ?? '?'),
         ', id=', $device->id(), ', ', $device->isOnline() ? 'онлайн' : 'офлайн', "\n";
}

// Альтернативный способ:
foreach (Starline::devices()->list() as $device) {
    // ...
}

// Расшаренные устройства:
foreach ($info->sharedDevices() as $device) {
    echo 'Расшарено: ', $device->alias(), "\n";
}

// ---------------------------------------------------------------------------
// 3. Текущее состояние устройства (типизированный DTO)
// ---------------------------------------------------------------------------

$state = Starline::devices()->state($deviceId); // DeviceState

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

// Сырой массив (все поля, включая нестандартные для конкретной прошивки):
$raw = $state->raw();

// ---------------------------------------------------------------------------
// 4. Команды устройству
// ---------------------------------------------------------------------------

Starline::devices()->arm($deviceId);
Starline::devices()->disarm($deviceId);
Starline::devices()->startEngine($deviceId);
Starline::devices()->stopEngine($deviceId);

// Произвольная команда через setParam:
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

// Библиотека типов событий:
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
$position = Starline::devices()->position($deviceId); // устаревший, но работает

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

// Автоматическое распознавание капчи (требуется ext-gd):
Starline::setOcr(new GdOcr());
Starline::authenticate(); // капча решится автоматически

// Или вручную после перехвата исключения:
try {
    Starline::authenticate();
} catch (StarlineAuthCaptchaException $e) {
    if ($e->isCaptchaRequired()) {
        // $e->getCaptchaImg() — URL картинки
        // $e->getCaptchaSid() — идентификатор капчи
        // Показать пользователю, получить код:
        Starline::authenticateWithCaptcha($e->getCaptchaSid(), $codeFromUser);
    }
    if ($e->isSmsRequired()) {
        // $e->getPhone() — номер телефона, куда отправлен SMS
        Starline::authenticateWithSms($smsCode);
    }
}

// ---------------------------------------------------------------------------
// 10. Авторизация через готовый StarLineID-токен
// ---------------------------------------------------------------------------

// Если токен формата "<hash>:<user_id>" уже получен через OAuth:
Starline::authenticateWithSlidToken('f6e706e17d41ce781b5166f09e782fd0:1663');

// login/password в .env не понадобятся, но app_id и app_secret всё ещё нужны.

// ---------------------------------------------------------------------------
// 11. Явная установка user_id (если автоопределение не сработало)
// ---------------------------------------------------------------------------

Starline::setUserId(12345678);

// Или через .env:
// STARLINE_USER_ID=12345678
