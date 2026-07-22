# PushCenter client-php — правила репозитория

## Контракт — источник истины

- API и payload определяются ТОЛЬКО `../contract/` (SPEC-API.md, SPEC-PAYLOAD.md, schemas/, fixtures/).
- Тесты сериализации обязаны гоняться на golden fixtures из `../contract/fixtures` (ROADMAP, принцип 3).
- Клиентская валидация — пре-флайт-подмножество схем контракта; авторитет — JSON Schema шлюза. Ослаблять серверные правила клиентом запрещено, ужесточать сверх контракта — тоже.
- Изменение поведения клиента без изменения контракта — запрещено; сначала правится контракт, потом реализация.

## Архитектура и стиль

- PHP 8.1+ (шире шлюза — клиент ставится в разные проекты), `declare(strict_types=1)` в каждом файле, PSR-4 `PushCenter\Client\` → `src/`.
- **Zero-dependency ядро**: в `require` только `psr/*`-интерфейсы. Конкретные HTTP-библиотеки не добавляются; встроенный транспорт — только ext-curl (`CurlTransport`), PSR-18 — через `Psr18Transport`.
- Все DTO immutable (`readonly`-свойства), валидируются в конструкторе; невалидный запрос не доходит до сети.
- Побочные эффекты за интерфейсами: `TransportInterface` (сеть), `SleeperInterface` (время), `LoggerInterface` (PSR-3). Тесты используют фейки из `tests/Support/`, сетевые вызовы в юнит-тестах запрещены.

## Контрактные инварианты (не ломать)

- Ретрай ТОЛЬКО с тем же `idempotency_key` (SPEC-API §5.1): ключ фиксируется до цикла ретраев. Новый ключ при ретрае — нарушение контракта.
- `4xx` (кроме 429) не ретраится; `429` не ретраится по умолчанию (`RateLimitedException` + `retryAfterSeconds`).
- Замер payload — тем же алгоритмом, что у шлюза: `strlen(json_encode($p, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))`, лимит 3500 байт.
- `IdempotencyKey::deterministic()` — паритет с Safar `PushNotificationEvent::idempotencyKey()` (рекурсивный ksort + sha256); менять алгоритм нельзя (ломает дедуп у живых отправителей).
- `fireAndForget` покрывает только `notify*`; register/bind/unbind всегда бросают.

## Качество (обязательно перед коммитом)

- `scripts/check.sh` — единая команда: PHPStan level max (0 ошибок) + юнит-тесты; `--integration` — контрактные тесты против живого шлюза (`php -S` из `../gateway/public`, docker-compose postgres/redis шлюза).
- В файлы `../gateway/` и `../contract/` из этого репозитория НЕ пишем — только чтение и запуск.
- Коммиты — conventional commits, на английском.
