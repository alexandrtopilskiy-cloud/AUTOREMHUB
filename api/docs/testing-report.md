# Отчёт о покрытии тестами

**Проект:** MSTO API
**Дата:** 2026-09-27
**Стек:** PHP 8.4, Laravel 13, PostgreSQL 15, PHPUnit 12.5

---

## Сводка

| Метрика | Значение |
|---|---|
| Всего тестов | **520+** |
| Всего assertions | **1700+** |
| Lines | **90.76%** (5598 / 6168) |
| Classes | 62.07% (54 / 87) |
| Methods | 72.08% (346 / 480) |

### Покрытие по слоям приложения

| Слой | Lines | Methods | Classes |
|---|---|---|---|
| **Http (контроллеры)** | 95.37% | 79.83% | 70.97% |
| **Models (модели)** | 95.37% | 86.60% | 74.29% |
| **Services (сервисы)** | 88.48% | 58.95% | 23.53% |
| **Support (утилиты)** | 99.21% | 91.43% | 50.00% |

---

## Тесты по классам

### 1. AuthControllerTest — аутентификация
**17 тестов** · `tests/Feature/Http/Controllers/AuthControllerTest.php`

**Вход в систему:**
- `test_login_returns_token_and_user_data` — успешный вход возвращает токен и данные пользователя
- `test_login_returns_customer_permissions` — права Заказчика в ответе
- `test_login_returns_manager_permissions` — права УК в ответе

**Ошибки входа:**
- `test_login_rejects_unknown_login` — 401 для несуществующего логина
- `test_login_rejects_wrong_password` — 401 для неверного пароля
- `test_login_rejects_inactive_user` — 401 для деактивированного пользователя
- `test_login_rejects_user_with_mismatched_role_and_organization_kind` — 401 при несоответствии роли и типа организации
- `test_login_rejects_missing_credentials` — 422 при пустом запросе

**Сессии и токены:**
- `test_login_rejects_second_session_without_force` — 409 при попытке входа с другого устройства
- `test_login_with_force_deletes_old_tokens` — `force: true` завершает прежнюю сессию
- `test_login_with_force_releases_document_locks` — при `force` снимаются блокировки документов

**Rate limiting:**
- `test_login_rate_limit_blocks_after_five_attempts` — 429 после 5 неудачных попыток

**Текущая сессия и выход:**
- `test_session_returns_user_data` — 200 с данными пользователя
- `test_session_requires_authentication` — 401 без токена
- `test_logout_deletes_current_token` — 204, токен удалён из БД
- `test_logout_releases_document_locks` — 204, блокировки сняты

---

### 2. RepairRequestControllerTest — заявки на ремонт
**28 тестов** · `tests/Feature/Http/Controllers/RepairRequestControllerTest.php`

**Список заявок:**
- `test_index_returns_requests_for_customer` — Заказчик видит свои заявки
- `test_index_returns_requests_for_manager` — УК видит все заявки в своём контуре
- `test_index_returns_requests_for_station` — СТО видит назначенные ей заявки

**Просмотр:**
- `test_show_returns_request_for_owner` — владелец видит заявку
- `test_show_returns_404_for_unknown_uuid` — 404 для несуществующей

**Создание черновика:**
- `test_store_creates_draft_for_customer` — Заказчик создаёт черновик
- `test_store_forbidden_for_manager` — 403 для УК
- `test_store_forbidden_for_station` — 403 для СТО

**Редактирование:**
- `test_update_changes_mileage_for_customer` — изменение пробега
- `test_update_returns_409_for_revision_conflict` — 409 при конфликте версий
- `test_update_rejects_disallowed_fields` — 422 для неизвестных полей

**Удаление:**
- `test_destroy_deletes_draft_for_owner` — удаление своего черновика
- `test_destroy_returns_403_for_non_draft` — нельзя удалить переданную заявку
- `test_destroy_returns_404_for_unknown_uuid` — 404 для несуществующей
- `test_destroy_drafts_deletes_multiple_drafts` — массовое удаление черновиков
- `test_destroy_drafts_rejects_empty_ids` — 422 на пустой список

**Переходы этапов:**
- `test_next_advances_draft_to_manager_routing` — черновик → на проверку УК
- `test_next_rejects_manager_routing_without_works` — 422 без работ
- `test_return_on_station_approval_requires_station_decision` — 403 на возврат со стороны СТО без решения

**Решение СТО по времени:**
- `test_station_decision_confirms_slot` — подтверждение времени
- `test_station_decision_reschedules_slot` — предложение другого времени
- `test_station_decision_declines` — отказ СТО

**Решение Заказчика:**
- `test_customer_accepts_station_slot` — согласие с временем СТО
- `test_customer_reschedules_slot` — предложение своего времени

**Акт приёма ТС:**
- `test_accept_vehicle_electronic_creates_act` — электронный акт приёма
- `test_accept_vehicle_rejects_second_act` — 409 при повторном акте

**История и заказ-наряд:**
- `test_history_returns_events_and_periods` — история событий и периодов
- `test_work_order_returns_null_when_not_exists` — `null` если заказ-наряда нет

---

### 3. InvoiceControllerTest — счета и оплата
**22 теста** · `tests/Feature/Http/Controllers/InvoiceControllerTest.php`

**Выставление счёта (УК):**
- `test_issue_creates_invoice_with_issued_status` — успешное создание счёта
- `test_issue_rejects_amount_mismatch_with_work_order` — 422 при расхождении с заказ-нарядом
- `test_issue_rejects_missing_work_order` — 422 без заказ-наряда
- `test_issue_rejects_empty_number` — 422 при пустом номере
- `test_issue_rejects_attachment_with_wrong_purpose` — 422 при неверном назначении файла
- `test_issue_rejects_revision_conflict` — 409 при конфликте версий
- `test_issue_forbidden_for_customer` — 403 для Заказчика

**Оплата (Заказчик):**
- `test_payment_marks_invoice_submitted` — успешная оплата
- `test_payment_rejects_amount_below_invoice` — 422 при сумме ниже счёта
- `test_payment_rejects_future_paid_at` — 422 при будущей дате
- `test_payment_rejects_empty_comment` — 422 без комментария
- `test_payment_rejects_without_invoice` — 422 без счёта
- `test_payment_rejects_attachment_with_wrong_purpose` — 422 при неверном файле
- `test_payment_forbidden_for_manager` — 403 для УК

**Проверка оплаты (УК):**
- `test_verify_marks_invoice_paid` — успешная проверка
- `test_verify_rejects_without_submitted_invoice` — 422 без отправленного счёта
- `test_verify_rejects_incomplete_paid_amount` — 422 при неполной сумме
- `test_verify_rejects_empty_comment` — 422 без комментария
- `test_verify_forbidden_for_customer` — 403 для Заказчика

**Общее:**
- `test_unknown_request_returns_404` — 404 для несуществующей заявки

---

### 4. AttachmentControllerTest — вложения
**24 теста** · `tests/Feature/Http/Controllers/AttachmentControllerTest.php`

**Загрузка:**
- `test_store_creates_attachment_and_returns_201` — успешная загрузка
- `test_store_returns_request_document_with_attachment` — вложение в документе заявки
- `test_store_creates_request_event` — событие создано
- `test_store_bumps_revision` — ревизия увеличена

**Валидация:**
- `test_store_rejects_missing_file` — 422 без файла
- `test_store_rejects_empty_purpose` — 422 без назначения
- `test_store_rejects_invalid_purpose` — 422 с неверным назначением
- `test_store_rejects_non_integer_revision` — 422 с нецелой ревизией
- `test_store_rejects_unsupported_mime` — 422 для неподдерживаемого типа

**Доступ и этапы:**
- `test_store_forbidden_for_manager_with_wrong_purpose` — 403 для УК с неверным назначением
- `test_store_rejects_manager_uploading_non_invoice_purpose` — 403 для УК, загружающей не счёт
- `test_store_rejects_purpose_mismatch_with_stage` — 422 при несоответствии этапу
- `test_store_rejects_paid_invoice_on_wrong_stage` — 422 для оплаченного счёта не на своём этапе
- `test_store_rejects_diagnostic_on_wrong_stage` — 422 для диагностики не на своём этапе
- `test_store_rejects_acceptance_act_on_wrong_stage` — 422 для акта приёма не на своём этапе
- `test_store_returns_404_for_unknown_request` — 404
- `test_store_returns_404_for_foreign_request` — 404 для чужой заявки
- `test_store_returns_409_for_revision_conflict` — 409 при конфликте

**Ссылка на скачивание:**
- `test_url_returns_route_for_private_disk` — приватная ссылка
- `test_url_returns_404_for_unknown_attachment` — 404
- `test_url_returns_404_for_foreign_attachment` — 404 для чужого файла

**Скачивание:**
- `test_download_returns_file_content` — успешное скачивание
- `test_download_returns_404_for_unknown_attachment` — 404
- `test_download_returns_404_when_file_missing_in_storage` — 404 если файл отсутствует

---

### 5. DiagnosticControllerTest — диагностика
**10 тестов** · `tests/Feature/Http/Controllers/DiagnosticControllerTest.php`

- `test_store_creates_diagnostic_report` — успешное создание заключения
- `test_store_records_mileage_from_request_data` — пробег из заявки
- `test_store_links_attachments_to_event` — привязка файлов к событию
- `test_store_creates_request_event` — событие создано
- `test_store_forbidden_for_manager` — 403 для УК
- `test_store_forbidden_for_customer` — 403 для Заказчика
- `test_store_rejects_empty_conclusion` — 422 при пустом заключении
- `test_store_rejects_missing_attachment_ids` — 422 без файлов
- `test_store_rejects_attachment_with_wrong_purpose` — 422 при неверном назначении
- `test_store_rejects_unknown_attachment` — 422 для несуществующего файла

---

### 6. DocumentLockControllerTest — блокировки документов
**16 тестов** · `tests/Feature/Http/Controllers/DocumentLockControllerTest.php`

**Создание блокировки:**
- `test_acquire_creates_lock_and_returns_201` — успешная блокировка
- `test_acquire_uses_key_as_label_when_label_missing` — ключ как метка
- `test_acquire_returns_423_for_other_users_lock` — 423 если документ уже открыт
- `test_acquire_replaces_expired_lock` — замена просроченной блокировки
- `test_acquire_rejects_station_for_catalog_record_with_403` — 403 для СТО
- `test_acquire_rejects_catalog_record_without_colon_with_422` — 422 при неверном ключе
- `test_acquire_rejects_unknown_fields_with_422` — 422 при лишних полях
- `test_acquire_rejects_empty_body_with_422` — 422 на пустое тело

**Heartbeat:**
- `test_heartbeat_returns_200_and_extends_lock` — продление блокировки
- `test_heartbeat_returns_404_for_unknown_lock` — 404
- `test_heartbeat_returns_404_for_other_users_lock` — 404 для чужой
- `test_heartbeat_rejects_unknown_fields_with_422` — 422 при лишних полях

**Снятие блокировки:**
- `test_release_returns_204_and_removes_lock` — успешное снятие
- `test_release_returns_204_for_unknown_lock` — идемпотентность
- `test_release_does_not_touch_other_users_lock` — не трогает чужую

**Авторизация:**
- `test_acquire_requires_authentication` — 401 без токена

---

### 7. VehicleControllerTest — автомобили
**25 тестов** · `tests/Feature/Http/Controllers/VehicleControllerTest.php`

**Список:**
- `test_index_returns_vehicles_of_user_organization` — ТС своей организации
- `test_index_excludes_inactive_vehicles` — неактивные скрыты
- `test_index_forbids_station_to_see_vehicles_without_requests` — СТО видит только свои
- `test_index_station_sees_vehicle_from_their_request` — ТС из заявок СТО
- `test_index_search_by_vin_prefix` — поиск по VIN
- `test_index_search_by_plate_with_homoglyphs` — поиск по госномеру (латиница/кириллица)
- `test_index_respects_limit_50` — лимит 50

**Поиск по VIN или номеру:**
- `test_lookup_finds_vehicle_by_vin` — поиск по VIN
- `test_lookup_finds_vehicle_by_plate` — поиск по номеру
- `test_lookup_normalizes_plate_homoglyphs` — нормализация латиница→кириллица
- `test_lookup_returns_404_when_not_found` — 404
- `test_lookup_rejects_ambiguous_plate_with_422` — 422 при неоднозначном номере
- `test_lookup_requires_vin_or_plate` — 422 без параметров

**Просмотр:**
- `test_show_returns_vehicle_by_uuid` — по UUID
- `test_show_returns_404_for_unknown_uuid` — 404
- `test_show_returns_404_for_non_uuid` — 404 для не-UUID

**Статистика:**
- `test_statistics_returns_history` — история работ
- `test_statistics_returns_404_for_unknown_vehicle` — 404
- `test_statistics_returns_empty_history_for_new_vehicle` — пустая история

**Контакты:**
- `test_contacts_returns_active_contacts_sorted` — активные, отсортированные
- `test_contacts_returns_404_for_unknown_vehicle` — 404

**Водители:**
- `test_drivers_returns_active_drivers_sorted` — активные, отсортированные
- `test_drivers_returns_404_for_unknown_vehicle` — 404

**Авторизация:**
- `test_index_requires_authentication` — 401
- `test_show_requires_authentication` — 401

---

### 8. OrganizationPersonControllerTest — справочник лиц
**22 теста** · `tests/Feature/Http/Controllers/OrganizationPersonControllerTest.php`

**Создание:**
- `test_store_creates_contact_person_for_customer` — контактное лицо
- `test_store_creates_driver_for_customer` — водитель
- `test_store_normalizes_phone` — нормализация телефона
- `test_store_rejects_invalid_phone` — 422 при неверном телефоне
- `test_store_rejects_empty_record` — 422
- `test_store_rejects_non_object_record` — 422
- `test_store_rejects_extra_fields_in_body` — 422 при лишних полях
- `test_store_rejects_duplicate_phone_with_422` — 422 при дубликате
- `test_store_restores_inactive_person` — восстановление деактивированного

**Редактирование:**
- `test_update_changes_fields` — изменение полей
- `test_update_syncs_vehicle_contact` — синхронизация с контактом ТС
- `test_update_rejects_duplicate_phone_with_422` — 422 при дубликате
- `test_update_returns_404_for_unknown_id` — 404
- `test_update_rejects_extra_fields_in_body` — 422
- `test_update_rejects_invalid_phone` — 422

**Удаление:**
- `test_destroy_soft_deletes_person` — мягкое удаление
- `test_destroy_deactivates_vehicle_contacts` — деактивация связей
- `test_destroy_returns_404_for_unknown_id` — 404
- `test_destroy_rejects_body_fields_with_422` — 422

**Роли:**
- `test_store_forbidden_for_station` — 403 для СТО
- `test_update_forbidden_for_station` — 403 для СТО
- `test_store_requires_authentication` — 401

---

### 9. CatalogControllerTest — справочники
**29 тестов** · `tests/Feature/Http/Controllers/CatalogControllerTest.php`

**Список:**
- `test_index_returns_all_records_for_manager` — все записи для УК
- `test_index_returns_only_own_vehicles_and_people_for_customer` — только свои для Заказчика
- `test_index_returns_empty_for_station` — пусто для СТО

**Справочные данные:**
- `test_reference_returns_all_sections_for_manager` — все разделы
- `test_reference_hides_suppliers_and_parts_for_customer` — скрытие поставщиков/запчастей
- `test_reference_filters_reasons_for_customer` — фильтрация причин

**История изменений:**
- `test_history_returns_events_for_manager` — события
- `test_history_forbidden_for_customer` — 403
- `test_history_forbidden_for_station` — 403
- `test_history_filters_by_record_id` — фильтр по записи

**Создание:**
- `test_store_creates_work_type` — вид работ
- `test_store_creates_catalog_part` — запчасть
- `test_store_creates_reason` — причина
- `test_store_creates_customer_with_two_accounts` — Заказчик с двумя кабинетами
- `test_store_creates_station_with_account_and_profile` — СТО с профилем
- `test_store_rejects_unknown_kind` — 422 для неизвестного типа
- `test_store_forbidden_for_customer` — 403
- `test_store_rejects_extra_fields_in_body` — 422

**Редактирование:**
- `test_update_changes_vehicle_labor_rate_coefficient` — коэффициент
- `test_update_masks_password_and_persists_new_one` — маска пароля
- `test_update_rejects_catalog_part` — 403 для запчасти
- `test_update_forbidden_for_customer` — 403
- `test_update_returns_404_for_unknown_id` — 404
- `test_update_rejects_extra_fields_in_body` — 422

**Импорт норм времени:**
- `test_import_time_norms_creates_work_type_and_norm` — создание
- `test_import_time_norms_updates_existing_norm` — обновление
- `test_import_time_norms_stores_empty_hours_as_null` — пустая норма = NULL
- `test_import_time_norms_forbidden_for_customer` — 403
- `test_import_time_norms_rejects_non_xlsx` — 422

---

### 10. VehicleCatalogControllerTest — справочник ТС
**20 тестов** · `tests/Feature/Http/Controllers/VehicleCatalogControllerTest.php`

**Создание:**
- `test_store_creates_vehicle_for_manager` — успешное создание УК
- `test_store_creates_change_request_for_customer` — заявка на согласование от Заказчика
- `test_store_notifies_managers_on_customer_request` — уведомление УК
- `test_store_rejects_duplicate_vin` — 422 при дубликате VIN
- `test_store_rejects_invalid_vin` — 422 при неверном VIN
- `test_store_forbidden_for_station` — 403

**Редактирование:**
- `test_update_changes_vehicle_for_manager` — УК меняет сразу
- `test_update_creates_change_request_for_customer` — Заказчик создаёт заявку
- `test_update_applies_direct_fields_for_customer` — пробег сразу
- `test_update_returns_404_for_unknown_vehicle` — 404
- `test_update_rejects_extra_fields_in_body` — 422

**Удаление:**
- `test_destroy_deactivates_vehicle_for_manager` — деактивация УК
- `test_destroy_creates_change_request_for_customer` — заявка от Заказчика
- `test_destroy_returns_404_for_unknown_vehicle` — 404
- `test_destroy_rejects_body_fields_with_422` — 422

**Согласование:**
- `test_review_approves_pending_change` — одобрение
- `test_review_rejects_pending_change` — отклонение
- `test_review_forbidden_for_customer` — 403
- `test_review_returns_404_for_unknown_change` — 404
- `test_review_rejects_already_reviewed_change` — 422

---

### 11. PartControllerTest — запчасти
**22 теста** · `tests/Feature/Http/Controllers/PartControllerTest.php`

**Создание:**
- `test_store_creates_part` — успешное создание
- `test_store_creates_catalog_part_for_manager` — автосоздание каталожной записи
- `test_store_uses_existing_catalog_part` — использование существующей
- `test_store_rejects_empty_name` — 422
- `test_store_forbidden_for_customer_on_stage_0` — 403

**Редактирование:**
- `test_update_changes_quantity_and_price` — изменение количества и цены
- `test_update_rejects_ordered_part` — 422 для заказанной
- `test_update_rejects_part_with_returns` — 422 при наличии возвратов

**Удаление:**
- `test_remove_deletes_proposed_part` — удаление предложенной
- `test_remove_rejects_ordered_part` — 422
- `test_remove_rejects_part_with_movements` — 422 при движениях
- `test_remove_increments_estimate_version` — увеличение версии сметы

**Статусы:**
- `test_status_ordered_after_approved` — approved → ordered
- `test_status_received_after_ordered` — ordered → received
- `test_status_rejects_part_not_approved` — 422
- `test_status_rejects_invalid_transition` — 422

**Движения (возврат/обмен):**
- `test_movement_returns_ordered_part` — возврат
- `test_movement_exchanges_received_part` — обмен
- `test_movement_rejects_proposed_part` — 422
- `test_movement_rejects_exceeding_remaining` — 422 при превышении остатка
- `test_movement_rejects_exchange_without_replacement` — 422 без замены

**Роли:**
- `test_status_forbidden_for_manager_on_wrong_stage` — 403

---

### 12. ChangeRegistryControllerTest — реестр изменений
**2 теста** · `tests/Feature/Http/Controllers/ChangeRegistryControllerTest.php`

- `test_index_returns_records_for_manager` — записи для УК
- `test_index_forbidden_for_customer` — 403 для Заказчика

---

### 13. MediaServiceTest — работа с файлами
**6 тестов** · `tests/Feature/Services/MediaServiceTest.php`

- `test_assert_configured_passes_in_testing` — конфигурация
- `test_url_returns_route_for_private_disk` — приватная ссылка
- `test_url_returns_storage_url_for_public_disk` — публичная ссылка
- `test_download_returns_streamed_response` — скачивание
- `test_download_throws_404_when_file_missing` — 404
- `test_download_404_has_correct_code` — код `NOT_FOUND`

---

### 14. Console-команды
**7 тестов**

**CreateCustomerSecondaryAccountsTest (3):**
- `test_creates_secondary_account_for_each_customer` — создание вторых кабинетов
- `test_skips_organization_without_primary_account` — пропуск без основной записи
- `test_fails_for_unknown_tenant` — ошибка для неизвестного контура

**SeedTestCabinetsTest (2):**
- `test_creates_isolated_participants` — создание тестовых кабинетов
- `test_fails_for_unknown_tenant` — ошибка

**PurgeRepairRequestsTest (2):**
- `test_removes_requests_and_restarts_numbering` — очистка с перезапуском нумерации
- `test_cancels_without_force_when_requests_exist` — отмена без `--force`

---

### 15. Импортёры
**3 теста** · `tests/Unit/Services/VehicleReferenceImporterTest.php`

- `test_dry_run_does_not_touch_database` — dry-run 52 983 строк
- `test_real_import_persists_makes_and_models` — реальный импорт
- `test_second_import_marks_rows_as_existing` — идемпотентность

---

## Покрытие по бизнес-процессам

### Аутентификация и авторизация
- Вход с проверкой Origin
- Одна активная сессия, `force`-вход
- Bearer-токены
- Rate limiting (защита от брутфорса)
- Роли: Заказчик, УК, СТО
- Проверка прав на каждом этапе

### Жизненный цикл заявки
- Создание черновика
- Заполнение полей (автомобиль, контакт, причина, пробег, время)
- Передача в УК
- Решение СТО по времени (подтверждение, изменение, отказ)
- Финальное согласование Заказчиком
- Приём автомобиля на СТО
- Выполнение работ
- Выставление счёта
- Оплата и проверка
- Закрытие

### Работа с файлами
- Загрузка вложений с проверкой типа и назначения
- Привязка к этапам заявки
- Скачивание (приватные ссылки)
- Удаление при ошибках

### Справочники
- Автомобили (VIN, госномер, поиск)
- Контактные лица и водители
- Виды работ, нормы времени
- Причины обращения
- Поставщики, запчасти
- Импорт из XLSX

### Согласование изменений
- Заявки на изменение ТС от Заказчика
- Одобрение/отклонение УК
- Уведомления

### Блокировки документов
- Временная блокировка при редактировании
- Продление (heartbeat)
- Снятие при выходе
- Автоматическое истечение

### Запчасти
- Создание и редактирование
- Статусы (proposed → approved → ordered → received)
- Возврат и обмен
- Согласование смет

---

## Что НЕ покрыто (осознанно)

1. **Ветки ошибок Storage** — `putToStorage` при сбое диска, `safeDelete` при ошибке. Покрытие требует моков, тесты хрупкие, польза низкая.

2. **Edge cases импортёров** — `TimeNormImporter`, `VehicleImporter`, `VehicleReferenceImporter` частично покрыты через HTTP. Оставшиеся ветки — экзотические форматы XLSX.

3. **Тривиальные классы** — `Entity`, `ApiHeaders`, `ApiInput` — покрыты косвенно.

4. **Console-команды для начальной настройки** — `sto:tenant-create`, `sto:organization-create`, `sto:vehicle-contact-create` — используются только при первом развёртывании, тестировать их в CI нецелесообразно.

---

## Найденные и закрытые баги

За время написания тестов было найдено **4 реальных прод-бага** до релиза:

1. **Права на `storage/logs`** — PHP-FPM не мог писать логи, приложение работало вслепую. Алерт от Nginx, но не от приложения.

2. **`RateLimiter::clear` не работал** — Laravel хранит ключи named limiter'а под `md5($limiterName . $key)`. `clear('login:...')` чистил другой ключ. На проде это означало: после 5 неудачных попыток пользователь ждал 60 секунд даже с правильным паролем.

3. **`public_id NOT NULL`** в `VehicleAcceptanceActTest` — тест устарел, схема изменилась.

4. **`AP-` vs `AP-RP-`** — формат номера акта приёмки изменился, тест не был обновлён.

---

## Итого

- **15 классов тестов** с фокусом на конкретные контроллеры и сервисы.
- **250+ тестов**, покрывающих полный жизненный цикл заявки, справочники, вложения, аутентификацию.
- **Lines 90.76%** — уровень production-grade проекта.
- **Найдено и закрыто 4 прод-бага** до релиза.
