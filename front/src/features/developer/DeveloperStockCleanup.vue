<script setup>
import { computed, onMounted, ref } from "vue";
import FormAlert from "../../components/FormAlert.vue";
import { marketplaceApi } from "../../api/marketplace.js";
import { money } from "../../data/permissions.js";

/*
 * Меню «Разработчик» → «Удаление Приходов и Реализаций» (только ЛК УК).
 * Документы всех организаций контура (УК и СТО) удаляются вместе со строками,
 * оплатами и движениями по складу (проведение отменяется). Перемещения «ЗЧ от УК»
 * сюда не входят — они удаляются вместе со своей ЗР.
 */

const emit = defineEmits(["deleted", "notify"]);

const CONFIRM_WORD = "УДАЛИТЬ";
const TYPE_LABELS = { receipt: "Приход", sale: "Реализация" };

const rows = ref([]);
const maxDelete = ref(200);
const loading = ref(false);
const error = ref("");
const search = ref("");
const type = ref("");
const organization = ref("");
const dateFrom = ref("");
const dateTo = ref("");
const selected = ref(new Set());
const dialogOpen = ref(false);
const confirmText = ref("");
const deleting = ref(false);
const progress = ref({ done: 0, total: 0 });
const result = ref(null);

const organizations = computed(() => [...new Set(rows.value.map((row) => row.organization))].sort());

const listed = computed(() => {
    const query = search.value.trim().toLowerCase();
    return rows.value.filter((row) => {
        if (type.value && row.type !== type.value) return false;
        if (organization.value && row.organization !== organization.value) return false;
        if (dateFrom.value && row.date < dateFrom.value) return false;
        if (dateTo.value && row.date > dateTo.value) return false;
        if (!query) return true;
        return [row.number, row.counterparty, row.warehouse, row.organization]
            .some((value) => String(value || "").toLowerCase().includes(query));
    });
});

const selectedRows = computed(() => rows.value.filter((row) => selected.value.has(row.id)));
const selectedStats = computed(() => ({
    receipts: selectedRows.value.filter((row) => row.type === "receipt").length,
    sales: selectedRows.value.filter((row) => row.type === "sale").length,
    posted: selectedRows.value.filter((row) => row.posted).length,
    payments: selectedRows.value.reduce((sum, row) => sum + (row.payments || 0), 0),
    postedReceipts: selectedRows.value.filter((row) => row.type === "receipt" && row.posted).length,
}));
const allListedSelected = computed(
    () => listed.value.length > 0 && listed.value.every((row) => selected.value.has(row.id)),
);
const someListedSelected = computed(
    () => !allListedSelected.value && listed.value.some((row) => selected.value.has(row.id)),
);
const canConfirm = computed(
    () => !deleting.value && confirmText.value.trim().toUpperCase() === CONFIRM_WORD && selectedRows.value.length > 0,
);

function formatDate(value) {
    const [year, month, day] = String(value || "").split("-");
    return day ? `${day}.${month}.${year}` : "—";
}

async function load() {
    loading.value = true;
    error.value = "";
    try {
        const data = await marketplaceApi.developerStockDocuments();
        rows.value = data.documents || [];
        maxDelete.value = data.maxDelete || 200;
        const ids = new Set(rows.value.map((row) => row.id));
        selected.value = new Set([...selected.value].filter((id) => ids.has(id)));
    } catch (exception) {
        error.value = exception.message || "Не удалось загрузить документы";
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
    // Сначала Реализации, затем Приходы: так остатки не уходят в минус между частями.
    const ids = [...selectedRows.value]
        .sort((a, b) => (a.type === b.type ? 0 : a.type === "sale" ? -1 : 1))
        .map((row) => row.id);
    deleting.value = true;
    error.value = "";
    progress.value = { done: 0, total: ids.length };
    const deleted = [];
    let payments = 0;
    try {
        for (let index = 0; index < ids.length; index += maxDelete.value) {
            const chunk = ids.slice(index, index + maxDelete.value);
            const data = await marketplaceApi.developerDeleteStockDocuments(chunk);
            deleted.push(...(data.deletedIds || []));
            payments += data.payments || 0;
            progress.value = { done: deleted.length, total: ids.length };
        }
    } catch (exception) {
        error.value = exception.message || "Не удалось удалить документы";
    } finally {
        deleting.value = false;
        if (deleted.length) {
            const gone = new Set(deleted);
            rows.value = rows.value.filter((row) => !gone.has(row.id));
            selected.value = new Set([...selected.value].filter((id) => !gone.has(id)));
            emit("deleted", deleted);
            emit("notify", `Удалено документов: ${deleted.length}, оплат: ${payments}`);
        }
        result.value = { deleted: deleted.length, payments, failed: ids.length - deleted.length };
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
                <h1>Удаление Приходов и Реализаций <span class="count">{{ listed.length }}</span></h1>
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
            Удалено документов: {{ result.deleted }}, оплат: {{ result.payments }}<template v-if="result.failed">, не удалено: {{ result.failed }}</template>.
        </p>
        <p class="list-hint developer-warning">
            Документ удаляется без возможности восстановления вместе со строками ЗЧ, оплатами (они пропадут из ДДС)
            и движениями по складу — проведение отменяется. Документы всех организаций: УК и СТО.
            Перемещения «ЗЧ от УК» удаляются вместе со своей ЗР в пункте «Удаление ЗР».
        </p>
        <div class="developer-filters">
            <label>
                <span>Вид</span>
                <select v-model="type">
                    <option value="">Приходы и Реализации</option>
                    <option value="receipt">Приход</option>
                    <option value="sale">Реализация</option>
                </select>
            </label>
            <label>
                <span>Организация</span>
                <select v-model="organization">
                    <option value="">Все организации</option>
                    <option v-for="name in organizations" :key="name" :value="name">{{ name }}</option>
                </select>
            </label>
            <label>
                <span>Дата с</span>
                <input v-model="dateFrom" type="date" />
            </label>
            <label>
                <span>по</span>
                <input v-model="dateTo" type="date" />
            </label>
            <label class="developer-search">
                <span>Поиск</span>
                <input v-model="search" type="search" placeholder="Номер, Поставщик / Заказчик, склад" />
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
                                aria-label="Выбрать все документы в списке"
                                @change="toggleAll"
                            />
                        </th>
                        <th>Номер</th>
                        <th>Вид</th>
                        <th>Дата</th>
                        <th>Статус</th>
                        <th>Организация</th>
                        <th>Поставщик / Заказчик</th>
                        <th>Склад</th>
                        <th class="align-right">Сумма, ₽</th>
                        <th class="align-right">Оплачено, ₽</th>
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
                        <td>{{ TYPE_LABELS[row.type] }}</td>
                        <td>{{ formatDate(row.date) }}</td>
                        <td>{{ row.posted ? "Проведён" : "Не проведён" }}</td>
                        <td>{{ row.organization }}</td>
                        <td>{{ row.counterparty || "—" }}</td>
                        <td>{{ row.warehouse || "—" }}</td>
                        <td class="align-right">{{ money(row.amount) }}</td>
                        <td class="align-right">{{ row.paid ? money(row.paid) : "—" }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="!listed.length" class="empty-state">
            <h3>{{ loading ? "Загружаем документы…" : "Документы не найдены" }}</h3>
        </div>

        <Teleport to="body">
            <div v-if="dialogOpen" class="developer-backdrop" @click.self="closeDialog">
                <div class="developer-dialog" role="alertdialog" aria-modal="true" aria-labelledby="developer-stock-title">
                    <h2 id="developer-stock-title">Удалить документы без возможности восстановления?</h2>
                    <p>
                        Будет удалено документов: <strong>{{ selectedRows.length }}</strong>
                        (Приходов: {{ selectedStats.receipts }}, Реализаций: {{ selectedStats.sales }}),
                        проведённых: {{ selectedStats.posted }}, оплат: <strong>{{ selectedStats.payments }}</strong>.
                    </p>
                    <p>Проведение отменяется: остатки на складах пересчитаются, оплаты пропадут из ДДС.</p>
                    <p v-if="selectedStats.postedReceipts" class="developer-note">
                        Если ЗЧ из удаляемых Приходов уже списаны в ЗР или Реализациях, остаток по ним уйдёт в минус.
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
                            Удалить {{ selectedRows.length }} док.
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
.developer-note {
    color: #b71c1c;
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
