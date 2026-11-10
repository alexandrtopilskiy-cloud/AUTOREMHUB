<script>
import { marketplaceApi } from "../../api/marketplace.js";
import { money } from "../../data/permissions.js";
import { matchesReference } from "../../utils/referenceSearch.js";

const COLUMNS = {
    receipt: {
        number: "Номер",
        documentDate: "Дата",
        statusLabel: "Статус",
        warehouse: "Склад",
        supplier: "Поставщик",
        positions: "Позиций",
        amount: "Сумма закупа, ₽",
        author: "Создал",
    },
    sale: {
        number: "Номер",
        documentDate: "Дата",
        statusLabel: "Статус",
        warehouse: "Склад",
        customer: "Заказчик",
        vehicle: "ТС",
        positions: "Позиций",
        amount: "Сумма реализации, ₽",
        author: "Создал",
    },
};

/** Реестр документов Приход или Реализация. */
export default {
    name: "StockDocumentList",
    props: {
        type: { type: String, required: true },
        reloadToken: { type: Number, default: 0 },
    },
    emits: ["open", "create", "notify"],
    data() {
        return { rows: [], loading: true, filters: {}, selection: "" };
    },
    computed: {
        title() {
            return this.type === "receipt" ? "Приход" : "Реализация";
        },
        columns() {
            return COLUMNS[this.type];
        },
        listed() {
            return this.rows.filter((row) =>
                Object.entries(this.filters).every(([key, value]) =>
                    matchesReference(String(this.cell(row, key)), String(value || "").toLowerCase().trim()),
                ),
            );
        },
    },
    watch: {
        reloadToken() {
            this.load();
        },
    },
    mounted() {
        this.load();
    },
    methods: {
        async load() {
            this.loading = true;
            try {
                this.rows = (await marketplaceApi.stockDocuments(this.type)).data;
            } catch (error) {
                this.$emit("notify", error.message);
            } finally {
                this.loading = false;
            }
        },
        cell(row, key) {
            if (key === "documentDate") return row.documentDate.split("-").reverse().join(".");
            if (key === "amount") return money(row.amount);
            return row[key] ?? "—";
        },
    },
};
</script>

<template>
    <div>
        <div class="list-heading">
            <div>
                <span class="eyebrow">Склад</span>
                <h1>
                    {{ title }} <span class="count">{{ listed.length }}</span>
                </h1>
            </div>
            <div class="list-heading-actions">
                <button class="primary-btn" @click="$emit('create')">
                    ＋ Создать {{ type === "receipt" ? "Приход" : "Реализацию" }}
                </button>
            </div>
        </div>
        <p class="list-hint">Один клик — выбрать · Двойной клик или Enter — открыть документ</p>
        <div class="registry-wrap">
            <table class="registry stock-registry">
                <thead>
                    <tr>
                        <th v-for="(name, key) in columns" :key="key">{{ name }}</th>
                        <th />
                    </tr>
                    <tr class="column-filter-row">
                        <th v-for="(name, key) in columns" :key="key">
                            <input v-model="filters[key]" :aria-label="`Фильтр: ${name}`" placeholder="Фильтр" />
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
                        @dblclick="$emit('open', row)"
                        @keydown.enter="$emit('open', row)"
                    >
                        <td v-for="(name, key) in columns" :key="key">
                            <span
                                v-if="key === 'statusLabel'"
                                class="status"
                                :class="row.status === 'posted' ? 'green' : 'amber'"
                            >{{ row.statusLabel }}</span>
                            <strong v-else-if="key === 'number'">{{ row.number }}</strong>
                            <template v-else>{{ cell(row, key) }}</template>
                        </td>
                        <td>
                            <button class="open-row" :aria-label="'Открыть ' + row.number" @click.stop="$emit('open', row)">↗</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="!listed.length" class="empty-state">
            <h3>{{ loading ? "Загружаем документы…" : "Документов пока нет" }}</h3>
            <p>Создайте документ {{ title }} кнопкой выше.</p>
        </div>
    </div>
</template>
