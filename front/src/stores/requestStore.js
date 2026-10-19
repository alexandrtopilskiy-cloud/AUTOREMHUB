/**
 * Хранилище заявок: in-memory state + сохранение в API по команде пользователя.
 *
 * Plain object store: Vue Options API makes it reactive through App.data().
 * Network state lives in memory; only layout preferences may use localStorage.
 */

import { requestFields } from "../data/permissions.js";

/**
 * Глубокое клонирование значения. `undefined` остаётся `undefined`.
 *
 * @template T
 * @param {T} value
 * @returns {T}
 */
function clone(value) {
    if (value === undefined) {
        return undefined;
    }

    return JSON.parse(JSON.stringify(value));
}

/**
 * Удаляет служебные поля интерфейса перед отправкой данных в API.
 * Они нужны только для стабильных ключей строк Vue и не являются частью домена.
 *
 * @param {unknown} value
 * @returns {unknown}
 */
export function stripClientMetadata(value) {
    if (Array.isArray(value)) {
        return value.map((item) => stripClientMetadata(item));
    }

    if (value !== null && typeof value === "object") {
        return Object.fromEntries(
            Object.entries(value)
                .filter(([key]) => !key.startsWith("_")&&
                        key !== "normApplicabilityLabel",
                )
                .map(([key, item]) => [key, stripClientMetadata(item)]),
        );
    }

    return value;
}

/** Снимок редактируемых полей запчастей для общего PATCH заявки. */
export function serializeParts(parts = []) {
    const fields = ['id', 'catalogPartId', 'name', 'article', 'unit', 'supplierId', 'qty', 'price',
    ];
    return parts
        .filter((row ) => row.id || row.catalogPartId || String(row.name || '').trim(),
        )
        .map((row ) => Object.fromEntries(fields.map((key ) => [
            key,
            row[key] ?? (['qty', 'price'].includes(key) ? 0 : ''),
        ]),
            ),
        );
}

/**
 * Сравнение двух значений по JSON-представлению.
 *
 * @param {unknown} a
 * @param {unknown} b
 * @returns {boolean}
 */
function equal(a, b) {
    return JSON.stringify(a) === JSON.stringify(b);
}

/**
 * @typedef {object} RequestStore
 * @property {Array<object>} requests
 * @property {Array<object>} records
 * @property {Array<object>} notifications
 * @property {Array<object>} chatSummaries
 * @property {number} chatUnreadTotal
 * @property {Array<object>} changeRegistry
 * @property {object} references
 * @property {Record<string, boolean>} pending
 * @property {boolean} busy
 * @property {boolean} loading
 * @property {string} saveError
 * @property {{ id: string }|null} conflict
 * @property {number} generation
 */

/**
 * @param {object} api
 * @returns {RequestStore}
 */
export function createRequestStore(api) {
    /** @type {Map<string, object>} */
    const baselines = new Map();

    /** @type {Map<string, Promise<void>>} */
    const saves = new Map();

    /**
     * Обновляет заявки с сервера, не затирая несохранённые поля формы.
     *
     * @param {RequestStore} store
     * @param {Array<object>} remote
     */
    function applyRemoteRequests(store, remote) {
        const previous = store.requests;

        store.requests = remote.map((request) =>
            store.pending[request.id]
                ? previous.find((item) => item.id === request.id) || request
                : request,
        );

        for (const request of previous) {
            if (store.pending[request.id] && !store.get(request.id)) {
                store.requests.push(request);
            }
        }

        for (const request of remote) {
            if (!store.pending[request.id]) {
                baselines.set(request.id, clone(request));
            }
        }
    }

    return {
        /* ─── Состояние ─── */

        requests: [],
        records: [],
        notifications: [],
        chatSummaries: [],
        chatUnreadTotal: 0,
        changeRegistry: [],
        references: {
            reasons: [],
            workTypes: [],
            timeNorms: [],
            normApplicabilities: [],
            workGroups: [],
            regulations: [],
            suppliers: [],
            parts: [],
            partUnits: [],
            organizations: [],
            accessRights: [],
        },
        pending: {},
        busy: false,
        loading: false,
        saveError: "",
        conflict: null,
        generation: 0,

        /* ─── Вычисляемые ─── */

        get dirty() {
            return Object.values(this.pending).some(Boolean);
        },

        /* ─── Чтение ─── */

        get(id) {
            return this.requests.find((request) => request.id === id);
        },

        changed(id) {
            const request = this.get(id);
            const baseline = baselines.get(id);

            const fields = requestFields.filter(
                (key) => !equal(request?.[key], baseline?.[key]),
            );

            return Object.fromEntries(
                fields.map((key) => [
                    key,
                    key === "parts" ? serializeParts(request[key]) : stripClientMetadata(clone(request[key])),
                ]),
            );
        },

        /* ─── Применение ответа API ─── */

        accept(server, unsent = {}) {
            const local = this.get(server.id);

            if (local) {
                Object.assign(local, clone(server), unsent);
            } else {
                this.requests.unshift(clone(server));
            }

            baselines.set(server.id, clone(server));

            this.pending[server.id] = Object.keys(unsent).length > 0;
        },

        /* ─── Загрузка ─── */

        async refresh() {
            const version = this.generation;

            this.loading = true;

            try {
                const [remote, records, refs, notifications, chats] = await Promise.all([
                    api.listRequests(),
                    api.listCatalogs(),
                    api.references(),
                    api.listNotifications(),
                    api.chatSummary(),
                ]);

                if (version !== this.generation) {
                    return;
                }

                applyRemoteRequests(this, remote);

                this.records = records;
                this.references = refs;
                this.notifications = notifications;
                this.chatSummaries = chats.chats;
                this.chatUnreadTotal = chats.unreadTotal;
            } finally {
                if (version === this.generation) {
                    this.loading = false;
                }
            }
        },

        /**
         * Фоновая синхронизация. Не загружает тяжёлые справочники и нормы
         * времени: они обновляются при входе и после профильных операций.
         */
        async refreshLive() {
            const version = this.generation;
            const [remote, notifications, chats] = await Promise.all([
                api.listRequests(),
                api.listNotifications(),
                api.chatSummary(),
            ]);

            if (version !== this.generation) {
                return;
            }

            applyRemoteRequests(this, remote);
            const knownNotifications = new Set(this.notifications.map((item) => item.id),
            );
            const manufacturerChanged = notifications.some((item) => item.kind === "manufacturer_reviewed" && !knownNotifications.has(item.id),
            );
            this.notifications = notifications;
            this.chatSummaries = chats.chats;
            this.chatUnreadTotal = chats.unreadTotal;
            if (manufacturerChanged) await this.refreshCatalogs(true);
        },

        /**
         * Обновляет только справочники. Используется после операций, результат
         * которых нельзя безопасно применить локально (например, согласование).
         *
         * @param {boolean} includeReferences
         */
        async refreshCatalogs(includeReferences = false) {
            const version = this.generation;
            const tasks = [api.listCatalogs()];

            if (includeReferences) {
                tasks.push(api.references());
            }

            const [records, references] = await Promise.all(tasks);

            if (version !== this.generation) {
                return;
            }

            this.records = records;

            if (includeReferences) {
                this.references = references;
            }
        },

        upsertRecord(record) {
            if (!record?.id) {
                return;
            }

            const index = this.records.findIndex((item) => item.id === record.id,
            );

            if (index >= 0) {
                this.records[index] = clone(record);
            } else {
                this.records.unshift(clone(record));
            }

            if (record.kind === "partManufacturers" && (record.status !== "approved" || !record.active)) {
                this.references.partManufacturers = (this.references.partManufacturers || []).filter((row) => row.id !== record.id);
                return;
            }
            const referenceRows = this.references[record.kind];

            if (Array.isArray(referenceRows)) {
                const referenceIndex = referenceRows.findIndex(
                    (item) => item.id === record.id,
                );

                if (referenceIndex >= 0) {
                    referenceRows[referenceIndex] = clone(record);
                } else {
                    referenceRows.unshift(clone(record));
                }
            }
        },

        removeRecord(id) {
            this.records = this.records.filter((item) => item.id !== id);

            for (const [key, rows] of Object.entries(this.references)) {
                if (Array.isArray(rows)) {
                    this.references[key] = rows.filter((item) => item.id !== id,
                    );
                }
            }
        },

        async refreshNotifications() {
            this.notifications = await api.listNotifications();
        },

        async refreshChatSummaries() {
            const version = this.generation;
            const data = await api.chatSummary();

            if (version !== this.generation) return;
            this.chatSummaries = data.chats;
            this.chatUnreadTotal = data.unreadTotal;
        },

        async refreshChangeRegistry() {
            this.changeRegistry = await api.changeRegistry();

            return this.changeRegistry;
        },

        async readNotification(id) {
            const notification = await api.readNotification(id);
            const index = this.notifications.findIndex((item) => item.id === id,
            );

            if (index >= 0) {
                if (notification.deleted) {
                    this.notifications.splice(index, 1);
                } else {
                    this.notifications[index] = notification;
                }
            }

            return notification;
        },

        /* ─── Сохранение ─── */

        async save(id) {
            if (saves.has(id)) {
                await saves.get(id);

                if (this.pending[id]) {
                    return this.save(id);
                }

                return;
            }

            const version = this.generation;
            const local = this.get(id);

            if (!local) {
                return;
            }

            const patch = this.changed(id);

            if (Object.keys(patch).length === 0) {
                this.pending[id] = false;

                return;
            }

            const before = clone(local);

            const task = (async () => {
                try {
                    const saved = await api.updateRequest(
                        id,
                        before.revision,
                        patch,
                    );

                    if (version !== this.generation) {
                        return;
                    }

                    const unsent = Object.fromEntries(
                        requestFields
                            .filter((key) => !equal(local[key], before[key]))
                            .map((key) => [key, clone(local[key])]),
                    );

                    this.accept(saved, unsent);
                    this.saveError = "";
                } catch (e) {
                    this.saveError = e.message;

                    if (e.status === 409) {
                        this.conflict = { id };
                    }

                    throw e;
                }
            })();

            saves.set(id, task);

            try {
                await task;
            } finally {
                saves.delete(id);
            }
        },

        /* ─── Несохранённые изменения ─── */

        touch(id) {
            this.pending[id] = true;
        },

        async flush() {
            await Promise.all([...saves.values()]);
        },

        discard(id) {
            const request = this.get(id);
            const baseline = baselines.get(id);

            if (request && baseline) {
                Object.assign(request, clone(baseline));
            }

            this.pending[id] = false;
            this.saveError = "";

            if (this.conflict?.id === id) {
                this.conflict = null;
            }
        },

        /* ─── Создание ─── */

        async create() {
            if (this.busy) {
                return null;
            }

            this.busy = true;

            try {
                const request = await api.createRequest();

                this.accept(request);

                return this.get(request.id);
            } finally {
                this.busy = false;
            }
        },

        async deleteDrafts(ids) {
            if (this.busy) {
                throw new Error("Дождитесь завершения текущей операции");
            }

            const uniqueIds = [...new Set(ids)];

            if (!uniqueIds.length) {
                return [];
            }

            this.busy = true;

            try {
                const deletedIds = await api.deleteDrafts(uniqueIds);
                const deleted = new Set(deletedIds);

                this.requests = this.requests.filter(
                    (request) => !deleted.has(request.id),
                );

                for (const id of deleted) {
                    baselines.delete(id);
                    delete this.pending[id];
                }

                return deletedIds;
            } catch (error) {
                this.saveError = error.message;
                throw error;
            } finally {
                this.busy = false;
            }
        },

        /* ─── Действия ─── */

        async act(id, method, ...args) {
            if (this.busy) {
                throw new Error("Дождитесь завершения текущей операции");
            }

            this.busy = true;

            try {
                if (this.pending[id]) {
                    throw new Error("Сначала сохраните изменения кнопкой «Сохранить»",
                    );
                }

                const request = await api[method](
                    id,
                    this.get(id).revision,
                    ...args,
                );

                this.accept(request);

                return request;
            } catch (e) {
                this.saveError = e.message;

                if (e.status === 409) {
                    this.conflict = { id };
                }

                throw e;
            } finally {
                this.busy = false;
            }
        },

        /* ─── Разрешение конфликта ─── */

        async resolveConflict(choice) {
            if (!this.conflict) {
                return;
            }

            const { id } = this.conflict;

            if (saves.has(id)) {
                try {
                    await saves.get(id);
                } catch {
                    // Игнорируем предыдущую ошибку — сейчас перезапрашиваем сервер.
                }
            }

            const before = clone(this.get(id));
            const patch = this.changed(id);
            const fresh = await api.getRequest(id);

            const result =
                choice === "mine" && Object.keys(patch).length > 0
                    ? await api.updateRequest(id, fresh.revision, patch)
                    : fresh;

            const local = this.get(id);

            const unsent =
                choice === "mine"
                    ? Object.fromEntries(
                          requestFields
                              .filter((key) => !equal(local[key], before[key]))
                              .map((key) => [key, clone(local[key])]),
                      )
                    : {};

            this.accept(result, unsent);
            this.conflict = null;
            this.saveError = "";

            if (this.pending[id]) {
                await this.save(id);
            }
        },

        /* ─── Сброс ─── */

        reset() {
            this.generation++;
            baselines.clear();

            this.requests = [];
            this.records = [];
            this.notifications = [];
            this.chatSummaries = [];
            this.chatUnreadTotal = 0;
            this.changeRegistry = [];
            this.pending = {};
            this.saveError = "";
            this.conflict = null;
            this.loading = false;
        },
    };
}
