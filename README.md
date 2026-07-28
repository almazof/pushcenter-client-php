# pushcenter/client

Тонкий PHP-клиент шлюза PushCenter: регистрация устройств, привязка устройства к
пользователю и постановка push-уведомлений в очередь по HTTP API v1 шлюза — с
ретраями по правилам контракта и без единой конкретной HTTP-зависимости.

Клиент разговаривает с сервисом PushCenter (шлюз), который сам ходит в APNs/FCM.
Сам шлюз в состав этой библиотеки не входит; ниже описано ровно то подмножество
API, которое реализует клиент, — этого достаточно, чтобы понять и использовать
библиотеку без доступа к спецификации.

## Установка

Пакет **не опубликован на Packagist**: лицензия проприетарная (см. `LICENSE`), а
Packagist рассчитан на свободно распространяемые пакеты. Сам репозиторий при этом
публичный, поэтому подключение не требует ни SSH-ключей, ни токенов в CI —
composer забирает код по HTTPS как обычный VCS-репозиторий.

Добавьте в `composer.json` проекта:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/almazof/pushcenter-client-php" }
    ]
}
```

и установите пакет:

```bash
composer require pushcenter/client:^0.1
```

Требования: **PHP >= 8.1**, `ext-json`; `ext-curl` — только если используется
встроенный `CurlTransport` (проектам со своим PSR-18-клиентом он не нужен).
Версии соответствуют тегам репозитория, `^0.1` берёт последний `v0.1.*`. До `1.0`
минорный шаг может быть ломающим — фиксируйте точную версию, если это критично.

## Требования и зависимости

- **PHP >= 8.1** — осознанно шире, чем у шлюза: клиент ставится в бэкенды разных
  проектов, и требовать от каждого из них последний PHP нельзя; 8.1 — нижняя
  граница с `readonly`-свойствами и enum, достаточная для строгих DTO.
- **Zero-dependency ядро**: в `require` только PSR-интерфейсы (`psr/http-client`,
  `psr/http-factory`, `psr/http-message`, `psr/log`) — ни одной конкретной
  HTTP-библиотеки. Транспорт на выбор:
  - `CurlTransport` (встроенный, по умолчанию) — голый ext-curl, для проектов без
    PSR-18-клиента;
  - `Psr18Transport` — адаптер под любой PSR-18 клиент проекта (Guzzle,
    symfony/http-client).

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

Мобильные приложения шлют push-токен на бэкенд проекта на каждом запуске; бэкенд
пробрасывает его в шлюз. Регистрация идемпотентна — повторный вызов с теми же
данными не создаёт дубль:

```php
$client->registerDevice(new RegisterDeviceRequest(
    installId: $request->installId,       // UUID v4 установки приложения
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
    ->event('order_created', $eventId, ['orderId' => $order->id])
    ->deeplink('OrderDetailsScreen', ['orderId' => (string) $order->id]) // значения — только строки
    ->ui('Заказ №1024 готов к выдаче', 'Новый заказ')
    ->build(); // валидация обязательных полей + лимит 3500 байт — до сети

$result = $client
    ->fireAndForget()   // рекомендуется для некритичных путей — см. ниже
    ->notifyUser((string) $userId, $payload, new NotifyOptions(
        collapseId: "order-{$order->id}",
        ttl: 3600,
        idempotencyKey: IdempotencyKey::deterministic(
            $eventId, 'order_created', ['orderId' => $order->id], (string) $userId,
        ),
    ));
// $result === false — постановка не удалась (уже залогировано), бизнес-операция продолжается
```

Прочее API: `notifyInstall($installId, ...)`, `notifyTokens(TokenTarget[], ...)`
(диагностика/миграции, 1..500 токенов), `health()`, `projectHealth(deep: true)`.

### Широковещательная рассылка по проекту

```php
use PushCenter\Client\Dto\{BroadcastFilters, BroadcastAudience, Platform};

$client->notifyBroadcast(
    $payload,
    new BroadcastFilters(          // все фильтры опциональны и комбинируются по И
        platform: Platform::Ios,
        locale: 'ru',
        appType: 'client',
        audience: BroadcastAudience::Guest,
    ),
    new NotifyOptions(idempotencyKey: $key),
);

$client->notifyBroadcast($payload);                       // вся активная база проекта
$client->notifyBroadcast($payload, new BroadcastFilters()); // то же самое, явно
```

Ответ — обычный `202 enqueued|deduplicated`: шлюз принимает ОДИН джоб и
разворачивает рассылку потоково, поэтому вызов возвращается задолго до последнего
устройства; порядок доставки не гарантируется. Ретрай с тем же
`idempotency_key` не запускает вторую рассылку. На широковещательные запросы у
шлюза действует отдельное строгое окно rate limit (дефолт 10/час) — `429`
приходит типизированным `RateLimitedException`. Фильтр `NotifyOptions::$locale`
для рассылок запрещён (это фильтр адресации `user_id`); локаль рассылки —
`BroadcastFilters(locale: ...)`.

`GET /v1/metrics` осознанно не входит в клиент: это операторский эндпоинт шлюза
(Prometheus-скрейп/мониторинг, curl) — бэкендам проектов он не нужен.

## Формат запроса и ограничения

Тело `POST /v1/notifications`, которое собирает клиент:

```jsonc
{
  "idempotency_key": "…",          // 16..128 символов [A-Za-z0-9._-], обязателен
  "to": { "user_id": "…" },        // ровно ОДИН режим адресации:
                                   // user_id | install_id | tokens[] | broadcast
  "payload": {
    "event":    { "type": "order_created", "id": "evt-1", "data": { } },
    "deeplink": { "target": "OrderDetailsScreen", "params": { "orderId": "42" } },
    "ui":       { "title": "…", "subtitle": "…", "body": "…" }
  },
  "collapse_id": "order-91",       // опционально, <= 64 печатных ASCII-символа
  "ttl": 3600                      // опционально, целое >= 0
}
```

Ключевые правила, которые клиент проверяет ДО обращения к сети (и которые шлюз
проверяет повторно — авторитет всегда за ним):

- `install_id` — строго UUID v4; `device_token` — 1..4096 символов
  `[A-Za-z0-9._:-]`.
- `payload.event.type` — `snake_case`; `payload.event` и `payload.ui` обязательны;
  `deeplink.target` обязателен, если задан сам `deeplink`.
- Все значения `deeplink.params` — только строки.
- Размер `payload` <= 3500 байт, где размер меряется точно так же, как на шлюзе:
  `strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))`.
- Режимы адресации взаимоисключающие; в клиенте это выражено отдельным методом на
  каждый режим, поэтому невалидную комбинацию нельзя даже собрать.

Ответ на успешную постановку — `202` c `{"status": "enqueued"}` либо
`{"status": "deduplicated"}`; оба означают «принято», второй — что такой
`idempotency_key` уже видели. Ошибки приходят конвертом
`{"error": {"code": "...", "message": "..."}}`.

## fireAndForget — почему и когда

Постановка пуша **не должна валить бизнес-операцию** проекта: упавший шлюз не
повод откатывать заказ. `$client->fireAndForget()` возвращает копию клиента, у
которой `notify*()` при любой ошибке возвращают `false` и пишут в PSR-3-логгер
(по умолчанию `NullLogger`; передайте свой логгер в конструктор). Ошибки
регистрации/bind НЕ глушатся — это реальные проблемы интеграции, их проект должен
видеть. Для критичных путей (например, платёжные уведомления с обязательным
аудитом) используйте строгий режим по умолчанию и обрабатывайте исключения.

## Ретраи

- `5xx` и сетевые ошибки/таймауты — повтор с **тем же** `idempotency_key`
  (ключ фиксируется до цикла ретраев; дедуп шлюза гасит дубль), экспоненциальный
  backoff с джиттером. Дефолт: 3 попытки, база 200мс, множитель 2, cap 5с —
  настраивается `RetryConfig`.
- `4xx` — не ретраятся никогда (`422` → `ValidationException`, `404`/`401` → `ApiException`).
- `429 rate_limited` — по умолчанию не ретраится: наружу летит `RateLimitedException`
  с `retryAfterSeconds` и фактическим `errorCode`; включить повтор —
  `RetryConfig(retryOn429: true)` (задержка = max(backoff, `Retry-After`)).
- `429 limit_exceeded` (потолок устройств проекта) — не ретраится **никогда**,
  даже при `retryOn429: true`: повтор не приведёт к успеху.

Иерархия исключений: `PushCenterException` ← `TransportException` (сеть),
`ApiException` (envelope шлюза: `statusCode`, `errorCode`) ←
`ValidationException` (клиентская пре-валидация со `statusCode: 0` или `422`),
`RateLimitedException`.

## idempotency_key

`IdempotencyKey::deterministic($eventId, $eventType, $data, ...$scope)` считает

```
sha256(implode('|', [$eventId, $eventType, ...$scope, $json]))
```

где `$json` — это `$data`, нормализованные **рекурсивным** `ksort` и закодированные
`json_encode(..., JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`. Одно
бизнес-событие → один ключ навсегда: повторный вызов кода схлопывается окном
дедупликации шлюза (>= 24ч). Формула зафиксирована тестом: если ключ считает и
другая сторона (отложенная джоба, сервис на другом языке), она обязана повторить
эти шаги шаг в шаг — иначе дедуп молча перестанет работать.

- `IdempotencyKey::random()` — когда каждая отправка логически новая.
- Без явного ключа клиент генерирует random-ключ на одну логическую отправку
  (все ретраи внутри неё используют его же).

## Спецификация контракта

В докблоках кода встречаются ссылки вида `SPEC-API §4.4` и `SPEC-PAYLOAD §1` —
это разделы спецификации HTTP API v1 и формата payload, которая живёт в отдельном
репозитории контракта (`SPEC-API.md`, `SPEC-PAYLOAD.md`, JSON Schema и golden
fixtures). Контракт — источник истины: клиент ему следует, а не наоборот.

Сам контракт в этот репозиторий не входит и публично не выложен. Это не мешает
пользоваться библиотекой: всё, что нужно для интеграции, описано здесь, в README,
а ссылки оставлены как есть — они точно адресуют правило для тех, у кого доступ к
спецификации есть (интеграторам он выдаётся по запросу), и для сверки при
изменении контракта. Копия golden fixtures, на которых гоняются тесты, лежит в
`tests/fixtures/`.

## Тесты и качество

```bash
scripts/check.sh                                       # PHPStan level max + юнит-тесты
scripts/check.sh --contract-drift=/path/to/contract    # + сверка фикстур с контрактом
scripts/check.sh --integration                         # + тесты против ЖИВОГО шлюза
```

Юнит-набор гоняет DTO-сериализацию на golden fixtures из `tests/fixtures/`;
дефолтный прогон самодостаточен и не требует ничего, кроме PHP и composer. Но он
проверяет только то, что клиент соблюдает ЭТИ копии, — не то, что копии всё ещё
совпадают с контрактом. Для второго нужен `--contract-drift`: он перезапускает
набор против чекаута контракта и сверяет наборы имён фикстур в обе стороны.
Что именно гарантирует каждый режим (и что не гарантирует ни один) — таблица в
`tests/fixtures/README.md`.

Интеграционный набор поднимает `php -S` из локальной копии шлюза и требует его
Postgres/Redis; сам шлюз в этот репозиторий не входит, поэтому этот набор
запускается только там, где он есть: `PUSHCENTER_GATEWAY_DIR` обязателен и
дефолта не имеет, остальные параметры — `PUSHCENTER_TEST_*` в `phpunit.xml.dist`.

CI в репозитории нет — `scripts/check.sh` запускается вручную перед коммитом.
Это осознанный техдолг, зафиксированный в `CLAUDE.md`.
