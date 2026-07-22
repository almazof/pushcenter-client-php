# pushcenter/client

Тонкий PHP-клиент шлюза PushCenter для бэкендов проектов. Реализует HTTP API v1
контракта (`../contract/SPEC-API.md`, `SPEC-PAYLOAD.md`) — регистрацию устройств,
bind/unbind и постановку уведомлений с контрактными ретраями.

## Требования и зависимости

- **PHP >= 8.1** — осознанно шире, чем у шлюза (8.3+): клиент ставится в бэкенды
  разных проектов, и требовать от каждого из них последний PHP нельзя; 8.1 — нижняя
  граница с `readonly`-свойствами и enum, достаточная для строгих DTO.
- **Zero-dependency ядро**: в `require` только PSR-интерфейсы (`psr/http-client`,
  `psr/http-factory`, `psr/http-message`, `psr/log`) — ни одной конкретной
  HTTP-библиотеки. Транспорт на выбор:
  - `CurlTransport` (встроенный, по умолчанию) — голый ext-curl, для проектов без
    PSR-18-клиента (как Safar-Backend);
  - `Psr18Transport` — адаптер под любой PSR-18 клиент проекта (Guzzle, symfony/http-client).

## Быстрый старт

```php
use PushCenter\Client\{ClientConfig, PushCenterClient, IdempotencyKey};
use PushCenter\Client\Dto\{RegisterDeviceRequest, Platform, NotifyOptions};
use PushCenter\Client\Payload\PayloadBuilder;

$client = new PushCenterClient(new ClientConfig(
    baseUrl: 'http://127.0.0.1:8080',
    apiKey: getenv('PUSHCENTER_API_KEY') ?: '',
));
```

### Регистрация устройства (middleware / эндпоинт регистрации токена)

Клиентские библиотеки приложений шлют токен на бэкенд проекта на каждом запуске;
бэкенд пробрасывает его в шлюз (регистрация идемпотентна — контракт §4.1):

```php
$client->registerDevice(new RegisterDeviceRequest(
    installId: $request->installId,       // UUID v4 установки
    deviceToken: $request->deviceToken,
    platform: Platform::Android,
    userId: $session->userId(),           // null для гостя
    locale: $request->locale,             // 'ru' / 'en-US' — фильтр to.locale
));

// логин / логаут:
$client->bindUser($installId, (string) $userId);
$client->unbindUser($installId);
```

### Уведомление после бизнес-события

```php
$payload = (new PayloadBuilder())
    ->event('booking_created', $eventId, ['bookingId' => $booking->id])
    ->deeplink('TripDetailsScreen', ['tripId' => (string) $trip->id]) // значения — только строки
    ->ui('Уфа → Казань, 3 авг в 09:30', 'Новое бронирование')
    ->build(); // валидация обязательных полей + лимит 3500 байт — до сети

$result = $client
    ->fireAndForget()   // рекомендуется для некритичных путей — см. ниже
    ->notifyUser((string) $userId, $payload, new NotifyOptions(
        collapseId: "booking-{$booking->id}",
        ttl: 3600,
        idempotencyKey: IdempotencyKey::deterministic(
            $eventId, 'booking_created', ['bookingId' => $booking->id], (string) $userId,
        ),
    ));
// $result === false — постановка не удалась (уже залогировано), бизнес-операция продолжается
```

Прочее API: `notifyInstall($installId, ...)`, `notifyTokens(TokenTarget[], ...)`
(диагностика/миграции, 1..500 токенов), `health()`, `projectHealth(deep: true)`.

## fireAndForget — почему и когда

Постановка пуша **не должна валить бизнес-операцию** проекта: упавший шлюз не повод
откатывать бронирование. `$client->fireAndForget()` возвращает копию клиента, у
которой `notify*()` при любой ошибке возвращают `false` и пишут в PSR-3-логгер
(по умолчанию `NullLogger`; передайте свой логгер в конструктор). Ошибки
регистрации/bind НЕ глушатся — это реальные проблемы интеграции, их проект должен
видеть. Для критичных путей (например, платёжные уведомления с обязательным
аудитом) используйте строгий режим по умолчанию и обрабатывайте исключения.

## Ретраи (контракт SPEC-API §5.1)

- `5xx` и сетевые ошибки/таймауты — повтор с **тем же** `idempotency_key`
  (ключ фиксируется до цикла ретраев; дедуп шлюза гасит дубль), экспоненциальный
  backoff с джиттером. Дефолт: 3 попытки, база 200мс, множитель 2, cap 5с —
  настраивается `RetryConfig`.
- `4xx` — не ретраятся никогда (`422` → `ValidationException`, `404`/`401` → `ApiException`).
- `429` — по умолчанию не ретраится: наружу летит `RateLimitedException` с
  `retryAfterSeconds`; включить повтор — `RetryConfig(retryOn429: true)`.

Иерархия исключений: `PushCenterException` ← `TransportException` (сеть),
`ApiException` (envelope шлюза: `statusCode`, `errorCode`) ←
`ValidationException` (клиентская пре-валидация со `statusCode: 0` или `422`),
`RateLimitedException`.

## idempotency_key

- `IdempotencyKey::deterministic($eventId, $eventType, $data, ...$scope)` —
  sha256 от канонизированных полей события (рекурсивный `ksort` данных,
  `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES`), тот же алгоритм, что
  `PushNotificationEvent::idempotencyKey()` в Safar. Одно бизнес-событие → один
  ключ навсегда: повторный вызов кода схлопывается окном дедупликации шлюза (≥24ч).
- `IdempotencyKey::random()` — когда каждая отправка логически новая.
- Без явного ключа клиент генерирует random-ключ на одну логическую отправку
  (все ретраи внутри неё используют его же).

## Тесты и качество

```bash
scripts/check.sh                # PHPStan level max + юнит-тесты (docker не нужен)
scripts/check.sh --integration  # + контрактные тесты против ЖИВОГО шлюза
```

Юнит-набор гоняет DTO-сериализацию на golden fixtures контракта
(`../contract/fixtures`) — принцип 3 ROADMAP. Интеграционный набор сам поднимает
`php -S` из `../gateway/public` со стаб-проектом; требуется запущенный
docker-compose Postgres/Redis шлюза (`cd ../gateway && docker compose up -d postgres redis`).
