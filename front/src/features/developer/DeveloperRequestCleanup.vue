<script setup>
import { computed, onMounted, ref } from "vue";
import FormAlert from "../../components/FormAlert.vue";
import { marketplaceApi } from "../../api/marketplace.js";
import { workflow } from "../../data/workflow.js";
import { formatDateTime } from "../../data/permissions.js";

/*
 * Меню «Разработчик» → «Удаление ЗР» (только ЛК УК).
 * ЗР удаляется целиком: работы, ЗЧ, история, документы, оплаты, акты, чат,
 * Рекомендации из этой ЗР, складские движения и все файлы на сервере.
 * Удаление необратимо, поэтому подтверждается вводом слова «УДАЛИТЬ».
 */

const emit = defineEmits(["deleted", "notify"]);

const CONFIRM_WORD = "УДАЛИТЬ";

const rows = ref([]);
const maxDelete = ref(200);
const loading = ref(false);
const error = ref("");
const search = ref("");
const stage = ref("");
const numberFrom = ref("");
const numberTo = ref("");
const selected = ref(new Set());
const dialogOpen = ref(false);
const confirmText = ref("");
const deleting = ref(false);
const progress = ref({ done: 0, total: 0 });
const result = ref(null);

const stages = workflow.map((item, index) => ({ value: index, label: `${index}. ${item.status}` }));

const numberOf = (row) => Number(row.systemId) || Number(String(row.number).replace(/\D/g, "")) || 0;
const parseNumber = (value) => {
    const digits = String(value || "").replace(/\D/g, "");
    return digits ? Number(digits) : null;
};

const listed = computed(() => {
    const query = search.value.trim().toLowerCase();
    const from = parseNumber(numberFrom.value);
    const to = parseNumber(numberTo.value);
    return rows.value.filter((row) => {
        if (stage.value !== "" && row.stage !== Number(stage.value)) return false;
        const number = numberOf(row);
        if (from !== null && number < from) return false;
        if (to !== null && number > to) return false;
        if (!query) return true;
        return [row.number, row.customer, row.station, row.vehicle]
            .some((value) => String(value || "").toLowerCase().includes(query));
    });
});

const selectedRows = computed(() => rows.value.filter((row) => selected.value.has(row.id)));
const selectedFiles = computed(() => selectedRows.value.reduce((sum, row) => sum + (row.files || 0), 0));
const allListedSelected = computed(
    () => listed.value.length > 0 && listed.value.every((row) => selected.value.has(row.id)),
);
const someListedSelected = computed(
    () => !allListedSelected.value && listed.value.some((row) => selected.value.has(row.id)),
);
const selectedRange = computed(() => {
    const numbers = selectedRows.value.map((row) => row.number).sort();
    if (!numbers.length) return "";
    return numbers.length === 1 ? numbers[0] : `${numbers[0]} … ${numbers[numbers.length - 1]}`;
});
const canConfirm = computed(
    () => !deleting.value && confirmText.value.trim().toUpperCase() === CONFIRM_WORD && selectedRows.value.length > 0,
);

function stageLabel(value) {
    return workflow[value]?.status || `Этап ${value}`;
}

async function load() {
    loading.value = true;
    error.value = "";
    try {
        const data = await marketplaceApi.developerRequests();
        rows.value = data.requests || [];
        maxDelete.value = data.maxDelete || 200;
        const ids = new Set(rows.value.map((row) => row.id));
        selected.value = new Set([...selected.value].filter((id) => ids.has(id)));
    } catch (exception) {
        error.value = exception.message || "Не удалось загрузить список ЗР";
    } finally {
        loading.value = false;
    }
}

function toggle(row) {
    const next = new Set(selected.value);
    if (next.has(row.id)) next.delete(row.id);
    else next.add(row.id);
    selected.value = next;
}

function toggleAll() {
    const next = new Set(selected.value);
    if (allListedSelected.value) listed.value.forEach((row) => next.delete(row.id));
    else listed.value.forEach((row) => next.add(row.id));
    selected.value = next;
}

function clearSelection() {
    selected.value = new Set();
}

function openDialog() {
    if (!selectedRows.value.length) return;
    confirmText.value = "";
    result.value = null;
    dialogOpen.value = true;
}

function closeDialog() {
    if (deleting.value) return;
    dialogOpen.value = false;
}

async function confirmDelete() {
    if (!canConfirm.value) return;
    const ids = selectedRows.value.map((row) => row.id);
    deleting.value = true;
    error.value = "";
    progress.value = { done: 0, total: ids.length };
    const deleted = [];
    let files = 0;
    try {
        // Большие наборы удаляются частями: каждая часть — отдельная транзакция.
        for (let index = 0; index < ids.length; index += maxDelete.value) {
            const chunk = ids.slice(index, index + maxDelete.value);
            const data = await marketplaceApi.developerDeleteRequests(chunk);
            deleted.push(...(data.deletedIds || []));
            files += data.files || 0;
            progress.value = { done: deleted.length, total: ids.length };
        }
    } catch (exception) {
        error.value = exception.message || "Не удалось удалить ЗР";
    } finally {
        deleting.value = false;
        if (deleted.length) {
            const gone = new Set(deleted);
            rows.value = rows.value.filter((row) => !gone.has(row.id));
            selected.value = new Set([...selected.value].filter((id) => !gone.has(id)));
            emit("deleted", deleted);
            emit("notify", `Удалено ЗР: ${deleted.length}, файлов: ${files}`);
        }
        result.value = { deleted: deleted.length, files, failed: ids.length - deleted.length };
        if (!error.value) dialogOpen.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="developer-cleanup">
        <div class="list-heading">
            <div>
                <span class="eyebrow">Разработчик</span>
                <h1>Удаление ЗР <span class="count">{{ listed.length }}</span></h1>
            </div>
            <div class="developer-actions">
                <button class="ghost-btn" :disabled="loading || deleting" @click="load">Обновить</button>
                <button class="ghost-btn" :disabled="!selected.size || deleting" @click="clearSelection">Снять выбор</button>
                <button class="ghost-btn danger-btn developer-delete" :disabled="!selected.size || deleting" @click="openDialog">
                    Удалить выбранные ({{ selected.size }})
                </button>
            </div>
        </div>
        <FormAlert v-if="error" class="error-message">{{ error }}</FormAlert>
        <p v-if="result && !dialogOpen" class="developer-result">
            Удалено ЗР: {{ result.deleted }}, файлов: {{ result.files }}<template v-if="result.failed">, не удалено: {{ result.failed }}</template>.
        </p>
        <p class="list-hint developer-warning">
            ЗР удаляется полностью и без возможности восстановления: работы и ЗЧ, история и согласования,
            документы закрытия и оплаты, акты, заказ-наряд, документы ЭДО, чат, уведомления, строки
            Рекомендаций из этой ЗР, складские списания и Перемещения «ЗЧ от УК» (остатки возвращаются),
            а также все вложенные файлы на сервере.
        </p>
        <div class="developer-filters">
            <label>
                <span>Номер с</span>
                <input v-model="numberFrom" type="text" inputmode="numeric" placeholder="ZR-000013" />
            </label>
            <label>
                <span>по</span>
                <input v-model="numberTo" type="text" inputmode="numeric" placeholder="ZR-000955" />
            </label>
            <label>
                <span>Этап</span>
                <select v-model="stage">
                    <option value="">Все этапы</option>
                    <option v-for="item in stages" :key="item.value" :value="item.value">{{ item.label }}</option>
                </select>
            </label>
            <label class="developer-search">
                <span>Поиск</span>
                <input v-model="search" type="search" placeholder="Номер, Заказчик, СТО, ТС" />
            </label>
        </div>
        <div class="registry-wrap">
            <table class="registry developer-table">
                <thead>
                    <tr>
                        <th class="developer-check">
                            <input
                                type="checkbox"
                                :checked="allListedSelected"
                                :indeterminate.prop="someListedSelected"
                                :disabled="!listed.length"
                                aria-label="Выбрать все ЗР в списке"
                                @change="toggleAll"
                            />
                        </th>
                        <th>Номер</th>
                        <th>Этап</th>
                        <th>Заказчик</th>
                        <th>СТО</th>
                        <th>ТС</th>
                        <th class="align-right">Файлов</th>
                        <th>Создана</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in listed"
                        :key="row.id"
                        :class="{ selected: selected.has(row.id) }"
                        @click="toggle(row)"
                    >
                        <td class="developer-check" @click.stop>
                            <input
                                type="checkbox"
                                :checked="selected.has(row.id)"
                                :aria-label="`Выбрать ${row.number}`"
                                @change="toggle(row)"
                            />
                        </td>
                        <td><strong>{{ row.number }}</strong></td>
                        <td>{{ stageLabel(row.stage) }}</td>
                        <td>{{ row.customer || "—" }}</td>
                        <td>{{ row.station || "—" }}</td>
                        <td>{{ row.vehicle || "—" }}</td>
                        <td class="align-right">{{ row.files }}</td>
                        <td>{{ row.createdAt ? formatDateTime(row.createdAt) : "—" }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="!listed.length" class="empty-state">
            <h3>{{ loading ? "Загружаем ЗР…" : "ЗР не найдены" }}</h3>
        </div>

        <Teleport to="body">
            <div v-if="dialogOpen" class="developer-backdrop" @click.self="closeDialog">
                <div class="developer-dialog" role="alertdialog" aria-modal="true" aria-labelledby="developer-dialog-title">
                    <h2 id="developer-dialog-title">Удалить ЗР без возможности восстановления?</h2>
                    <p>
                        Будет удалено ЗР: <strong>{{ selectedRows.length }}</strong>
                        <template v-if="selectedRange"> ({{ selectedRange }})</template>,
                        вложенных файлов: <strong>{{ selectedFiles }}</strong>.
                    </p>
                    <p>
                        Вместе с ЗР удаляются все документы, оплаты, акты, чат, строки Рекомендаций и складские
                        движения по ним. Отменить удаление нельзя.
                    </p>
                    <FormAlert v-if="error" class="error-message">{{ error }}</FormAlert>
                    <label class="developer-confirm">
                        <span>Для подтверждения введите «{{ CONFIRM_WORD }}»</span>
                        <input
                            v-model="confirmText"
                            type="text"
                            autocomplete="off"
                            :disabled="deleting"
                            @keydown.enter.prevent="confirmDelete"
                        />
                    </label>
                    <p v-if="deleting" class="developer-progress">
                        Удаляем… {{ progress.done }} из {{ progress.total }}
                    </p>
                    <div class="developer-dialog-buttons">
                        <button class="ghost-btn" :disabled="deleting" @click="closeDialog">Отмена</button>
                        <button class="ghost-btn danger-btn developer-delete" :disabled="!canConfirm" @click="confirmDelete">
                            Удалить {{ selectedRows.length }} ЗР
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.developer-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}
.developer-warning {
    margin: 0 24px 12px;
}
.developer-result {
    margin: 0 24px 12px;
    font-weight: 600;
}
.developer-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
    margin: 0 0 12px;
    padding: 0 24px;
}
.developer-filters label {
    min-width: 140px;
}
.developer-search {
    flex: 1;
    max-width: 420px;
}
.developer-table tbody tr {
    cursor: pointer;
}
.developer-table tbody tr.selected td {
    background: #fdecea;
}
.developer-check {
    width: 36px;
    text-align: center;
}
.developer-delete:not(:disabled) {
    background: #c62828;
    border-color: #c62828;
    color: #fff;
}
.developer-delete:hover:not(:disabled) {
    background: #a61f1f;
}
.developer-backdrop {
    position: fixed;
    inset: 0;
    z-index: 2000;
    display: grid;
    place-items: center;
    padding: 16px;
    background: rgb(18 34 56 / 55%);
}
.developer-dialog {
    width: min(520px, 100%);
    max-height: 90dvh;
    overflow: auto;
    background: #fff;
    border-radius: 12px;
    padding: 22px;
    box-shadow: 0 20px 60px rgb(10 30 60 / 35%);
}
.developer-dialog h2 {
    margin: 0 0 12px;
    font-size: 18px;
    color: #b71c1c;
}
.developer-confirm {
    display: grid;
    gap: 6px;
    margin: 14px 0;
}
.developer-progress {
    font-weight: 600;
}
.developer-dialog-buttons {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 12px;
}
</style>
