<script>
import { marketplaceApi } from "../../api/marketplace.js";
import { formatDateTime } from "../../data/permissions.js";
import { matchesReference } from "../../utils/referenceSearch.js";
import EdoSettingsModal from "./EdoSettingsModal.vue";
import EdoSignModal from "./EdoSignModal.vue";

/** Столбцы реестра: ключ → заголовок. Порядок = порядок в таблице. */
const COLUMNS = {
    createdAt: "Создан",
    typeLabel: "Тип",
    number: "Номер",
    request: "Заявка",
    counterparty: "Контрагент",
    directionLabel: "Направление",
    statusLabel: "Статус",
    expectedAction: "Ожидаемое действие",
};

const FILTER_PLACEHOLDERS = {
    createdAt: "Дата",
    request: "ZR-…",
};

/** Цвет статуса документа (классы .status.* из styles.css). */
const STATUS_COLORS = {
    draft: "amber",
    to_sign: "amber",
    prepared: "amber",
    waiting_for_send: "violet",
    sended: "violet",
    delivered: "violet",
    received: "violet",
    on_agreement: "violet",
    signed: "green",
    accepted: "green",
    rejected: "red",
    clarification_requested: "red",
    requested_avoidance: "red",
    on_revocation: "amber",
    revoked: "gray",
    accepted_avoidance: "gray",
    rejected_avoidance: "gray",
    unknown: "gray",
};

/** Подпись строки фильтра/сортировки для ячейки. */
function edoCellText(row, key) {
    if (key === "createdAt") return formatDateTime(row.createdAt);
    if (key === "request") return row.request?.number || "—";
    return row[key] ?? "—";
}

/** Значение для сортировки: даты — по времени, остальное — по тексту. */
function sortValue(row, key) {
    if (key === "createdAt") return row.createdAt || "";
    return String(edoCellText(row, key)).toLocaleLowerCase("ru-RU");
}

/**
 * Реестр «Документы ЭДО»: только документы, записанные у оператора ЭДО,
 * в стиле справочников (фильтр под каждым столбцом), новые сверху.
 */
export default {
    name: "EdoDocuments",
    components: { EdoSettingsModal, EdoSignModal },
    emits: ["open-request"],
    data() {
        return {
            rows: [],
            loading: true,
            error: "",
            filters: {},
            selection: "",
            sort: { key: "createdAt", desc: true },
            showSettings: false,
            signing: null,
            notice: "",
            columns: COLUMNS,
        };
    },
    computed: {
        listed() {
            const { key, desc } = this.sort;
            return this.rows
                .filter((row) =>
                    Object.entries(this.filters).every(([field, value]) =>
                        matchesReference(edoCellText(row, field), value),
                    ),
                )
                .sort((a, b) => {
                    const left = sortValue(a, key);
                    const right = sortValue(b, key);
                    const order = left < right ? -1 : left > right ? 1 : 0;
                    return desc ? -order : order;
                });
        },
        waitingForMe() {
            return this.rows.filter((row) => row.actionOwner === "me").length;
        },
    },
    mounted() {
        this.load();
    },
    methods: {
        formatDateTime,
        cell: edoCellText,
        /** «Обновить»: сначала забираем изменения из СБИС, затем список. */
        async refresh() {
            this.loading = true;
            this.error = "";
            try {
                await marketplaceApi.refreshSbisChanges();
            } catch (error) {
                this.error = "СБИС: " + (error.message || "не удалось получить изменения");
            }
            await this.load(false);
        },
        async load(resetError = true) {
            this.loading = true;
            if (resetError) this.error = "";
            try {
                const response = await marketplaceApi.listEdoDocuments();
                const documents = response.documents?.data ?? response.documents;
                this.rows = Array.isArray(documents) ? documents : [];
            } catch (error) {
                this.error = error.message || "Не удалось загрузить документы ЭДО";
            } finally {
                this.loading = false;
            }
        },
        sortBy(key) {
            this.sort =
                this.sort.key === key
                    ? { key, desc: !this.sort.desc }
                    : { key, desc: key === "createdAt" };
        },
        sortMark(key) {
            if (this.sort.key !== key) return "";
            return this.sort.desc ? " ↓" : " ↑";
        },
        placeholder(key) {
            return FILTER_PLACEHOLDERS[key] || "Фильтр";
        },
        statusColor(row) {
            return STATUS_COLORS[row.status] || "gray";
        },
        signed(document) {
            const index = this.rows.findIndex((row) => row.id === document.id);
            if (index >= 0) this.rows.splice(index, 1, document);
            this.notice = `${document.number}: ${document.statusLabel}`;
            this.signing = null;
        },
        openRequest(row) {
            if (row.request?.id) this.$emit("open-request", row.request.id);
        },
    },
};
</script>

<template>
    <div class="edo-registry">
        <div class="list-heading">
            <div>
                <span class="eyebrow">Документы</span>
                <h1>
                    Документы ЭДО <span class="count">{{ listed.length }}</span>
                </h1>
            </div>
            <div class="list-heading-actions">
                <button class="ghost-btn" :disabled="loading" @click="refresh">
                    {{ loading ? "Загрузка…" : "Обновить" }}
                </button>
                <button
                    class="ghost-btn edo-settings-btn"
                    title="Настройки подключения к ЭДО"
                    aria-label="Настройки подключения к ЭДО"
                    @click="showSettings = true"
                >
                    ⚙️
                </button>
            </div>
        </div>
        <p class="list-hint">
            Только документы, переданные оператору ЭДО · новые сверху · клик по
            заголовку — сортировка
            <template v-if="waitingForMe">
                · <strong class="edo-waiting">ждут вашего действия: {{ waitingForMe }}</strong>
            </template>
        </p>
        <div v-if="error" class="edo-error">{{ error }}</div>
        <div v-if="notice" class="edo-notice">{{ notice }}</div>
        <div class="registry-wrap">
            <table class="registry edo-table">
                <thead>
                    <tr>
                        <th
                            v-for="(name, key) in columns"
                            :key="key"
                            class="edo-sortable"
                            :aria-sort="sort.key === key ? (sort.desc ? 'descending' : 'ascending') : 'none'"
                            @click="sortBy(key)"
                        >
                            {{ name }}{{ sortMark(key) }}
                        </th>
                        <th />
                    </tr>
                    <tr class="column-filter-row">
                        <th v-for="(name, key) in columns" :key="key">
                            <input
                                v-model="filters[key]"
                                :aria-label="`Фильтр: ${name}`"
                                :placeholder="placeholder(key)"
                            />
                        </th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in listed"
                        :key="row.id"
                        tabindex="0"
                        :class="{ selected: selection === row.id }"
                        @click="selection = row.id"
                        @dblclick="openRequest(row)"
                        @keydown.enter="openRequest(row)"
                    >
                        <td v-for="(name, key) in columns" :key="key">
                            <template v-if="key === 'number'">
                                <strong>{{ row.number }}</strong>
                                <small v-if="row.externalId" class="edo-external" :title="row.externalId">
                                    {{ row.providerLabel }} · {{ row.externalId }}
                                </small>
                            </template>
                            <button
                                v-else-if="key === 'request' && row.request"
                                type="button"
                                class="link-btn"
                                :title="`Открыть заявку ${row.request.number}`"
                                @click.stop="openRequest(row)"
                            >
                                {{ row.request.number }}
                            </button>
                            <span
                                v-else-if="key === 'statusLabel'"
                                class="status"
                                :class="statusColor(row)"
                            >{{ row.statusLabel }}</span>
                            <span
                                v-else-if="key === 'expectedAction'"
                                class="edo-action"
                                :class="row.actionOwner ? `edo-action-${row.actionOwner}` : 'edo-action-none'"
                            >{{ row.expectedAction }}</span>
                            <template v-else>{{ cell(row, key) }}</template>
                        </td>
                        <td class="edo-row-actions">
                            <button
                                v-if="row.canSign"
                                type="button"
                                class="primary-btn edo-sign-btn"
                                :title="`${row.signLabel} УКЕП`"
                                @click.stop="signing = row"
                            >✍ {{ row.signLabel }}</button>
                            <a
                                v-if="row.url"
                                :href="row.url"
                                target="_blank"
                                rel="noopener"
                                class="open-row"
                                title="Открыть в СБИС"
                                @click.stop
                            >↗</a>
                            <a
                                v-if="row.pdfUrl"
                                :href="row.pdfUrl"
                                target="_blank"
                                rel="noopener"
                                class="open-row"
                                title="Скачать файл документа"
                                @click.stop
                            >📄</a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="!listed.length" class="empty-state">
            <h3>{{ loading ? "Загружаем документы…" : "Документов ЭДО пока нет" }}</h3>
            <p>
                {{
                    rows.length
                        ? "Измените параметры фильтрации."
                        : "Здесь появятся документы, записанные у оператора ЭДО (ЗН, Счёт, УПД по заявкам)."
                }}
            </p>
        </div>
        <EdoSignModal
            v-if="signing"
            :document="signing"
            @close="signing = null"
            @signed="signed"
        />
        <EdoSettingsModal
            v-if="showSettings"
            @close="showSettings = false"
            @connected="load"
        />
    </div>
</template>

<style scoped>
.edo-sortable {
    cursor: pointer;
    user-select: none;
}

.edo-sortable:hover {
    color: var(--accent, #1f5fbf);
}

.edo-external {
    display: block;
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--muted);
    font-size: 11px;
}

.link-btn {
    padding: 0;
    border: 0;
    background: none;
    color: var(--accent, #1f5fbf);
    font: inherit;
    font-weight: 650;
    text-decoration: underline;
    cursor: pointer;
}

.edo-action {
    font-size: 13px;
}

.edo-action-me {
    color: #c2560c;
    font-weight: 650;
}

.edo-action-counterparty,
.edo-action-operator {
    color: #5f6f84;
}

.edo-action-none {
    color: var(--muted);
}

.edo-waiting {
    color: #c2560c;
}

.edo-row-actions {
    white-space: nowrap;
}

.edo-row-actions .open-row {
    text-decoration: none;
}

.edo-notice {
    margin-bottom: 12px;
    padding: 10px 12px;
    border-radius: 4px;
    background: #dcf1e8;
    color: #267353;
}

.edo-sign-btn {
    min-height: 28px;
    padding: 3px 10px;
    font-size: 12px;
    white-space: nowrap;
}

.edo-error {
    margin-bottom: 12px;
    padding: 10px 12px;
    border-radius: 4px;
    background: #fbe3e2;
    color: #a32d29;
}

.edo-settings-btn {
    min-width: 40px;
    padding-inline: 8px;
}
</style>
