<script>
import { marketplaceApi } from "../../api/marketplace.js";
import { money } from "../../data/permissions.js";

export default {
    name: "RecommendationPanel",
    props: {
        vehicleId: { type: String, required: true },
        requests: { type: Array, default: () => [] },
        pending: { type: Object, default: () => ({}) },
    },
    emits: ["restored"],
    data() {
        return {
            documents: [],
            selectedIds: [],
            targetId: "",
            busy: false,
            error: "",
            detailItem: null,
        };
    },
    computed: {
        document() {
            return this.documents[0];
        },
        vehicle() {
            return (
                this.document ||
                this.requests.find((row) => row.vehicleId === this.vehicleId) ||
                {}
            );
        },
        items() {
            return this.documents
                .flatMap((doc) =>
                    doc.items.map((item) => ({
                        ...item,
                        documentId: doc.id,
                        documentRevision: doc.revision,
                    })),
                )
                .sort(
                    (a, b) =>
                        a.createdAt.localeCompare(b.createdAt) ||
                        a.id.localeCompare(b.id),
                );
        },
        groups() {
            return [
                { kind: "work", title: "Работы" },
                { kind: "part", title: "ЗЧ" },
            ].map((group) => ({
                ...group,
                items: this.items.filter((item) => item.kind === group.kind),
            }));
        },
        targets() {
            return this.requests.filter(
                (row) =>
                    row.vehicleId === this.vehicleId &&
                    row.permissions?.canRestoreRecommendations &&
                    (!this.document || row.vin === this.document.vin),
            );
        },
    },
    mounted() {
        this.load();
    },
    methods: {
        money,
        parameterLabel(key) {
            return (
                {
                    name: "Наименование",
                    workCode: "Код работы",
                    normId: "Код нормы",
                    normHours: "Норма времени, ч",
                    normIsManual: "Ручная норма",
                    normOverrideReason: "Причина ручной нормы",
                    normApplicabilityLabel: "Применимость",
                    hourlyRate: "Стоимость нормо/часа, ₽",
                    actualHours: "Фактическое время, ч",
                    qty: "Количество",
                    price: "Цена, ₽",
                    purchasePrice: "Цена закупа, ₽",
                    amount: "Сумма, ₽",
                    lineAmount: "Сумма, ₽",
                    article: "Артикул",
                    manufacturer: "Производитель",
                    unit: "Единица измерения",
                    supplierName: "Поставщик",
                    status: "Статус",
                    returnedQty: "Возвращено",
                }[key] || key
            );
        },
        parameterValue(value) {
            return typeof value === "boolean"
                ? value
                    ? "Да"
                    : "Нет"
                : (value ?? "—");
        },
        async load() {
            try {
                this.documents = await marketplaceApi.vehicleRecommendations(
                    this.vehicleId,
                );
            } catch (exception) {
                this.error = exception.message;
            }
        },
        async restore(createNew = false) {
            const target = this.targets.find((row) => row.id === this.targetId);
            if (!createNew && (!target || this.pending[target.id])) {
                this.error =
                    "Выберите доступную заявку и сначала сохраните её изменения";
                return;
            }
            const selection = this.documents
                .map((doc) => ({
                    id: doc.id,
                    revision: doc.revision,
                    itemIds: doc.items
                        .filter(
                            (item) =>
                                this.selectedIds.includes(item.id) &&
                                !item.movedAt,
                        )
                        .map((item) => item.id),
                }))
                .filter((doc) => doc.itemIds.length);
            if (!selection.length) return;
            this.busy = true;
            this.error = "";
            const locks = [];
            try {
                for (const doc of [...selection].sort((a, b) =>
                    a.id.localeCompare(b.id),
                )) {
                    locks.push(
                        await marketplaceApi.acquireDocumentLock(
                            "recommendation",
                            doc.id,
                            "Рекомендации ТС",
                        ),
                    );
                }
                const result =
                    await marketplaceApi.restoreRecommendationSelection(
                        selection,
                        createNew ? null : target.id,
                        createNew ? null : target.revision,
                    );
                this.selectedIds = [];
                await this.load();
                this.$emit("restored", result.request);
            } catch (exception) {
                this.error = exception.message;
            } finally {
                for (const lock of locks)
                    if (lock?.id)
                        await marketplaceApi
                            .releaseDocumentLock(lock.id)
                            .catch(() => {});
                this.busy = false;
            }
        },
    },
};
</script>
<template>
    <section class="document-panel recommendation-panel">
        <div class="document-head">
            <div>
                <span class="eyebrow">Рекомендации ТС</span>
                <h1>
                    {{
                        [vehicle.make, vehicle.model, vehicle.plate]
                            .filter(Boolean)
                            .join(" · ") || "Рекомендации"
                    }}
                </h1>
                <p>VIN {{ vehicle.vin || "—" }}</p>
            </div>
        </div>
        <section
            v-for="group in groups"
            :key="group.kind"
            class="recommendation-group"
        >
            <h3>{{ group.title }}</h3>
            <div v-if="group.items.length" class="table-wrap excel-grid-wrap">
                <table class="excel-grid recommendation-grid">
                    <thead>
                        <tr>
                            <th class="selection-col" />
                            <th>Наименование</th>
                            <th>
                                {{ group.kind === "work" ? "Код" : "Артикул" }}
                            </th>
                            <th v-if="group.kind === 'work'">Норма, ч</th>
                            <th v-else>Производитель</th>
                            <th>Кол-во</th>
                            <th>Цена / ставка, ₽</th>
                            <th>Сумма, ₽</th>
                            <th>Кто добавил</th>
                            <th>Дата</th>
                            <th>Состояние</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in group.items" :key="item.id">
                            <td class="selection-col">
                                <input
                                    v-if="!item.movedAt"
                                    v-model="selectedIds"
                                    type="checkbox"
                                    class="selection-checkbox"
                                    :value="item.id"
                                    :disabled="busy"
                                    :aria-label="`Выбрать ${item.position.name}`"
                                />
                            </td>
                            <td :title="item.position.name">
                                {{ item.position.name }}
                                <button
                                    type="button"
                                    class="link-btn"
                                    title="Все параметры позиции"
                                    @click="detailItem = item"
                                >
                                    Параметры
                                </button>
                            </td>
                            <td
                                :title="
                                    item.position.article ||
                                    item.position.workCode
                                "
                            >
                                {{
                                    item.position.article ||
                                    item.position.workCode
                                }}
                            </td>
                            <td v-if="group.kind === 'work'">
                                {{ item.position.normHours ?? "—" }}
                            </td>
                            <td v-else :title="item.position.manufacturer">
                                {{ item.position.manufacturer || "—" }}
                            </td>
                            <td>
                                {{ item.position.qty }}
                                {{ item.position.unit || "" }}
                            </td>
                            <td>
                                {{
                                    money(
                                        group.kind === "work"
                                            ? item.position.hourlyRate
                                            : item.position.price,
                                    )
                                }}
                            </td>
                            <td>
                                {{
                                    group.kind === "work" &&
                                    item.position.lineAmount == null
                                        ? "Не рассчитано"
                                        : money(
                                              item.position.lineAmount ??
                                                  item.position.qty *
                                                      item.position.price,
                                          )
                                }}
                            </td>
                            <td :title="item.actor">{{ item.actor }}</td>
                            <td
                                :title="
                                    new Date(item.createdAt).toLocaleString(
                                        'ru-RU',
                                        {
                                            hour12: false,
                                        },
                                    )
                                "
                            >
                                {{
                                    new Date(item.createdAt).toLocaleDateString(
                                        "ru-RU",
                                    )
                                }}
                            </td>
                            <td
                                :title="
                                    item.movedAt
                                        ? 'Перенесено в заявку'
                                        : 'Рекомендовано'
                                "
                            >
                                {{
                                    item.movedAt
                                        ? "Перенесено"
                                        : "Рекомендовано"
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="muted">
                {{
                    group.kind === "work"
                        ? "Работы не добавлены."
                        : "Запчасти не добавлены."
                }}
            </p>
        </section>
        <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
        <div class="record-editor-footer action-buttons">
            <label
                ><span>Заявка для этого VIN</span
                ><select v-model="targetId">
                    <option value="">Выберите заявку</option>
                    <option
                        v-for="row in targets"
                        :key="row.id"
                        :value="row.id"
                    >
                        {{ row.number }}
                    </option>
                </select></label
            >
            <button
                class="ghost-btn"
                :disabled="busy || !selectedIds.length || !targetId"
                @click="restore(false)"
            >
                Переместить в заявку на ремонт
            </button>
            <button
                class="primary-btn"
                :disabled="busy || !selectedIds.length"
                @click="restore(true)"
            >
                Создать заявку из выбранного
            </button>
        </div>
        <dialog
            v-if="detailItem"
            open
            class="action-dialog recommendation-details"
        >
            <h3>{{ detailItem.position.name }}</h3>
            <p>
                {{ detailItem.actor }} ·
                {{
                    new Date(detailItem.createdAt).toLocaleString("ru-RU", {
                        hour12: false,
                    })
                }}
            </p>
            <dl>
                <template v-for="(value, key) in detailItem.position" :key="key"
                    ><dt>{{ parameterLabel(key) }}</dt>
                    <dd>{{ parameterValue(value) }}</dd></template
                >
            </dl>
            <button class="ghost-btn" type="button" @click="detailItem = null">
                Закрыть
            </button>
        </dialog>
    </section>
</template>
