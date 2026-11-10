<script>
import { marketplaceApi } from "../../api/marketplace.js";
import { money } from "../../data/permissions.js";
import { matchesReference } from "../../utils/referenceSearch.js";

function today() {
    const now = new Date();
    const pad = (value) => String(value).padStart(2, "0");
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/** Отчёт «Остатки на складе» на конец выбранной даты (23:59:59). */
export default {
    name: "StockBalanceReport",
    emits: ["notify"],
    data() {
        return {
            date: today(),
            warehouseId: "",
            warehouses: [],
            rows: [],
            totals: null,
            search: "",
            loading: false,
            error: "",
        };
    },
    computed: {
        listed() {
            const query = this.search.toLowerCase().trim();
            return this.rows.filter((row) => matchesReference([row.name, row.article, row.manufacturer].join(" "), query));
        },
        dateLabel() {
            return this.date ? this.date.split("-").reverse().join(".") : "";
        },
    },
    async mounted() {
        try {
            this.warehouses = (await marketplaceApi.warehouseSettings()).warehouses;
        } catch (error) {
            this.error = error.message;
        }
        await this.load();
    },
    methods: {
        money,
        qty(value) {
            return Number(value || 0).toLocaleString("ru-RU", { maximumFractionDigits: 3 });
        },
        async load() {
            if (!this.date) return;
            this.loading = true;
            this.error = "";
            try {
                const data = await marketplaceApi.stockBalance(this.date, this.warehouseId);
                this.rows = data.rows;
                this.totals = data.totals;
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },
    },
};
</script>

<template>
    <div class="stock-balance">
        <div class="list-heading">
            <div>
                <span class="eyebrow">Склад · отчёт</span>
                <h1>Остатки на складе <span class="count">{{ listed.length }}</span></h1>
            </div>
        </div>
        <div class="stock-balance-filters">
            <label>
                <span>На дату (на 23:59:59)</span>
                <input v-model="date" type="date" @change="load" />
            </label>
            <label>
                <span>Склад</span>
                <select v-model="warehouseId" @change="load">
                    <option value="">Все склады</option>
                    <option v-for="row in warehouses" :key="row.id" :value="row.id">{{ row.name }}</option>
                </select>
            </label>
            <label class="stock-balance-search">
                <span>Поиск</span>
                <input v-model="search" type="search" placeholder="Наименование, артикул, производитель" />
            </label>
        </div>
        <p v-if="error" class="error-message">{{ error }}</p>
        <div class="registry-wrap">
            <table class="registry stock-balance-table">
                <thead>
                    <tr>
                        <th>Наименование ЗЧ</th>
                        <th>Артикул</th>
                        <th>Производитель</th>
                        <th>Ед.</th>
                        <th class="align-right">Приход, ед.</th>
                        <th class="align-right">Расход, ед.</th>
                        <th class="align-right">Остаток, ед.</th>
                        <th class="align-right">Стоимость по цене закупа, ₽</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in listed" :key="row.catalogPartId">
                        <td><strong>{{ row.name }}</strong></td>
                        <td>{{ row.article }}</td>
                        <td>{{ row.manufacturer || "—" }}</td>
                        <td>{{ row.unit }}</td>
                        <td class="align-right">{{ qty(row.incoming) }}</td>
                        <td class="align-right">{{ qty(row.outgoing) }}</td>
                        <td class="align-right"><strong>{{ qty(row.balance) }}</strong></td>
                        <td class="align-right">{{ money(row.amount) }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="totals && listed.length">
                    <tr>
                        <td colspan="4"><strong>Итого на {{ dateLabel }}</strong></td>
                        <td class="align-right">{{ qty(totals.incoming) }}</td>
                        <td class="align-right">{{ qty(totals.outgoing) }}</td>
                        <td class="align-right"><strong>{{ qty(totals.balance) }}</strong></td>
                        <td class="align-right"><strong>{{ money(totals.amount) }}</strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div v-if="!listed.length" class="empty-state">
            <h3>{{ loading ? "Формируем отчёт…" : "Движений ЗЧ на эту дату нет" }}</h3>
            <p>Остатки появляются после проведения документов Приход.</p>
        </div>
        <p class="list-hint">
            Стоимость остатка — по средней цене закупа проведённых Приходов на дату отчёта.
        </p>
    </div>
</template>

<style scoped>
.stock-balance-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
    margin: 0 0 12px;
    padding: 0 24px;
}
.stock-balance-filters label {
    min-width: 180px;
}
.stock-balance-search {
    flex: 1;
    max-width: 420px;
}
.stock-balance-table tfoot td {
    background: var(--soft);
    padding: 8px 10px;
    border-top: 2px solid var(--line);
}
</style>
