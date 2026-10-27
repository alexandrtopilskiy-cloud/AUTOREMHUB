/**
 * API-клиент маркетплейса СТО.
 *
 * Все методы возвращают нормализованные данные (см. readUser, readRequest)
 * или бросают ApiError.
 */

import { http, ApiError, apiConfig } from "./client.js";
import { roleLabels, requestFields } from "../data/permissions.js";

/* ─── Константы ─── */

/** Таймаут для загрузки файлов — 3 минуты. */
const UPLOAD_TIMEOUT_MS = 180_000;

/** Формат номера заявки: RP-000001 и более. */
const REQUEST_NUMBER_PATTERN = /^RP-\d{6,}$/;

/** Максимальный номер этапа (11 — «Заявка закрыта»). */
const MAX_STAGE = 11;

/** Ключ для сохранения токена только в текущей вкладке браузера. */
const TOKEN_STORAGE_KEY = "api_token";

/**
 * @param {string|number} value
 * @returns {string}
 */
const encodeId = (value) => encodeURIComponent(String(value));

/* ─── Нормализация ответов ─── */

/**
 * Нормализует пользователя из ответа API.
 *
 * @param {object} data
 * @returns {object}
 */
function readUser(data) {
    if (!data?.user?.id || !roleLabels[data.user.role]) {
        throw new ApiError("Некорректный ответ входа");
    }

    return {
        ...data.user,
        label: roleLabels[data.user.role],
    };
}

/**
 * Применяет токен из ответа API: сохраняет в клиенте и sessionStorage.
 *
 * @param {object} data
 */
function applyToken(data) {
    if (typeof data?.token !== "string" || data.token === "") {
        return;
    }

    http.setToken(data.token);

    try {
        sessionStorage.setItem(TOKEN_STORAGE_KEY, data.token);
    } catch {
        // sessionStorage может быть недоступен (private mode).
    }
}

/**
 * Очищает токен в клиенте и хранилище текущей вкладки.
 */
export function clearToken() {
    http.setToken("");

    try {
        sessionStorage.removeItem(TOKEN_STORAGE_KEY);
    } catch {
        // Игнорируем недоступность sessionStorage.
    }

    try {
        // Удаляем токен старых версий, чтобы новая вкладка его не наследовала.
        localStorage.removeItem(TOKEN_STORAGE_KEY);
    } catch {
        // Игнорируем недоступность localStorage.
    }
}

/**
 * Восстанавливает токен текущей вкладки. Вызывается при старте приложения.
 *
 * @returns {boolean} true, если токен восстановлен.
 */
export function restoreToken() {
    let saved = "";

    try {
        saved = sessionStorage.getItem(TOKEN_STORAGE_KEY) || "";
    } catch {
        saved = "";
    }

    try {
        localStorage.removeItem(TOKEN_STORAGE_KEY);
    } catch {
        // Игнорируем недоступность localStorage.
    }

    if (saved === "") {
        return false;
    }

    http.setToken(saved);

    return true;
}

/**
 * Нормализует заявку: проверяет формат и фильтрует editableFields
 * по актуальному whitelist (requestFields).
 *
 * @param {object} request
 * @returns {object}
 */
export function readRequest(request) {
    if (!isValidRequest(request)) {
        throw new ApiError(
            "Ожидается формат заявки API v2 с номером RP-000001",
        );
    }

    return {
        ...request,
        permissions: {
            ...request.permissions,
            editableFields: (request.permissions?.editableFields || []).filter(
                (key) => requestFields.includes(key),
            ),
        },
    };
}

/**
 * @param {unknown} request
 * @returns {boolean}
 */
function isValidRequest(request) {
    return (
        request !== null &&
        typeof request === "object" &&
        typeof request.id === "string" &&
        Number.isInteger(request.revision) &&
        Number.isInteger(request.stage) &&
        request.stage >= 0 &&
        request.stage <= MAX_STAGE &&
        REQUEST_NUMBER_PATTERN.test(request.number || "")
    );
}

/**
 * Выполняет команду (POST-переход) и нормализует ответ.
 *
 * @param {string} key
 * @param {string} path
 * @param {number} revision
 * @param {object} [body]
 * @returns {Promise<object>}
 */
async function command(key, path, revision, body = {}) {
    const response = await http.request(`/requests/${encodeId(key)}${path}`, {
        method: "POST",
        body: { revision, ...body },
    });

    return readRequest(response.request);
}

/* ─── Публичный API ─── */

export const marketplaceApi = {
    /* ─── Аутентификация ─── */

    /**
     * @param {string} login
     * @param {string} password
     * @param {boolean} [force]
     * @returns {Promise<object>}
     */
    async login(login, password, force = false) {
        const data = await http.request("/login", {
            method: "POST",
            body: { login, password, force },
        });

        applyToken(data);

        return readUser(data);
    },

    /**
     * @returns {Promise<object>}
     */
    async getSession() {
        const data = await http.request("/session");

        return readUser(data);
    },

    async logout() {
        try {
            await http.request("/logout", { method: "POST", body: {} });
        } finally {
            clearToken();
        }
    },

    /* ─── Заявки ─── */

    /**
     * @returns {Promise<object[]>}
     */
    async listRequests() {
        const data = await http.request("/requests");

        return data.requests.map(readRequest);
    },

    /**
     * @param {string} key
     * @returns {Promise<object>}
     */
    async getRequest(key) {
        const data = await http.request(`/requests/${encodeId(key)}`);

        return readRequest(data.request);
    },

    /**
     * @returns {Promise<object>}
     */
    async createRequest() {
        const data = await http.request("/requests", {
            method: "POST",
            body: {},
        });

        return readRequest(data.request);
    },

    /**
     * @param {string[]} ids
     * @returns {Promise<string[]>}
     */
    async deleteDrafts(ids) {
        const data = await http.request("/requests/drafts/delete", {
            method: "POST",
            body: { ids },
        });

        return data.deletedIds || [];
    },

    /**
     * @param {string} key
     * @param {number} revision
     * @param {object} patch
     * @returns {Promise<object>}
     */
    async updateRequest(key, revision, patch) {
        const data = await http.request(`/requests/${encodeId(key)}`, {
            method: "PATCH",
            body: { revision, ...patch },
        });

        return readRequest(data.request);
    },

    /**
     * @param {string} key
     * @param {number} revision
     * @param {string} action
     * @param {object} body
     * @returns {Promise<object>}
     */
    transition(key, revision, action, body) {
        return command(key, `/${action}`, revision, body);
    },

    /**
     * @param {string} key
     * @param {number} revision
     * @param {string} decision
     * @param {{comment: string, stationSlot?: string}} body
     * @returns {Promise<object>}
     */
    stationDecision(key, revision, decision, body) {
        return command(key, "/station-decision", revision, {
            decision,
            ...body,
        });
    },

    /**
     * @param {string} key
     * @param {number} revision
     * @param {'accepted'|'rescheduled'} decision
     * @param {{slot?: string, comment?: string}} body
     * @returns {Promise<object>}
     */
    customerSlotDecision(key, revision, decision, body = {}) {
        return command(key, "/customer-slot-decision", revision, {
            decision,
            ...body,
        });
    },

    /**
     * @param {string} key
     * @param {number} revision
     * @param {object} body
     * @returns {Promise<object>}
     */
    acceptVehicle(key, revision, body) {
        return command(key, "/acceptance-act", revision, body);
    },

    /* ─── Уведомления ─── */

    /**
     * @returns {Promise<object[]>}
     */
    async listNotifications() {
        const data = await http.request("/notifications");

        return data.notifications;
    },

    /**
     * @param {string} id
     * @returns {Promise<object>}
     */
    async readNotification(id) {
        const data = await http.request(`/notifications/${encodeId(id)}/read`, {
            method: "POST",
            body: {},
        });

        return data.notification;
    },

    /* ─── Чат заявки ─── */

    async chatSummary() {
        return http.request("/chats/summary");
    },

    async getChat(requestId, cursor = {}) {
        const query = cursor.before
            ? `?before=${encodeURIComponent(cursor.before)}`
            : cursor.after
              ? `?after=${encodeURIComponent(cursor.after)}`
              : "";

        return http.request(`/requests/${encodeId(requestId)}/chat${query}`);
    },

    async createChat(requestId) {
        return http.request(`/requests/${encodeId(requestId)}/chat`, {
            method: "POST",
            body: {},
        });
    },

    async sendChatMessage(requestId, text, files = []) {
        const body = new FormData();
        body.append("text", text);
        for (const file of files) body.append("files[]", file);

        const data = await http.request(
            `/requests/${encodeId(requestId)}/chat/messages`,
            { method: "POST", body, timeoutMs: UPLOAD_TIMEOUT_MS },
        );

        return data.message;
    },

    async readChat(requestId, lastMessageId) {
        return http.request(`/requests/${encodeId(requestId)}/chat/read`, {
            method: "POST",
            body: { lastMessageId },
        });
    },

    async downloadChatFile(fileId) {
        const response = await fetch(
            `${apiConfig.baseUrl}/chat-files/${encodeId(fileId)}/download`,
            { headers: { Authorization: `Bearer ${http.getToken()}` } },
        );

        if (!response.ok) {
            throw new ApiError("Не удалось открыть файл чата", response.status);
        }

        return response.blob();
    },

    /* ─── Справочники ─── */

    /**
     * @returns {Promise<object[]>}
     */
    async listCatalogs() {
        const data = await http.request("/catalogs");

        return data.records;
    },

    /**
     * @returns {Promise<object>}
     */
    references() {
        return http.request("/reference-data");
    },

    /**
     * @param {string} key
     * @param {object} patch
     * @returns {Promise<object>}
     */
    async updateCatalog(key, patch) {
        const data = await http.request(`/catalogs/${encodeId(key)}`, {
            method: "PATCH",
            body: { patch },
        });

        return data.record;
    },

    /**
     * @param {string} kind
     * @param {object} record
     * @returns {Promise<object>}
     */
    async createCatalog(kind, record) {
        const data = await http.request(`/catalogs/${encodeId(kind)}`, {
            method: "POST",
            body: { record },
        });

        return data.record;
    },

    /**
     * @param {object} record
     * @returns {Promise<object>}
     */
    async createVehicleCatalog(record) {
        return http.request("/catalogs/vehicles", {
            method: "POST",
            body: { record },
        });
    },

    /**
     * @param {string} id
     * @param {object} patch
     * @returns {Promise<object>}
     */
    async updateVehicleCatalog(id, patch) {
        return http.request(`/catalogs/vehicles/${encodeId(id)}`, {
            method: "PATCH",
            body: { patch },
        });
    },

    /**
     * @param {string} id
     * @returns {Promise<object>}
     */
    async deleteVehicleCatalog(id) {
        return http.request(`/catalogs/vehicles/${encodeId(id)}`, {
            method: "DELETE",
        });
    },

    /**
     * @param {object} record
     * @returns {Promise<object>}
     */
    async createOrganizationPerson(record) {
        const data = await http.request("/catalogs/people", {
            method: "POST",
            body: { record },
        });

        return data.record;
    },

    /**
     * @param {string} id
     * @param {object} patch
     * @returns {Promise<object>}
     */
    async updateOrganizationPerson(id, patch) {
        const data = await http.request(`/catalogs/people/${encodeId(id)}`, {
            method: "PATCH",
            body: { patch },
        });

        return data.record;
    },

    /**
     * @param {string} id
     * @returns {Promise<object>}
     */
    deleteOrganizationPerson(id) {
        return http.request(`/catalogs/people/${encodeId(id)}`, {
            method: "DELETE",
        });
    },

    /**
     * @param {string} id
     * @param {'approved'|'rejected'} decision
     * @param {string} comment
     * @returns {Promise<object>}
     */
    async reviewVehicleChange(id, decision, comment) {
        const data = await http.request(
            `/catalogs/vehicle-approvals/${encodeId(id)}/decision`,
            {
                method: "POST",
                body: { decision, comment },
            },
        );

        return data.record;
    },

    /**
     * @returns {Promise<object[]>}
     */
    async changeRegistry() {
        const data = await http.request("/change-registry");

        return data.records;
    },

    /**
     * @param {string} kind
     * @param {string} [recordId]
     * @returns {Promise<object[]>}
     */
    async catalogHistory(kind, recordId = "") {
        const query = new URLSearchParams({ kind });

        if (recordId) {
            query.set("recordId", recordId);
        }

        const data = await http.request(`/catalogs/history?${query}`);

        return data.events;
    },

    /**
     * @param {File} file
     * @returns {Promise<object>}
     */
    async reviewManufacturer(id, decision, revision) {
        const data = await http.request(
            `/catalogs/part-manufacturers/${id}/decision`,
            { method: "POST", body: { decision, revision } },
        );
        return data.record;
    },
    async importTimeNorms(file) {
        const body = new FormData();
        body.append("file", file);

        const data = await http.request("/catalogs/time-norms/import", {
            method: "POST",
            body,
            timeoutMs: UPLOAD_TIMEOUT_MS,
        });

        return data.result;
    },

    /**
     * Выполняет полную сверку нового справочника работ без изменения БД.
     *
     * @param {File} file
     * @returns {Promise<object>}
     */
    async restoreRecommendationSelection(
        documents,
        requestId = null,
        requestRevision = null,
    ) {
        const result = await http.request("/recommendations/restore", {
            method: "POST",
            body: { documents, requestId, requestRevision },
        });
        return { ...result, request: readRequest(result.request) };
    },

    async previewWorkCatalog(file) {
        const body = new FormData();
        body.append("file", file);

        const data = await http.request(
            "/catalogs/work-catalog/import/preview",
            {
                method: "POST",
                body,
                timeoutMs: UPLOAD_TIMEOUT_MS,
            },
        );

        return data.result;
    },

    /**
     * Атомарно применяет ранее проверенный справочник работ.
     *
     * @param {File} file
     * @param {string} checksum
     * @returns {Promise<object>}
     */
    async applyWorkCatalog(file, checksum) {
        const body = new FormData();
        body.append("file", file);
        body.append("checksum", checksum);

        const data = await http.request("/catalogs/work-catalog/import/apply", {
            method: "POST",
            body,
            timeoutMs: UPLOAD_TIMEOUT_MS,
        });

        return data.result;
    },

    /**
     * Подбирает наиболее узкую норму и рассчитывает стоимость строки.
     *
     * @param {string} workTypeId
     * @param {string} vehicleId
     * @param {number|string} quantity
     * @returns {Promise<object>}
     */
    async resolveTimeNorm(
        workTypeId,
        vehicleId,
        quantity = 1,
        laborRateType = "general",
    ) {
        const query = new URLSearchParams({
            workTypeId,
            vehicleId,
            quantity: String(quantity || 1),
            laborRateType,
        });
        const data = await http.request(`/time-norms/resolve?${query}`);

        return data.result;
    },

    /**
     * @param {string} kind
     * @returns {Promise<{blob: Blob, filename: string}>}
     */
    async downloadCatalogTemplate(kind) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), apiConfig.timeoutMs);

        try {
            const response = await fetch(
                `${apiConfig.baseUrl}/catalogs/${encodeId(kind)}/import/template`,
                {
                    signal: controller.signal,
                    headers: {
                        Accept: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                        ...(http.getToken()
                            ? { Authorization: `Bearer ${http.getToken()}` }
                            : {}),
                    },
                },
            );

            if (!response.ok) {
                let data = null;

                try {
                    data = await response.json();
                } catch {
                    data = null;
                }

                throw new ApiError(
                    data?.error?.message ||
                        `Не удалось скачать шаблон (HTTP ${response.status})`,
                    response.status,
                    data,
                );
            }

            const disposition =
                response.headers.get("Content-Disposition") || "";
            const match = disposition.match(
                /filename\*?=(?:UTF-8''|\")?([^\";]+)/i,
            );

            return {
                blob: await response.blob(),
                filename: match
                    ? decodeURIComponent(match[1].replace(/^\"|\"$/g, ""))
                    : `template-${kind}.xlsx`,
            };
        } catch (error) {
            if (error instanceof ApiError) throw error;

            if (controller.signal.aborted) {
                throw new ApiError(
                    "API не ответил вовремя. Повторите скачивание шаблона.",
                );
            }

            throw new ApiError(
                "Не удалось скачать шаблон. Проверьте соединение с API.",
            );
        } finally {
            clearTimeout(timer);
        }
    },

    /**
     * @param {string} kind
     * @param {File} file
     * @returns {Promise<object>}
     */
    async importCatalogExcel(kind, file) {
        const body = new FormData();
        body.append("file", file);

        const data = await http.request(`/catalogs/${encodeId(kind)}/import`, {
            method: "POST",
            body,
            timeoutMs: UPLOAD_TIMEOUT_MS,
        });

        return data.result;
    },

    /**
     * @param {string} search
     * @returns {Promise<object[]>}
     */
    async vehicleSelection(makeId, step, filters = {}) {
        const query = new URLSearchParams({ makeId, step });
        for (const [key, value] of Object.entries(filters))
            query.set(`filters[${key}]`, value);
        return http.request(`/vehicle-reference/selection?${query}`);
    },

    async vehicleMakes(search = "") {
        const query = new URLSearchParams({ search, limit: "100" });
        const data = await http.request(`/vehicle-reference/makes?${query}`);

        return data.makes || [];
    },

    /**
     * @param {string} makeId
     * @param {string} search
     * @returns {Promise<object[]>}
     */
    async vehicleModels(makeId, search = "", uniqueNames = false) {
        const query = new URLSearchParams({
            makeId,
            search,
            limit: "100",
        });
        if (uniqueNames) query.set("uniqueNames", "1");
        const data = await http.request(`/vehicle-reference/models?${query}`);

        return data.models || [];
    },

    /**
     * @param {string} modelId
     * @param {string} search
     * @returns {Promise<object[]>}
     */
    async vehicleGenerations(modelId, search = "") {
        const query = new URLSearchParams({
            modelId,
            search,
            limit: "100",
        });
        const data = await http.request(
            `/vehicle-reference/generations?${query}`,
        );

        return data.generations || [];
    },

    /**
     * @param {string} modelId
     * @param {string} generationId
     * @param {string} search
     * @returns {Promise<object[]>}
     */
    async vehicleEngines(modelId, generationId = "", search = "") {
        const query = new URLSearchParams({
            modelId,
            search,
            limit: "100",
        });

        if (generationId) query.set("generationId", generationId);

        const data = await http.request(`/vehicle-reference/engines?${query}`);

        return data.engines || [];
    },

    /**
     * @param {string} search
     * @returns {Promise<object[]>}
     */
    async vehicleTransmissions(search = "") {
        const query = new URLSearchParams({ search, limit: "100" });
        const data = await http.request(
            `/vehicle-reference/transmissions?${query}`,
        );

        return data.transmissions || [];
    },

    /**
     * @param {string} search
     * @returns {Promise<object[]>}
     */
    async vehicleDrives(search = "") {
        const query = new URLSearchParams({ search, limit: "100" });
        const data = await http.request(`/vehicle-reference/drives?${query}`);

        return data.drives || [];
    },

    /**
     * @param {Record<string, string>} filters
     * @param {number} page
     * @param {number} perPage
     * @returns {Promise<object>}
     */
    async vehicleReferenceCatalog(filters = {}, page = 1, perPage = 100) {
        const query = new URLSearchParams({
            page: String(page),
            perPage: String(perPage),
        });

        Object.entries(filters).forEach(([key, value]) => {
            if (String(value || "").trim()) {
                query.set(key, String(value).trim());
            }
        });

        return http.request(`/vehicle-reference/catalog?${query}`);
    },

    /**
     * @param {object} record
     * @returns {Promise<object>}
     */
    async createVehicleCatalogVariant(record) {
        const data = await http.request("/vehicle-reference/catalog", {
            method: "POST",
            body: { record },
        });
        return data.model;
    },
    async createVehicleModel(record) {
        const data = await http.request("/vehicle-reference/models", {
            method: "POST",
            body: { record },
        });

        return data.model;
    },

    /* ─── Автомобили ─── */

    /**
     * @param {string} query
     * @param {string} [excludeRequest]
     * @returns {Promise<object[]>}
     */
    async searchVehicles(query, excludeRequest = "") {
        const suffix = excludeRequest
            ? `&excludeRequest=${encodeId(excludeRequest)}`
            : "";
        const data = await http.request(
            `/vehicles?q=${encodeId(query)}${suffix}`,
        );

        return data.vehicles;
    },

    /**
     * @param {string} key
     * @returns {Promise<object[]>}
     */
    async vehicleContacts(key) {
        const data = await http.request(`/vehicles/${encodeId(key)}/contacts`);

        return data.contacts;
    },

    /**
     * @param {string} key
     * @returns {Promise<object[]>}
     */
    async vehicleDrivers(key) {
        const data = await http.request(`/vehicles/${encodeId(key)}/drivers`);

        return data.drivers;
    },

    /* ─── Блокировки совместного открытия ─── */

    /**
     * @param {string} resourceType
     * @param {string} resourceKey
     * @param {string} label
     * @returns {Promise<object>}
     */
    async acquireDocumentLock(resourceType, resourceKey, label = "") {
        const data = await http.request("/document-locks", {
            method: "POST",
            body: { resourceType, resourceKey, label },
        });

        return data.lock;
    },

    /**
     * @param {string} id
     * @returns {Promise<object>}
     */
    async heartbeatDocumentLock(id) {
        const data = await http.request(
            `/document-locks/${encodeId(id)}/heartbeat`,
            { method: "POST", body: {} },
        );

        return data.lock;
    },

    /**
     * @param {string} id
     * @returns {Promise<void>}
     */
    releaseDocumentLock(id) {
        return http.request(`/document-locks/${encodeId(id)}`, {
            method: "DELETE",
        });
    },

    /**
     * @param {string} field
     * @param {string} value
     * @returns {Promise<object>}
     */
    async lookupVehicle(field, value) {
        const data = await http.request(
            `/vehicles/lookup?${field}=${encodeId(value)}`,
        );

        return data.vehicle;
    },

    /**
     * @param {string} key
     * @returns {Promise<object>}
     */
    vehicleStatistics(key) {
        return http.request(`/vehicles/${encodeId(key)}/statistics`);
    },

    /* ─── Диагностика и запчасти ─── */

    /**
     * @param {string} key
     * @param {number} revision
     * @param {object} body
     * @returns {Promise<object>}
     */
    diagnosis(key, revision, body) {
        return command(key, "/diagnostics", revision, body);
    },

    /**
     * @param {string} key
     * @param {number} revision
     * @param {object} body
     * @param {string} [partId]
     * @returns {Promise<object>}
     */
    async vehicleRecommendations(vehicleId) {
        const result = await http.request(
            `/recommendations?vehicleId=${encodeId(vehicleId)}`,
        );
        return result.recommendations;
    },
    async deferRecommendations(key, revision, selection) {
        const result = await http.request(
            `/requests/${encodeId(key)}/recommendations`,
            { method: "POST", body: { revision, ...selection } },
        );
        return { ...result, request: readRequest(result.request) };
    },
    async restoreRecommendations(
        id,
        revision,
        itemIds,
        requestId = null,
        requestRevision = null,
    ) {
        const result = await http.request(
            `/recommendations/${encodeId(id)}/restore`,
            {
                method: "POST",
                body: { revision, itemIds, requestId, requestRevision },
            },
        );
        return { ...result, request: readRequest(result.request) };
    },
    async savePart(key, revision, body, partId) {
        if (partId) {
            const data = await http.request(
                `/requests/${encodeId(key)}/parts/${encodeId(partId)}`,
                { method: "PATCH", body: { revision, ...body } },
            );

            return readRequest(data.request);
        }

        return command(key, "/parts", revision, body);
    },

    /**
     * @param {string} key
     * @param {number} revision
     * @param {string} partId
     * @returns {Promise<object>}
     */
    removePart(key, revision, partId) {
        return command(key, `/parts/${encodeId(partId)}/remove`, revision);
    },

    /**
     * @param {string} key
     * @param {number} revision
     * @param {string} partId
     * @param {object} body
     * @returns {Promise<object>}
     */
    partStatus(key, revision, partId, body) {
        return command(
            key,
            `/parts/${encodeId(partId)}/status`,
            revision,
            body,
        );
    },

    /**
     * @param {string} key
     * @param {number} revision
     * @param {string} partId
     * @param {object} body
     * @returns {Promise<object>}
     */
    partMovement(key, revision, partId, body) {
        return command(
            key,
            `/parts/${encodeId(partId)}/movements`,
            revision,
            body,
        );
    },

    /* ─── Счёт и оплата ─── */

    /**
     * @param {string} key
     * @param {number} revision
     * @param {object} body
     * @returns {Promise<object>}
     */
    invoice(key, revision, body) {
        return command(key, "/invoice", revision, body);
    },

    /**
     * @param {string} key
     * @param {number} revision
     * @param {object} body
     * @returns {Promise<object>}
     */
    payment(key, revision, body) {
        return command(key, "/payment", revision, body);
    },

    /**
     * @param {string} key
     * @param {number} revision
     * @param {object} body
     * @returns {Promise<object>}
     */
    verifyPayment(key, revision, body) {
        return command(key, "/payment/verify", revision, body);
    },

    /* ─── Вложения ─── */

    /**
     * @param {string} key
     * @param {number} revision
     * @param {File} file
     * @param {string} purpose
     * @returns {Promise<object>}
     */
    async upload(key, revision, file, purpose) {
        const body = new FormData();
        body.append("revision", String(revision));
        body.append("purpose", purpose);
        body.append("file", file);

        const data = await http.request(
            `/requests/${encodeId(key)}/attachments`,
            {
                method: "POST",
                body,
                timeoutMs: UPLOAD_TIMEOUT_MS,
            },
        );

        return readRequest(data.request);
    },

    /**
     * @param {string} key
     * @returns {Promise<string>}
     */
    async attachmentUrl(key) {
        const data = await http.request(`/attachments/${encodeId(key)}/url`);

        return data.url;
    },
};
