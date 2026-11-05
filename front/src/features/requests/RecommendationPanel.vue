<script>
import { marketplaceApi } from "../../api/marketplace.js";
import { money, formatDateTime } from "../../data/permissions.js";

export default {
    name: "RecommendationPanel",
    props: {
        // Пустой vehicleId — общий перечень рекомендаций по всем доступным ТС.
        vehicleId: { type: String, default: "" },
        vehicleInfo: { type: Object, default: () => ({}) },
        requests: { type: Array, default: () => [] },
        pending: { type: Object, default: () => ({}) },
        selectedId: { type: String, default: "" },
        // ЗР, из которой открыта форма: перенос выполняется только в неё.
        requestId: { type: String, default: "" },
        userRole: { type: String, default: "" },
        showCompany: { type: Boolean, default: true },
    },
    // select — одинарный клик по ТС в перечне (панель «Состояние документа»),
    // open-vehicle — двойной клик/Enter: открыть документ рекомендаций ТС,
    // loaded — документ ТС загружен (для панели состояния).
    emits: ["restored", "open-vehicle", "select", "loaded", "open-request"],
    data() {
        return {
            documents: [],
            selectedIds: [],
            busy: false,
            error: "",
            detailItem: null,
            loaded: false,
            filter: "",
            confirmDelete: false,
        };
    },
    computed: {
        allVehicles() {
            return !this.vehicleId;
        },
        document() {
            return this.documents[0];
        },
        vehicle() {
            return (
                this.document ||
                this.requests.find((row) => row.vehicleId === this.vehicleId) ||
                this.vehicleInfo ||
                {}
            );
        },
        sections() {
            return [
                {
                    vehicleId: this.vehicleId,
                    vehicle: this.vehicle,
                    groups: this.groupsOf(this.documents),
                },
            ];
        },
        // Перечень ТС с рекомендациями: один документ на ТС.
        rows() {
            const query = this.filter.toLowerCase().trim();
            return this.documents
                .filter((doc) => doc.items.length)
                .filter(
                    (doc) =>
                        !query ||
                        [
                            doc.number,
                            doc.make,
                            doc.model,
                            doc.plate,
                            doc.vin,
                            doc.company,
                            ...(doc.sourceRequests || []),
                        ]
                            .join(" ")
                            .toLowerCase()
                            .includes(query),
                )
                .sort(
                    (a, b) =>
                        Number(b.openCount > 0) - Number(a.openCount > 0) ||
                        String(b.lastAddedAt || "").localeCompare(
                            String(a.lastAddedAt || ""),
                        ),
                );
        },
        sourceRequest() {
            return (
                (this.requestId &&
                    this.requests.find((row) => row.id === this.requestId)) ||
                null
            );
        },
        // Причина, по которой перенос в исходную ЗР сейчас недоступен.
        transferBlock() {
            const target = this.sourceRequest;
            if (!target) return "";
            if (
                target.vehicleId !== this.vehicleId ||
                (this.document && target.vin !== this.document.vin)
            )
                return `ТС в заявке ${target.number} не совпадает с ТС рекомендаций`;
            if (!target.permissions?.canRestoreRecommendations)
                return "Перенос в заявку возможен на этапах с «Черновик» по «В работе»";
            if (this.pending[target.id])
                return "Сначала сохраните изменения заявки";
            return "";
        },
        canCreateRequest() {
            return this.userRole === "customer";
        },
        // Удалять строки могут Заказчик и УК.
        canDelete() {
            return ["customer", "manager"].includes(this.userRole);
        },
        selectable() {
            return (
                Boolean(this.sourceRequest) ||
                this.canCreateRequest ||
                this.canDelete
            );
        },
    },
    mounted() {
        this.load();
    },
    methods: {
        money,
        formatDateTime,
        vehicleTitle(vehicle) {
            return (
                [vehicle.make, vehicle.model, vehicle.plate]
                    .filter(Boolean)
                    .join(" · ") || vehicle.vin || "ТС"
            );
        },
        groupsOf(documents) {
            const items = documents
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
            return [
                { kind: "work", title: "Работы" },
                { kind: "part", title: "ЗЧ" },
            ].map((group) => ({
                ...group,
                items: items.filter((item) => item.kind === group.kind),
            }));
        },
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
                this.documents = this.allVehicles
                    ? await marketplaceApi.allRecommendations()
                    : await marketplaceApi.vehicleRecommendations(
                          this.vehicleId,
                      );
                if (!this.allVehicles) this.$emit("loaded", this.document || null);
                else if (this.selectedId)
                    this.$emit(
                        "select",
                        this.documents.find(
                            (doc) => doc.id === this.selectedId,
                        ) || null,
                    );
            } catch (exception) {
                this.error = exception.message;
            } finally {
                this.loaded = true;
            }
        },
        async removeSelected() {
            const doc = this.document;
            const itemIds = (doc?.items || [])
                .filter((item) => this.selectedIds.includes(item.id))
                .map((item) => item.id);
            this.confirmDelete = false;
            if (!doc || !itemIds.length || !this.canDelete) return;
            this.busy = true;
            this.error = "";
            let lock = null;
            try {
                lock = await marketplaceApi.acquireDocumentLock(
                    "recommendation",
                    doc.id,
                    "Рекомендации ТС",
                );
                await marketplaceApi.deleteRecommendationItems(
                    doc.id,
                    doc.revision,
                    itemIds,
                );
                this.selectedIds = [];
                await this.load();
            } catch (exception) {
                this.error = exception.message;
            } finally {
                if (lock?.id)
                    await marketplaceApi
                        .releaseDocumentLock(lock.id)
                        .catch(() => {});
                this.busy = false;
            }
        },
        async restore(createNew = false) {
            const target = this.sourceRequest;
            if (createNew ? !this.canCreateRequest : !target || this.transferBlock) {
                this.error = this.transferBlock || "Перенос недоступен";
                return;
            }
            const selection = this.documents
                .map((doc) => ({
                    id: doc.id,
                    revision: doc.revision,
                    itemIds: doc.items
                        .filter(
                            (item) =>
                                this.selectedIds.includes(item.id),
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
                <span class="eyebrow">Документы</span>
                <h1 v-if="allVehicles">
                    Рекомендации <span class="count">{{ rows.length }}</span>
                </h1>
                <h1 v-else>
                    Рекомендации
                    <template v-if="document?.number">
                        {{ document.number }}</template
                    >
                </h1>
                <p v-if="allVehicles">
                    ТС, по которым есть рекомендации. Один клик — состояние
                    документа · Двойной клик или Enter — открыть рекомендации ТС.
                </p>
                <p v-else>
                    {{ vehicleTitle(vehicle) }} · VIN {{ vehicle.vin || "—" }}
                </p>
            </div>
        </div>
        <template v-if="allVehicles">
            <div class="registry-wrap">
                <table class="registry recommendation-registry">
                    <thead>
                        <tr>
                            <th>Документ / обновлён</th>
                            <th>Автомобиль / VIN</th>
                            <th v-if="showCompany">Заказчик</th>
                            <th>Рекомендовано</th>
                            <th>Из заявок</th>
                            <th />
                        </tr>
                        <tr class="column-filter-row">
                            <th :colspan="showCompany ? 6 : 5">
                                <input
                                    v-model="filter"
                                    aria-label="Фильтр рекомендаций"
                                    placeholder="Номер, ТС, госномер, VIN, заказчик или ЗР"
                                />
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="doc in rows"
                            :key="doc.id"
                            tabindex="0"
                            :class="{ selected: selectedId === doc.id }"
                            @click="$emit('select', doc)"
                            @dblclick="$emit('open-vehicle', doc)"
                            @keydown.enter="$emit('open-vehicle', doc)"
                        >
                            <td>
                                <strong>{{ doc.number }}</strong
                                ><small>{{
                                    formatDateTime(doc.lastAddedAt || doc.updatedAt)
                                }}</small>
                            </td>
                            <td>
                                <strong>{{
                                    [doc.make, doc.model].filter(Boolean).join(" ") ||
                                    "ТС"
                                }}</strong
                                ><small
                                    >{{ doc.plate || "без госномера" }} ·
                                    {{ doc.vin || "—" }}</small
                                >
                            </td>
                            <td v-if="showCompany">{{ doc.company || "—" }}</td>
                            <td>
                                <span
                                    class="status"
                                    :class="doc.openCount ? 'amber' : 'green'"
                                    >{{
                                        doc.openCount
                                            ? `${doc.openCount} поз.`
                                            : "Всё перенесено"
                                    }}</span
                                ><small v-if="doc.openCount"
                                    >работ {{ doc.openWorks }} · ЗЧ
                                    {{ doc.openParts }}</small
                                >
                            </td>
                            <td>{{ (doc.sourceRequests || []).join(", ") || "—" }}</td>
                            <td>
                                <button
                                    class="open-row"
                                    :aria-label="'Открыть ' + doc.number"
                                    @click.stop="$emit('open-vehicle', doc)"
                                >
                                    ↗
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="loaded && !rows.length" class="empty-state">
                <h3>Рекомендаций пока нет</h3>
                <p>
                    {{
                        filter
                            ? "Измените параметры фильтрации."
                            : "Позиции попадают сюда из заявок кнопкой «Добавить в рекомендации»."
                    }}
                </p>
            </div>
        </template>
        <section
            v-for="section in allVehicles ? [] : sections"
            :key="section.key || section.vehicleId"
            class="recommendation-vehicle"
        >
        <section
            v-for="group in section.groups"
            :key="group.kind"
            class="recommendation-group"
        >
            <h3>{{ group.title }}</h3>
            <div v-if="group.items.length" class="table-wrap excel-grid-wrap">
                <table class="excel-grid recommendation-grid">
                    <thead>
                        <tr>
                            <th v-if="selectable" class="selection-col" />
                            <th class="name-col">Наименование</th>
                            <th>
                                {{ group.kind === "work" ? "Код" : "Артикул" }}
                            </th>
                            <th v-if="group.kind === 'work'">Норма, ч</th>
                            <th v-else>Производитель</th>
                            <th>Кол-во</th>
                            <th>Цена / ставка, ₽</th>
                            <th>Сумма, ₽</th>
                            <th>Из заявки</th>
                            <th>Кто добавил</th>
                            <th>Дата</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in group.items" :key="item.id">
                            <td v-if="selectable" class="selection-col">
                                <input
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
                            <td>
                                <button
                                    v-if="item.sourceRequestId"
                                    type="button"
                                    class="link-btn"
                                    @click="$emit('open-request', item.sourceRequestId)"
                                >
                                    {{ item.sourceRequestNumber }}
                                </button>
                                <template v-else>—</template>
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
        </section>
        <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
        <div
            v-if="!allVehicles && document"
            class="record-editor-footer action-buttons"
        >
            <template v-if="sourceRequest">
                <button
                    class="primary-btn"
                    :disabled="busy || !selectedIds.length || Boolean(transferBlock)"
                    @click="restore(false)"
                >
                    Перенести в {{ sourceRequest.number }}
                </button>
                <span v-if="transferBlock" class="muted">{{ transferBlock }}</span>
            </template>
            <button
                v-if="canCreateRequest"
                :class="sourceRequest ? 'ghost-btn' : 'primary-btn'"
                :disabled="busy || !selectedIds.length"
                @click="restore(true)"
            >
                Создать заявку из выбранного
            </button>
            <button
                v-if="canDelete"
                class="danger-btn"
                :disabled="busy || !selectedIds.length"
                @click="confirmDelete = true"
            >
                Удалить выбранное ({{ selectedIds.length }})
            </button>
            <span v-if="!sourceRequest && !canCreateRequest" class="muted">
                Чтобы перенести позиции, откройте Рекомендации из заявки на
                ремонт по этому ТС.
            </span>
        </div>
        <dialog v-if="confirmDelete" open class="action-dialog">
            <h3>Удалить из рекомендаций?</h3>
            <p>
                Выбрано позиций: {{ selectedIds.length }}. Они исчезнут из
                документа, в истории останется запись об удалении.
            </p>
            <div class="action-buttons">
                <button class="danger-btn" type="button" @click="removeSelected">
                    Удалить
                </button>
                <button
                    class="ghost-btn"
                    type="button"
                    @click="confirmDelete = false"
                >
                    Отмена
                </button>
            </div>
        </dialog>
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
