/**
 * HTTP transport only. Authentication and all authorization belong to your API.
 */

const DEFAULT_BASE_URL = "/api";
const DEFAULT_TIMEOUT_MS = 30000;
const JSON_CONTENT_TYPE = "application/json";
const ACCEPT_HEADER = "application/json";

export class ApiError extends Error {
    /**
     * @param {string} message
     * @param {number} [status]
     * @param {unknown} [details]
     */
    constructor(message, status = 0, details = null) {
        super(message);
        this.name = "ApiError";
        this.status = status;
        this.details = details;
    }
}

const viteEnv = import.meta.env || {};

export const apiConfig = {
    baseUrl: stripTrailingSlashes(
        viteEnv.VITE_API_BASE_URL || DEFAULT_BASE_URL,
    ),
    timeoutMs: Number(viteEnv.VITE_API_TIMEOUT_MS) || DEFAULT_TIMEOUT_MS,
};

/**
 * @param {string} url
 * @returns {string}
 */
function stripTrailingSlashes(url) {
    return url.replace(/\/+$/, "");
}

/**
 * @typedef {object} ApiClientConfig
 * @property {string} baseUrl
 * @property {number} timeoutMs
 */

/**
 * @typedef {object} RequestOptions
 * @property {string} [method]
 * @property {unknown} [body]
 * @property {Record<string, string>} [headers]
 * @property {number} [timeoutMs]
 */

/**
 * @param {ApiClientConfig} [config]
 * @param {typeof fetch} [fetcher]
 */
export function createApiClient(
    config = apiConfig,
    fetcher = globalThis.fetch,
) {
    // Bearer-токен хранится в памяти. Персистентность — задача вызывающего кода.
    let token = "";
    let unauthorizedHandler = null;

    /**
     * @param {string} path
     * @param {RequestOptions} [options]
     * @returns {Promise<unknown>}
     */
    async function request(
        path,
        { method = "GET", body, headers = {}, timeoutMs } = {},
    ) {
        const isMultipart =
            typeof FormData !== "undefined" && body instanceof FormData;
        const controller = new AbortController();
        const timer = setTimeout(
            () => controller.abort(),
            timeoutMs || config.timeoutMs || DEFAULT_TIMEOUT_MS,
        );

        try {
            const requestHeaders = buildHeaders({
                body,
                isMultipart,
                headers,
                token,
            });

            const response = await fetcher(
                stripTrailingSlashes(config.baseUrl) + path,
                {
                    method,
                    signal: controller.signal,
                    headers: requestHeaders,
                    ...(body !== undefined
                        ? { body: isMultipart ? body : JSON.stringify(body) }
                        : {}),
                },
            );

            const data = await parseResponse(response);

            if (!response.ok) {
                if (response.status === 401) {
                    unauthorizedHandler?.();
                }

                throw new ApiError(
                    extractErrorMessage(data, response.status),
                    response.status,
                    data,
                );
            }

            return data;
        } catch (error) {
            throw normalizeError(error, controller.signal.aborted);
        } finally {
            clearTimeout(timer);
        }
    }

    return {
        request,
        setToken: (value) => {
            token = value || "";
        },
        getToken: () => token,
        setUnauthorizedHandler: (handler) => {
            unauthorizedHandler = handler;
        },
    };
}

/**
 * @param {object} params
 * @param {unknown} params.body
 * @param {boolean} params.isMultipart
 * @param {Record<string, string>} params.headers
 * @param {string} params.token
 * @returns {Record<string, string>}
 */
function buildHeaders({ body, isMultipart, headers, token }) {
    const result = {
        Accept: ACCEPT_HEADER,
        ...headers,
    };

    if (body !== undefined && !isMultipart) {
        result["Content-Type"] = JSON_CONTENT_TYPE;
    }

    if (token) {
        result.Authorization = `Bearer ${token}`;
    }

    return result;
}

/**
 * @param {Response} response
 * @returns {Promise<unknown>}
 */
async function parseResponse(response) {
    const raw = await response.text();

    if (!raw) {
        return null;
    }

    try {
        return JSON.parse(raw);
    } catch {
        throw new ApiError(
            `API вернул ответ не в формате JSON (HTTP ${response.status}). Проверьте адрес API.`,
            response.status,
        );
    }
}

/**
 * @param {unknown} data
 * @param {number} status
 * @returns {string}
 */
function extractErrorMessage(data, status) {
    if (typeof data?.error === "string") {
        return data.error;
    }

    if (data?.error?.message) {
        return data.error.message;
    }

    if (data?.message) {
        return data.message;
    }

    return `Ошибка API: HTTP ${status}`;
}

/**
 * @param {unknown} error
 * @param {boolean} aborted
 * @returns {ApiError}
 */
function normalizeError(error, aborted) {
    if (error instanceof ApiError) {
        return error;
    }

    if (aborted) {
        return new ApiError(
            "API не ответил вовремя. Изменения остались в форме.",
        );
    }

    return new ApiError(
        "Не удалось подключиться к API. Проверьте соединение и настройки доступа.",
        0,
    );
}

export const http = createApiClient();
