# Baseline инфраструктуры

**Сгенерирован:** 2026-09-29 11:06 UTC
**Период лога:** 29/Sep/2026:10:31 — 29/Sep/2026:10:34
**Длительность:** 1 s
**Всего запросов:** 14
**Версия приложения:** Laravel 13
**Конфигурация сервера:** см. раздел «Ресурсы»

---

## 1. Нагрузка

| Метрика | Значение |
|---|---|
| Всего запросов | 14 |
| RPS avg | 14.0000 |

---

## 2. Latency (по полю `rt`)

| Перцентиль | Значение, s |
|---|---|
| p50 | 0.136 |
| p95 | 5.494 |
| p99 | 5.494 |
| max | 5.494 |

---

## 3. Коды ответов

| Код | Количество |
|---|---|
| 200 | 12 |
| 404 | 2 |

---

## 4. Топ-10 эндпоинтов по среднему \`rt\`

| Эндпоинт | Avg rt, s | Calls |
|---|---|---|
| `/api/catalogs` | 5.4940 | 1 |
| `/api/reference-data` | 5.3210 | 1 |
| `/api/requests` | 0.3977 | 3 |
| `/api/chats/summary` | 0.1573 | 3 |
| `/api/notifications` | 0.1353 | 3 |
| `/api/session` | 0.0390 | 1 |
| `/health` | 0.0090 | 1 |
| `/status` | 0.0060 | 1 |

---

## 5. Топ-10 медленных SQL (pg_stat_statements)

| Mean time, ms | Calls | Запрос |
|---|---|---|
| 11.53 | 4 | `select * from "vehicle_models" where "vehicle_models"."id" in ($1)` |
| 10.09 | 1 | `select * from "work_types" where "tenant_id" = $1 and "active" = $2` |
| 9.42 | 3 | `select * from "work_types" where "id" in ($1, $2)` |
| 8.17 | 3 | `select * from "time_norms" where "id" in ($1)` |
| 5.81 | 1 | `insert into "cache" ("expiration", "key", "value") values ($1, $2, $3) on conflict ("key") do update set "expiration" = ` |
| 4.24 | 6 | `update "personal_access_tokens" set "last_used_at" = $1, "updated_at" = $2 where "id" = $3` |
| 4.08 | 3 | `select * from "stations" where "stations"."id" in ($1)` |
| 3.97 | 2 | `select * from "time_norms" where "work_type_id" in (select "id" from "work_types" where "tenant_id" = $1)` |
| 3.19 | 1 | `select * from "work_types" where "tenant_id" = $1` |
| 2.59 | 3 | `select * from "repair_requests" where "tenant_id" = $1 and ("stage" > $2 or "created_by" = $3) order by "id" desc` |

---

## 6. PostgreSQL cache hit ratio

| Метрика | Значение |
|---|---|
| Cache hit ratio, % | 99.99 |

---

## 7. Ресурсы

| Метрика | Значение |
|---|---|
| Load average (1m, 5m, 15m) | 0.07, 0.03, 0.01 |
| RAM total, MB | 1966 |
| RAM used, MB | 902 |
| RAM free, MB | 127 |
| RAM buff/cache, MB | 1182 |
| RAM available, MB | 1064 |
| Swap total, MB | 0 |
| Диск / размер | 19G |
| Диск / занято | 5.8G |
| Диск / свободно | 13G |
| Диск / % | 31% |
| `storage/app/media` | 2.6M |

---

## 8. Топ-5 процессов по CPU

| PID | User | CPU % | MEM % | Command |
|---|---|---|---|---|
| 447868 | netdata | 1.2 | 5.4 | `/usr/sbin/netdata -P /run/netdata/netdata.pid -D` |
| 448278 | netdata | 0.7 | 0.4 | `/usr/libexec/netdata/plugins.d/apps.plugin 1` |
| 448284 | netdata | 0.2 | 5.0 | `/usr/libexec/netdata/plugins.d/go.d.plugin 1` |
| 118621 | root | 0.2 | 1.2 | `//opt/acronis/aakore run` |
| 118622 | root | 0.1 | 8.4 | `/usr/lib/Acronis/BackupAndRecovery/mms` |

---

## 9. Топ-5 процессов по RAM

| PID | User | CPU % | MEM % | Command |
|---|---|---|---|---|
| 118622 | root | 0.1 | 8.4 | `/usr/lib/Acronis/BackupAndRecovery/mms` |
| 447868 | netdata | 1.2 | 5.4 | `/usr/sbin/netdata -P /run/netdata/netdata.pid -D` |
| 448284 | netdata | 0.2 | 5.0 | `/usr/libexec/netdata/plugins.d/go.d.plugin 1` |
| 118601 | root | 0.0 | 3.2 | `/usr/lib/Acronis/APL/active-protection --conf_file=/etc/Acronis/APL/active-protection.conf` |
| 118380 | root | 0.0 | 3.1 | `/usr/lib/systemd/systemd-journald` |

---

## 10. Выводы

_Заполнить вручную после просмотра отчёта._

- Сервер по ресурсам: …
- БД: …
- Аномально медленные эндпоинты: …
- Что делать дальше: …

