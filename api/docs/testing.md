# Тесты

## Быстрый старт

```bash
# Полный прогон
vendor/bin/phpunit

# Только Feature / Unit
vendor/bin/phpunit --testsuite=Feature
vendor/bin/phpunit --testsuite=Unit

# Один класс / один тест
vendor/bin/phpunit --filter=AuthControllerTest
vendor/bin/phpunit --filter=test_login_returns_token_and_user_data
```

## Покрытие

```bash
# Три итоговые цифры
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text=php://stdout 2>&1 \
  | grep -E "Classes:|Methods:|Lines:"

# HTML-отчёт
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-html=build/coverage

# По одному классу
XDEBUG_MODE=coverage vendor/bin/phpunit --filter=AuthControllerTest \
  --coverage-text=php://stdout 2>&1 | grep -A1 "AuthController"
```

## Остановка и диагностика

```bash
# Остановиться на первой ошибке
vendor/bin/phpunit --stop-on-error
vendor/bin/phpunit --stop-on-failure

# Подробный вывод
vendor/bin/phpunit --debug
vendor/bin/phpunit --testdox

# Случайный порядок (проверка изоляции)
vendor/bin/phpunit --order-by=random
```

## Важно про метрику Methods

PCOV и Xdebug **занижают Methods для контроллеров**: Laravel вызывает их через `$container->call()`, и coverage driver не помечает методы «вызванными», даже если строки внутри исполнены.

- **Lines** — надёжная метрика.
- **Classes** — считается корректно.
- **Methods** — для контроллеров занижена, для сервисов/моделей/Request корректна.

**Для CI-гейта используем Classes и Lines.** Methods — информативно.

## Конфигурация покрытия

В `phpunit.xml`:

```xml
<source>
    <include>
        <directory>app</directory>
    </include>
    <exclude>
        <directory suffix=".php">app/Console/Commands</directory>
        <directory suffix=".php">app/Providers</directory>
        <directory suffix=".php">app/Exceptions</directory>
    </exclude>
</source>
```

## Полезные заметки

### Кеш PHPUnit

Перед запуском покрытия иногда стоит сбросить:

```bash
rm -rf .phpunit.cache
```

### Память

Если падает с `Allowed memory size exhausted`:

```bash
php -d memory_limit=512M vendor/bin/phpunit
```

### Медленные тесты

Тест полного импорта XLSX обрабатывает 52 983 строки и относится к медленным.
Это нормально для проверки производственного справочника.

## Соглашения по тестам

- **Один класс = один контроллер/сервис.** `app/Http/Controllers/AuthController.php` ↔ `tests/Feature/Http/Controllers/AuthControllerTest.php`.
- **Название теста — что проверяем.** `test_login_rejects_wrong_password`, а не `test_login_2`.
- **Фикстуры внутри класса.** `makeUser`, `makeRequest` — `private` методы класса.
- **`DatabaseTransactions`** — для изоляции. Не использовать `static` в тестах.
- **`Storage::fake('media')`** в `setUp()` для тестов с файлами.
- **`travelTo()`** для детерминированного времени.
- **`Origin` header** для `/api/login` — middleware `EnsureLoginOrigin` пропускает только `http://localhost:5173`.

## CI

GitLab CI запускает полный тестовый набор на PHP 8.4 и PostgreSQL 15,
формирует текстовый отчёт, Clover XML и JUnit XML. Гейт Этапа 0:

- Classes ≥ 60%;
- Lines ≥ 85%;
- Methods — информативно и не участвует в блокировке pipeline.

Проверка порогов выполняется командой:

```bash
php scripts/coverage-gate.php build/coverage.txt 60 85
```

Финальный Definition of Done остаётся строже: Classes ≥ 75%, Lines ≥ 88%.
До достижения финального этапа эти значения не используются как CI-гейт.

## CI (GitLab)

Пайплайн в `.gitlab-ci.yml`. Два job'а:

### lint:phpstan
- Образ: `php:8.4-cli-alpine`;
- Покрытие: только PHPStan (без тестов);
- Команда: `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`.

### api:tests
- Образ: `php:8.4-cli-alpine`;
- Service: `postgres:16-alpine` как `postgres`;
- БД: `msto_test`, схема `msto_test`;
- Composer: с `GITHUB_TOKEN` через `composer config --global github-oauth.github.com "$GITHUB_TOKEN"`;
- Покрытие: **PCOV не используется**. Baseline через baseline.sh на проде;
- Команда: `php -d memory_limit=1G vendor/bin/phpunit --log-junit=build/junit.xml`.

### Требования к `GITHUB_TOKEN`

Переменная должна быть **без флага `Protected`** в GitLab CI/CD Variables,
иначе MR из незащищённых веток не получит токен, и Composer упадёт с
`Could not authenticate against github.com`.

### Требования к `composer.lock`

Версия PHP в CI и локально должна совпадать (8.4), иначе
`composer install` может упасть из-за platform requirements.
