<script setup>
import { computed, nextTick, onMounted, ref, toRaw, watch } from "vue";
import FormAlert from "../../components/FormAlert.vue";
import { marketplaceApi } from "../../api/marketplace.js";
import { money, agentDiscountPercent, agentDiscountAmount } from "../../data/permissions.js";
import { startColumnResize } from "../../utils/columnResize.js";
import WorkTypePicker from "./WorkTypePicker.vue";
import PartLineDialog from "./PartLineDialog.vue";
const props = defineProps({
    request: { type: Object, required: true },
    suppliers: { type: Array, default: () => [] },
    catalogParts: { type: Array, default: () => [] },
    partUnits: { type: Array, default: () => [] },
    busy: Boolean,
    perform: { type: Function, required: true },
    canCreateCatalogPart: Boolean,
    selectedIds: { type: Array, default: () => [] },
    selectable: Boolean,
    customerView: Boolean,
    stationAgentPercent: { type: Number, default: null },
    /* Изменения на стадии «В работе»: столбец примечаний и подсветка строк. */
    showChangeNotes: Boolean,
    canChangeInWork: Boolean,
    changeItems: { type: Map, default: () => new Map() },
});
const emit = defineEmits(["create-part", "touch", "update:selectedIds"]);
const STATUS_LABELS = {
    proposed: "На согласовании",
    approved: "Согласована",
    ordered: "Заказана",
    received: "Получена",
    installed: "Установлена",
    returned: "Возвращена",
    exchanged: "Обменена",
};
const OPERATION_RETURN = "return";
const OPERATION_EXCHANGE = "exchange";
const error = ref("");
const operation = ref(null);
const comment = ref("");
const qty = ref(null);
const replacementPartId = ref("");
let sequence = 0;
function emptyPart() {
    return {
        _clientId: `part-${++sequence}`,
        catalogPartId: "",
        name: "",
        article: "",
        manufacturer: "",
        unit: props.partUnits[0] || "шт.",
        supplierId: "",
        qty: 1,
        price: 0,
        status: "proposed",
        comment: "",
        changeNote: "",
    };
}
const placeholder = ref(emptyPart());
/* Складской учёт: наличие ЗЧ на складах СТО, назначенной в ЗР (не для Заказчика). */
const stock = ref(null);
async function loadStock() {
    if (props.customerView) return;
    try {
        // СТО берётся из формы: она может быть выбрана, но ещё не сохранена.
        stock.value = await marketplaceApi.requestStock(props.request.id, props.request.stationId);
    } catch {
        stock.value = null;
    }
    fillStockPrices();
}
/* Строки, выбранные до загрузки остатков (или до выбора СТО), получают цену склада. */
function fillStockPrices() {
    if (!stock.value?.available) return;
    for (const row of props.request.parts || []) {
        const info = stock.value.parts[row.catalogPartId];
        if (!info || !editable(row) || (row.id && !row._stockDirty) || Number(row.price || 0) > 0) continue;
        if (info.salePrice != null) row.price = info.salePrice;
        if (!row.supplierId && info.supplierId) row.supplierId = info.supplierId;
    }
}
onMounted(loadStock);
watch(() => [props.request.id, props.request.revision, props.request.stationId], loadStock);
function stockQty(value) {
    return Number(value || 0).toLocaleString("ru-RU", { maximumFractionDigits: 3 });
}
function stockState(row) {
    if (!stock.value?.available || props.customerView) return null;
    const need = Number(row.qty || 0) - Number(row.returnedQty || 0);
    if (row.id && stock.value.rows[row.id] && !row._stockDirty) {
        const state = stock.value.rows[row.id];
        if (state.writtenOff) return { label: "Списано со склада", kind: "written" };
        if (state.sufficient) return { label: stock.value.reserving ? "В резерве" : "Есть на складе", kind: "ok" };
        return { label: `Нет на складе (есть ${stockQty(state.covered)})`, kind: "missing" };
    }
    if (!row.catalogPartId) return row.name ? { label: "Нет на складе", kind: "missing" } : null;
    const free = Number(stock.value.parts[row.catalogPartId]?.free || 0);
    return free + 0.0005 >= need
        ? { label: "Есть на складе", kind: "ok" }
        : { label: `Нет на складе (есть ${stockQty(free)})`, kind: "missing" };
}
const hasMissingStock = computed(() => rows.value.some((row) => stockState(row)?.kind === "missing"));
const canEditParts = computed(
    () => props.request.permissions?.canEditParts === true,
);
const canManageParts = computed(
    () => props.request.permissions?.canManageParts === true,
);
const rows = computed(() =>
    props.request.parts.length
        ? props.request.parts
        : canEditParts.value
          ? [placeholder.value]
          : [],
);
const catalogOptions = computed(() =>
    props.catalogParts
        .filter((p) => p.active !== false)
        .map((p) => ({
            ...p,
            optionLabel: [
                p.name,
                p.article,
                p.manufacturer,
                stock.value?.available
                    ? `на складе: ${stockQty(stock.value.parts[p.id]?.free || 0)}`
                    : "",
            ]
                .filter(Boolean)
                .join(" · "),
        })),
);
const replacementParts = computed(() =>
    (props.request.parts || []).filter(
        (p) =>
            p.id !== operation.value?.part.id &&
            p.approvedVersion === props.request.estimateVersion,
    ),
);
/* Агентская скидка СТО: только ЛК УК и ЛК СТО, в заявке не редактируется. */
function rowAgentPercent(row) {
    return agentDiscountPercent(row, props.request, props.stationAgentPercent);
}
function rowAgentDiscount(row) {
    return agentDiscountAmount(
        Number(row.qty || 0) * Number(row.price || 0),
        rowAgentPercent(row),
    );
}
/** Сумма строки: Заказчику — полная, в ЛК УК и ЛК СТО — за вычетом агентской скидки. */
function rowAmount(row) {
    const amount = Number(row.qty || 0) * Number(row.price || 0);
    if (props.customerView) return amount;
    return Math.round((amount - (rowAgentDiscount(row) ?? 0)) * 100) / 100;
}
function editable(row) {
    return (
        canEditParts.value &&
        ["proposed", "approved", undefined].includes(row.status)
    );
}
function lineChange(row) {
    if (!props.showChangeNotes) return "";
    if (!row.id) return props.canChangeInWork && props.request.parts.includes(row) ? "added" : "";
    return props.changeItems.get(`part:${row.id}`)?.action || "";
}
function ensureRow(row) {
    if (!props.request.parts.includes(row)) props.request.parts.push(row);
}
function changed(row) {
    ensureRow(row);
    row._stockDirty = true;
    emit("touch");
}
function selectPart(row, part) {
    Object.assign(row, {
        catalogPartId: part.id,
        name: part.name,
        article: part.article || "",
        manufacturer: part.manufacturer || "",
        unit: part.unit || "шт.",
        // Цена реализации и поставщик — по остаткам склада СТО с учётом метода списания.
        supplierId: stock.value?.parts[part.id]?.supplierId || row.supplierId || "",
        price: stock.value?.parts[part.id]?.salePrice ?? row.price ?? 0,
    });
    changed(row);
}
function nameEdited(row, name) {
    row.name = name;
    row.catalogPartId = "";
    changed(row);
}
function createPart(row, name) {
    ensureRow(row);
    emit("create-part", {
        name,
        article: row.article || "",
        unit: row.unit,
        partId: row.id,
        clientId: row._clientId,
        index: props.request.parts.indexOf(row),
    });
}
async function addPartRow() {
    props.request.parts.push(emptyPart());
    emit("touch");
    await nextTick();
    document
        .querySelector(".parts-grid tbody tr:last-child input")
        ?.focus({ preventScroll: true });
}
function remove(row) {
    const i = props.request.parts.indexOf(row);
    if (i >= 0) {
        props.request.parts.splice(i, 1);
        emit("touch");
    }
}
function toggle(row, checked) {
    emit(
        "update:selectedIds",
        checked
            ? [...props.selectedIds, row.id]
            : props.selectedIds.filter((id) => id !== row.id),
    );
}
function statusLabel(value) {
    return STATUS_LABELS[value] || value || "Новая";
}
function supplierName(id) {
    return props.suppliers.find((s) => s.id === id)?.name || "—";
}
function movementLabel(kind) {
    return kind === "return" ? "Возврат" : "Обмен";
}
function isQuantityOperation(kind) {
    return ["return", "exchange"].includes(kind);
}
function isStatusAction(kind) {
    return ["ordered", "received"].includes(kind);
}
function start(part, kind) {
    operation.value = { part, kind };
    comment.value = "";
    qty.value = null;
    replacementPartId.value = "";
}

function cancelOperation() {
    operation.value = null;
}

/* Карточка ЗЧ: двойное нажатие по строке (кроме флажка и кнопки удаления). */
const dialogPart = ref(null);
const dialogPosition = ref(0);
function openPartDialog(row, event, index) {
    if (event?.target?.closest?.(".selection-checkbox, .delete-row")) return;
    dialogPart.value = row;
    dialogPosition.value = index + 1;
}
watch(() => props.request.revision, () => {
    if (dialogPart.value && !rows.value.some((row) => toRaw(row) === toRaw(dialogPart.value))) dialogPart.value = null;
});
function partActions(row) {
    if (!canManageParts.value) return [];
    const list = [];
    if (row.status === "approved") list.push({ kind: "ordered", label: "Заказать" });
    if (row.status === "ordered") list.push({ kind: "received", label: "Получена" });
    if (["ordered", "received"].includes(row.status)) {
        list.push({ kind: "return", label: "Возврат" }, { kind: "exchange", label: "Обмен" });
    }
    return list;
}
function dialogAction(kind) {
    const row = dialogPart.value;
    dialogPart.value = null;
    start(row, kind);
}
function freeQty(row) {
    if (!stock.value?.available || !row.catalogPartId) return null;
    const free = stock.value.parts[row.catalogPartId]?.free;
    return free == null ? null : Number(free);
}

async function apply() {
    error.value = "";

    try {
        const { part, kind } = operation.value;

        if (isStatusAction(kind)) {
            await props.perform("partStatus", part.id, {
                status: kind,
                comment: comment.value,
            });
        } else {
            await props.perform("partMovement", part.id, {
                kind,
                qty: qty.value,
                comment: comment.value,
                replacementPartId: replacementPartId.value,
                attachmentIds: [],
            });
        }

        operation.value = null;
    } catch (exception) {
        error.value = exception.message;
    }
}
</script>
<template>
    <div class="parts-panel estimate-section">
        <h3>Запчасти</h3>
        <FormAlert v-if="error" class="error-message">{{ error }}</FormAlert>
        <div class="table-wrap excel-grid-wrap">
            <table
                class="excel-grid parts-grid"
                :class="{ 'with-change-notes': showChangeNotes }"
            >
                <colgroup>
                    <col v-if="selectable" style="width: 26px" />
                    <col />
                    <col style="width: 84px" />
                    <col style="width: 88px" />
                    <col style="width: 38px" />
                    <col style="width: 58px" />
                    <col style="width: 74px" />
                    <col v-if="!customerView" style="width: 84px" />
                    <col v-if="showChangeNotes" style="width: 132px" />
                    <col style="width: 80px" />
                    <col style="width: 26px" />
                </colgroup>
                <thead>
                    <tr>
                        <th v-if="selectable" class="selection-col" />
                        <th
                            v-for="title in [
                                'Наименование',
                                'Артикул',
                                'Производитель',
                                'Ед.',
                                'Кол-во',
                                'Цена, ₽',
                                ...(!customerView
                                    ? ['Агентская скидка, ₽']
                                    : []),
                                ...(showChangeNotes
                                    ? ['Примечание к изменению']
                                    : []),
                                'Сумма, ₽',
                            ]"
                            :key="title"
                            class="resizable-column"
                        >
                            {{ title
                            }}<span
                                class="column-resize-handle"
                                @pointerdown="startColumnResize"
                            />
                        </th>
                        <th class="row-command-column" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, index) in rows"
                        :key="row.id || row._clientId"
                        class="part-draft-row"
                        title="Двойное нажатие — карточка запчасти"
                        @dblclick="openPartDialog(row, $event, index)"
                        :class="[
                            lineChange(row) ? 'line-' + lineChange(row) : '',
                            stockState(row)?.kind === 'missing' ? 'part-missing-stock' : '',
                        ]"
                    >
                        <td v-if="selectable">
                            <input
                                v-if="
                                    row.id &&
                                    ['proposed', 'approved'].includes(
                                        row.status,
                                    )
                                "
                                class="selection-checkbox"
                                type="checkbox"
                                :checked="selectedIds.includes(row.id)"
                                :disabled="busy"
                                aria-label="Выбрать запчасть для рекомендаций"
                                @change="toggle(row, $event.target.checked)"
                            />
                        </td>
                        <td :title="row.name">
                            <WorkTypePicker
                                v-if="editable(row)"
                                v-model="row.catalogPartId"
                                :name="row.name"
                                :options="catalogOptions"
                                placeholder="Начните вводить запчасть"
                                :disabled="busy"
                                :can-create="canCreateCatalogPart"
                                create-label="Добавить запчасть"
                                @select="selectPart(row, $event)"
                                @edit="nameEdited(row, $event)"
                                @create="createPart(row, $event)"
                            /><span v-else>{{ row.name }}</span>
                        </td>
                        <td :title="row.article">{{ row.article || "—" }}</td>
                        <td :title="row.manufacturer">
                            {{ row.manufacturer || "—" }}
                        </td>
                        <td :title="row.unit">{{ row.unit }}</td>
                        <td>
                            <input
                                v-if="editable(row)"
                                v-model.number="row.qty"
                                :title="String(row.qty ?? '')"
                                type="number"
                                min="0.1"
                                step="0.1"
                                :disabled="busy"
                                aria-label="Количество запчасти"
                                @input="changed(row)"
                            /><span v-else>{{ row.qty }}</span>
                        </td>
                        <td>
                            <input
                                v-if="editable(row)"
                                v-model.number="row.price"
                                :title="String(row.price ?? '')"
                                type="number"
                                min="0"
                                step="0.01"
                                :disabled="busy"
                                aria-label="Цена реализации запчасти"
                                @input="changed(row)"
                            /><span v-else>{{ money(row.price) }}</span>
                        </td>
                        <td v-if="!customerView" class="agent-discount-cell">
                            {{
                                rowAgentDiscount(row) == null
                                    ? "—"
                                    : money(rowAgentDiscount(row))
                            }}
                        </td>
                        <td
                            v-if="showChangeNotes"
                            class="line-comment-cell"
                            :title="row.changeNote || ''"
                        >
                            <input
                                v-if="canChangeInWork && canEditParts"
                                v-model="row.changeNote"
                                type="text"
                                maxlength="2000"
                                :disabled="busy"
                                :class="{
                                    'missing-note':
                                        lineChange(row) &&
                                        !String(row.changeNote || '').trim(),
                                }"
                                placeholder="Что и почему изменено"
                                aria-label="Примечание к изменению запчасти"
                                @input="changed(row)"
                            /><span v-else>{{ row.changeNote || "" }}</span>
                        </td>
                        <td class="part-sum-cell">
                            {{ money(rowAmount(row)) }}
                        </td>
                        <td>
                            <button
                                v-if="editable(row)"
                                type="button"
                                class="delete-row"
                                :disabled="busy"
                                title="Удалить позицию"
                                aria-label="Удалить запчасть"
                                @click="remove(row)"
                            >
                                ×
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="table-actions">
            <button
                v-if="canEditParts"
                class="grid-add-row"
                type="button"
                :disabled="busy"
                title="Добавить строку запчасти"
                aria-label="Добавить строку запчасти"
                @click="addPartRow"
            >
                ＋
            </button>
        </div>
        <p v-if="!rows.length" class="muted">Запчасти не добавлены.</p>
        <p v-if="hasMissingStock" class="parts-stock-note">
            Красным выделены ЗЧ, которых нет на складе СТО в нужном количестве — они есть только в ЗР.
            Перевести ЗР на этап «ТС готово к выдаче Заказчику» можно только после оприходования этих ЗЧ.
        </p>
        <form
            v-if="operation"
            class="form-grid inset-form"
            @submit.prevent="apply"
        >
            <h4 class="full">
                {{ statusLabel(operation.kind) }}: {{ operation.part.name }}
            </h4>

            <label v-if="isQuantityOperation(operation.kind)">
                Количество
                <input
                    v-model.number="qty"
                    type="number"
                    min="0.001"
                    step="0.001"
                    required
                />
            </label>

            <label v-if="operation.kind === OPERATION_EXCHANGE">
                Согласованная позиция на замену
                <select v-model="replacementPartId" required>
                    <option value="">Выберите позицию</option>
                    <option
                        v-for="part in replacementParts"
                        :key="part.id"
                        :value="part.id"
                    >
                        {{ part.name }}
                    </option>
                </select>
            </label>

            <label class="full">
                Комментарий
                <textarea v-model="comment" required />
            </label>

            <div class="action-buttons">
                <button class="primary-btn" :disabled="busy">
                    Подтвердить
                </button>
                <button
                    class="ghost-btn"
                    type="button"
                    @click="cancelOperation"
                >
                    Отмена
                </button>
            </div>
        </form>


        <PartLineDialog
            v-if="dialogPart"
            :part="dialogPart"
            :position="dialogPosition"
            :options="catalogOptions"
            :suppliers="suppliers"
            :editable="editable(dialogPart)"
            :can-create="canCreateCatalogPart"
            :can-edit-comment="canEditParts"
            :customer-view="customerView"
            :busy="busy"
            :status-label="statusLabel(dialogPart.status)"
            :actions="partActions(dialogPart)"
            :stock="stockState(dialogPart)"
            :free-qty="freeQty(dialogPart)"
            :agent-percent="rowAgentPercent(dialogPart)"
            :agent-discount="rowAgentDiscount(dialogPart)"
            :show-change-notes="showChangeNotes"
            :can-change-in-work="canChangeInWork"
            :change="lineChange(dialogPart)"
            @close="dialogPart = null"
            @select="selectPart(dialogPart, $event)"
            @edit="nameEdited(dialogPart, $event)"
            @create="createPart(dialogPart, $event)"
            @touch="changed(dialogPart)"
            @action="dialogAction"
        />

        <details v-if="request.partMovements?.length">
            <summary>Возвраты и обмены</summary>
            <p v-for="movement in request.partMovements" :key="movement.id">
                {{ movementLabel(movement.kind) }} · {{ movement.qty }} ·
                {{ movement.comment }}
            </p>
        </details>
    </div>
</template>
