<script setup>
import { computed, nextTick, ref } from "vue";
import { money } from "../../data/permissions.js";
import { startColumnResize } from "../../utils/columnResize.js";
import WorkTypePicker from "./WorkTypePicker.vue";
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
    };
}
const placeholder = ref(emptyPart());
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
            optionLabel: [p.name, p.article, p.manufacturer]
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
function editable(row) {
    return (
        canEditParts.value &&
        ["proposed", "approved", undefined].includes(row.status)
    );
}
function ensureRow(row) {
    if (!props.request.parts.includes(row)) props.request.parts.push(row);
}
function changed(row) {
    ensureRow(row);
    emit("touch");
}
function selectPart(row, part) {
    Object.assign(row, {
        catalogPartId: part.id,
        name: part.name,
        article: part.article || "",
        manufacturer: part.manufacturer || "",
        unit: part.unit || "шт.",
        supplierId: part.supplierId || "",
        price: part.salePrice ?? 0,
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
        <h3>Запчасти и поставщики</h3>
        <div class="table-wrap excel-grid-wrap">
            <table class="excel-grid parts-grid">
                <colgroup>
                    <col v-if="selectable" style="width: 28px" />
                    <col />
                    <col style="width: 8%" />
                    <col style="width: 10%" />
                    <col style="width: 5%" />
                    <col v-if="!customerView" style="width: 11%" />
                    <col style="width: 6%" />
                    <col style="width: 10%" />
                    <col style="width: 10%" />
                    <col style="width: 8%" />
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
                                ...(!customerView ? ['Поставщик'] : []),
                                'Кол-во',
                                'Цена, ₽',
                                'Сумма, ₽',
                                'Статус',
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
                        v-for="row in rows"
                        :key="row.id || row._clientId"
                        class="part-draft-row"
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
                        <td
                            v-if="!customerView"
                            :title="supplierName(row.supplierId)"
                        >
                            <select
                                v-if="editable(row)"
                                v-model="row.supplierId"
                                :disabled="busy"
                                @change="changed(row)"
                            >
                                <option value="">Выберите поставщика</option>
                                <option
                                    v-for="s in suppliers"
                                    :key="s.id"
                                    :value="s.id"
                                >
                                    {{ s.name }}
                                </option></select
                            ><span v-else>{{
                                supplierName(row.supplierId)
                            }}</span>
                        </td>
                        <td>
                            <input
                                v-if="editable(row)"
                                v-model.number="row.qty"
                                :title="String(row.qty ?? '')"
                                type="number"
                                min="0.001"
                                step="0.001"
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
                        <td>
                            {{
                                money(
                                    Number(row.qty || 0) *
                                        Number(row.price || 0),
                                )
                            }}
                        </td>
                        <td :title="statusLabel(row.status)">
                            <select
                                v-if="
                                    canManageParts &&
                                    [
                                        'approved',
                                        'ordered',
                                        'received',
                                    ].includes(row.status)
                                "
                                :disabled="busy"
                                :aria-label="`Действие с запчастью ${row.name}`"
                                @change="
                                    start(row, $event.target.value);
                                    $event.target.value = '';
                                "
                            >
                                <option value="">
                                    {{ statusLabel(row.status) }}
                                </option>
                                <option
                                    v-if="row.status === 'approved'"
                                    value="ordered"
                                >
                                    Заказать
                                </option>
                                <option
                                    v-if="row.status === 'ordered'"
                                    value="received"
                                >
                                    Получена
                                </option>
                                <option
                                    v-if="
                                        ['ordered', 'received'].includes(
                                            row.status,
                                        )
                                    "
                                    value="return"
                                >
                                    Возврат
                                </option>
                                <option
                                    v-if="
                                        ['ordered', 'received'].includes(
                                            row.status,
                                        )
                                    "
                                    value="exchange"
                                >
                                    Обмен
                                </option>
                            </select>
                            <span v-else>{{ statusLabel(row.status) }}</span>
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

        <p v-if="error" class="error-message">{{ error }}</p>

        <details v-if="request.partMovements?.length">
            <summary>Возвраты и обмены</summary>
            <p v-for="movement in request.partMovements" :key="movement.id">
                {{ movementLabel(movement.kind) }} · {{ movement.qty }} ·
                {{ movement.comment }}
            </p>
        </details>
    </div>
</template>
