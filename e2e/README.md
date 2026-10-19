# Сквозная браузерная проверка AUTOREMHUB

Скрипты работают с **запущенными** API и Front на отдельной тестовой БД
(не на production). Они создают демонстрационные записи и заявки.

| Файл | Что делает |
|---|---|
| `seed.py` | через API УК создаёт контакт и водителя «Альфа», ТС Toyota Camry с тарифами 2400/3200/1900, три демо-работы с общей нормой, поставщика и две ЗЧ. Это тестовые данные, не восстановленный рабочий справочник. |
| `route.mjs` | Playwright: Заказчик создаёт RP-000001 → УК назначает СТО и смету → СТО меняет смету → УК возвращает с обязательным комментарием → СТО повторно → УК согласует → Заказчик подтверждает → акт приёма AP-RP-000001 → диагностика, ЗЧ «Заказана/Получена» → завершение и заказ-наряд → выдача → счёт → оплата → проверка УК → «Заявка закрыта». Скриншоты в `shots/`. |
| `probe.py` | 27 прямых API-проверок прав и отрицательных случаев (скрытие закупочных цен, чужая СТО, 409 по ревизии, отказ СТО, STATION_PREVIOUSLY_DECLINED, блокировки, SESSION_ACTIVE, удаление черновиков, VIN, дубль варианта ТС, расчёт 1,25 × 2400 × 2). Запускать после `route.mjs`. |

## Подготовка

Пустая БД, затем (ID организаций на чистой БД: 1 — УК, 2 — «Альфа», 4 — «КУБ»):

```bash
cd api
php artisan migrate --force
php artisan sto:tenant-create 'ООО «Авторемхаб»'
php artisan sto:test-cabinets-create --tenant=1
php artisan sto:reference-seed 1
php artisan catalog:seed-part-manufacturers
php artisan sto:vehicle-reference-import database/data/vehicle_reference_20261014.xlsx
# пароль Demo-pass-2026 вводится дважды в консоли
php artisan sto:create-user uk.ivanov 1 --last-name=Иванов --first-name=Иван --phone='+7 (999) 100 00 01'
php artisan sto:create-user alfa.petrova 2 --last-name=Петрова --first-name=Анна --phone='+7 (999) 200 00 01'
php artisan sto:create-user alfa.sidorov 2 --last-name=Сидоров --first-name=Олег --phone='+7 (999) 200 00 02'
php artisan sto:create-user kub.master 4 --last-name=Кузнецов --first-name=Михаил --phone='+7 (999) 300 00 01'
php artisan sto:create-user kub.second 4 --last-name=Белов --first-name=Игорь --phone='+7 (999) 300 00 03'
php artisan sto:create-user oval.master 5 --last-name=Орлов --first-name=Денис --phone='+7 (999) 300 00 02'
```

## Запуск

```bash
cd e2e
python3 seed.py
npm i -D playwright   # или укажите PLAYWRIGHT_MODULE на установленный пакет
node route.mjs
python3 probe.py
```

Переменные: `E2E_FRONT_URL` (по умолчанию http://localhost:5173/),
`E2E_API_URL` (http://127.0.0.1:8000/api), `E2E_ORIGIN`, `PLAYWRIGHT_MODULE`,
`CHROMIUM_PATH`, `E2E_SHOTS`. Повторный прогон требует чистой БД: скрипт ищет
заявку RP-000001.
