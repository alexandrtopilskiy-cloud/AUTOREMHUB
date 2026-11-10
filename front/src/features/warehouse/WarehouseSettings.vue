<script>
import { marketplaceApi } from "../../api/marketplace.js";
import { money } from "../../data/permissions.js";

const MAX_TIERS = 10;

/** Настройки → Складской учёт: склады организации и наценка на товары. */
export default {
    name: "WarehouseSettings",
    emits: ["notify"],
    data() {
        return {
            loading: true,
            busy: false,
            error: "",
            markupError: "",
            warehouses: [],
            editingId: "",
            editingName: "",
            newName: "",
            markup: { mode: "flat", percent: 0, tiers: [] },
            examplePrice: 1000,
            maxTiers: MAX_TIERS,
        };
    },
    computed: {
        exampleSalePrice() {
            const price = Number(this.examplePrice || 0);
            const percent = this.percentFor(price);
            return percent == null ? null : Math.round(price * (1 + percent / 100) * 100) / 100;
        },
    },
    async mounted() {
        try {
            const data = await marketplaceApi.warehouseSettings();
            this.warehouses = data.warehouses;
            this.markup = {
                ...data.markup,
                tiers: data.markup.tiers.length ? data.markup.tiers.map((row) => ({ ...row })) : [this.emptyTier(0)],
            };
        } catch (error) {
            this.error = error.message;
        } finally {
            this.loading = false;
        }
    },
    methods: {
        money,
        emptyTier(from = null) {
            return { from, to: null, percent: null };
        },
        percentFor(price) {
            if (this.markup.mode === "flat") return Number(this.markup.percent || 0);
            const tier = this.markup.tiers.find(
                (row) =>
                    row.percent !== null && row.percent !== "" &&
                    price >= Number(row.from || 0) &&
                    (row.to === null || row.to === "" || price <= Number(row.to)),
            );
            return tier ? Number(tier.percent) : null;
        },
        async run(action, success) {
            this.busy = true;
            this.error = "";
            try {
                const data = await action();
                this.warehouses = data.warehouses;
                this.$emit("notify", success);
                return true;
            } catch (error) {
                this.error = error.message;
                return false;
            } finally {
                this.busy = false;
            }
        },
        async addWarehouse() {
            const name = this.newName.trim();
            if (!name) {
                this.error = "Укажите наименование склада";
                return;
            }
            if (await this.run(() => marketplaceApi.createWarehouse(name), "Склад добавлен")) {
                this.newName = "";
            }
        },
        startEdit(row) {
            this.editingId = row.id;
            this.editingName = row.name;
        },
        async saveEdit(row) {
            if (await this.run(() => marketplaceApi.updateWarehouse(row.id, this.editingName.trim()), "Склад переименован")) {
                this.editingId = "";
            }
        },
        async remove(row) {
            if (!window.confirm(`Удалить склад «${row.name}»?`)) return;
            await this.run(() => marketplaceApi.deleteWarehouse(row.id), "Склад удалён");
        },
        addTier() {
            if (this.markup.tiers.length >= MAX_TIERS) return;
            const last = this.markup.tiers[this.markup.tiers.length - 1];
            const from = last?.to != null && last.to !== "" ? Math.round((Number(last.to) + 0.01) * 100) / 100 : null;
            this.markup.tiers.push(this.emptyTier(from));
        },
        removeTier(index) {
            this.markup.tiers.splice(index, 1);
            if (!this.markup.tiers.length) this.markup.tiers.push(this.emptyTier(0));
        },
        async saveMarkup() {
            this.busy = true;
            this.markupError = "";
            const blank = (value) => value === "" || value === null || value === undefined;
            try {
                const tiers = this.markup.tiers
                    .filter((row) => !blank(row.from) || !blank(row.to) || !blank(row.percent))
                    .map((row) => ({
                        from: blank(row.from) ? null : Number(row.from),
                        to: blank(row.to) ? null : Number(row.to),
                        percent: blank(row.percent) ? null : Number(row.percent),
                    }));
                const saved = await marketplaceApi.saveWarehouseMarkup({
                    mode: this.markup.mode,
                    percent: blank(this.markup.percent) ? null : Number(this.markup.percent),
                    tiers: this.markup.mode === "progressive" ? tiers : [],
                });
                this.markup = {
                    ...saved.markup,
                    tiers: saved.markup.tiers.length ? saved.markup.tiers : [this.emptyTier(0)],
                };
                this.$emit("notify", "Наценка сохранена");
            } catch (error) {
                this.markupError = error.message;
            } finally {
                this.busy = false;
            }
        },
    },
};
</script>

<template>
    <section class="document-panel warehouse-settings">
        <div class="document-head">
            <div>
                <span class="eyebrow">Настройки</span>
                <h2>Складской учёт</h2>
            </div>
        </div>
        <p v-if="loading" class="muted">Загружаем настройки…</p>
        <template v-else>
            <section class="feature-card warehouse-card">
                <h3>Склады</h3>
                <div class="table-wrap">
                    <table class="warehouse-table">
                        <thead>
                            <tr>
                                <th>Наименование</th>
                                <th class="warehouse-actions-col" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in warehouses" :key="row.id">
                                <td>
                                    <input
                                        v-if="editingId === row.id"
                                        v-model="editingName"
                                        maxlength="255"
                                        aria-label="Наименование склада"
                                        @keydown.enter.prevent="saveEdit(row)"
                                        @keydown.esc="editingId = ''"
                                    />
                                    <span v-else>{{ row.name }}</span>
                                </td>
                                <td class="warehouse-actions-col">
                                    <template v-if="editingId === row.id">
                                        <button class="primary-btn small-btn" :disabled="busy" @click="saveEdit(row)">Сохранить</button>
                                        <button class="ghost-btn small-btn" :disabled="busy" @click="editingId = ''">Отмена</button>
                                    </template>
                                    <template v-else>
                                        <button class="ghost-btn small-btn" :disabled="busy" @click="startEdit(row)">Изменить</button>
                                        <button
                                            class="danger-btn small-btn"
                                            :disabled="busy || row.used"
                                            :title="row.used ? 'Склад используется в документах' : 'Удалить склад'"
                                            @click="remove(row)"
                                        >Удалить</button>
                                    </template>
                                </td>
                            </tr>
                            <tr v-if="!warehouses.length">
                                <td colspan="2" class="muted">Складов пока нет — добавьте первый.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <form class="warehouse-add" @submit.prevent="addWarehouse">
                    <input v-model="newName" maxlength="255" placeholder="Наименование нового склада" aria-label="Наименование нового склада" />
                    <button class="primary-btn" :disabled="busy">＋ Создать склад</button>
                </form>
                <p v-if="error" class="error-message">{{ error }}</p>
            </section>

            <section class="feature-card warehouse-card">
                <h3>Наценка на товары</h3>
                <p class="muted">
                    Цена реализации ЗЧ = цена прихода × (1 + наценка / 100). Подставляется в документ
                    Приход при сохранении, если цена реализации не указана вручную.
                </p>
                <label class="markup-option">
                    <input v-model="markup.mode" type="radio" value="flat" />
                    <span>Одинаковая наценка для всех</span>
                </label>
                <div v-if="markup.mode === 'flat'" class="markup-flat">
                    <label>
                        <span>Наценка, %</span>
                        <input v-model.number="markup.percent" type="number" min="0" step="0.01" aria-label="Наценка, %" />
                    </label>
                </div>
                <label class="markup-option">
                    <input v-model="markup.mode" type="radio" value="progressive" />
                    <span>Таблица с прогрессией</span>
                </label>
                <div v-if="markup.mode === 'progressive'" class="markup-tiers">
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Цена прихода от, ₽</th>
                                    <th>Цена прихода до, ₽</th>
                                    <th>Наценка, %</th>
                                    <th class="row-command-column" />
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in markup.tiers" :key="index">
                                    <td><input v-model.number="row.from" type="number" min="0" step="0.01" placeholder="0" :aria-label="`Строка ${index + 1}: цена прихода от`" /></td>
                                    <td><input v-model.number="row.to" type="number" min="0" step="0.01" placeholder="без ограничения" :aria-label="`Строка ${index + 1}: цена прихода до`" /></td>
                                    <td><input v-model.number="row.percent" type="number" min="0" step="0.01" :aria-label="`Строка ${index + 1}: наценка`" /></td>
                                    <td>
                                        <button type="button" class="delete-row" title="Удалить строку" @click="removeTier(index)">×</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-actions">
                        <button
                            type="button"
                            class="grid-add-row"
                            :disabled="markup.tiers.length >= maxTiers"
                            :title="markup.tiers.length >= maxTiers ? 'Не более 10 строк' : 'Добавить строку'"
                            @click="addTier"
                        >＋</button>
                        <small class="muted">Строк: {{ markup.tiers.length }} из {{ maxTiers }}. Пустое «до» — без верхней границы.</small>
                    </div>
                </div>
                <div class="markup-example">
                    <span>Проверка:</span>
                    цена прихода
                    <input v-model.number="examplePrice" type="number" min="0" step="0.01" aria-label="Пример цены прихода" />
                    ₽ →
                    <strong v-if="exampleSalePrice != null">цена реализации {{ money(exampleSalePrice) }} ₽</strong>
                    <strong v-else class="error-text">диапазон не найден</strong>
                </div>
                <p v-if="markupError" class="error-message">{{ markupError }}</p>
                <div class="action-buttons">
                    <button class="primary-btn" :disabled="busy" @click="saveMarkup">Сохранить наценку</button>
                </div>
            </section>
        </template>
    </section>
</template>

<style scoped>
.warehouse-settings {
    padding-bottom: 24px;
}
.warehouse-card {
    margin-top: 16px;
    display: grid;
    gap: 12px;
}
.warehouse-card h3 {
    margin: 0;
}
.warehouse-card .muted {
    margin: 0;
}
.warehouse-table td span {
    padding-left: 4px;
}
.warehouse-actions-col {
    width: 230px;
    text-align: right;
    white-space: nowrap;
}
.warehouse-actions-col button + button {
    margin-left: 6px;
}
.small-btn {
    padding: 5px 10px;
    font-size: 13px;
}
.warehouse-add {
    display: flex;
    gap: 8px;
}
.warehouse-add input {
    flex: 1;
    max-width: 420px;
}
.markup-option {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    cursor: pointer;
}
.markup-option input {
    width: auto;
    accent-color: var(--brand-teal-dark);
}
.markup-option span {
    display: inline;
    margin: 0;
    font-size: 15px;
    color: var(--ink);
}
.markup-flat,
.markup-tiers {
    margin-left: 26px;
    max-width: 640px;
}
.markup-flat input {
    max-width: 180px;
}
.markup-tiers table {
    min-width: 0;
}
.markup-tiers .table-actions {
    justify-content: flex-start;
    margin-top: 6px;
}
.markup-example {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    padding: 10px 12px;
    border-radius: 6px;
    background: var(--soft);
    font-size: 14px;
}
.markup-example input {
    width: 120px;
}
.markup-example > span {
    font-weight: 650;
}
.error-text {
    color: var(--red-error);
}
</style>
