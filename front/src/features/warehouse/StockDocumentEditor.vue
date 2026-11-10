<script>
import { marketplaceApi } from "../../api/marketplace.js";
import { money } from "../../data/permissions.js";
import WorkTypePicker from "../requests/WorkTypePicker.vue";
import ReferencePicker from "../../components/ReferencePicker.vue";

let sequence = 0;

function today() {
    const now = new Date();
    const pad = (value) => String(value).padStart(2, "0");
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/**
 * Документ Приход или Реализация. Черновик формы хранится во вкладке
 * (tab.draft), чтобы не потеряться при переходе в форму новой ЗЧ.
 */
export default {
    name: "StockDocumentEditor",
    components: { WorkTypePicker, ReferencePicker },
    props: {
        tab: { type: Object, required: true },
        references: { type: Object, required: true },
    },
    emits: ["saved", "close", "create-part", "notify"],
    data() {
        return {
            loading: true,
            busy: false,
            error: "",
            warehouses: [],
            customers: [],
            vehicles: [],
            managers: [],
            currentManagerId: "",
            payment: { paidOn: today(), amount: null, method: "bank", comment: "" },
            paymentError: "",
            markup: null,
        };
    },
    computed: {
        draft() {
            return this.tab.draft;
        },
        isReceipt() {
            return this.tab.type === "receipt";
        },
        posted() {
            return this.draft?.status === "posted";
        },
        editable() {
            return !this.posted && !this.busy;
        },
        title() {
            const name = this.isReceipt ? "Приход" : "Реализация";
            return this.draft?.number ? `${name} ${this.draft.number}` : `${name} · новый документ`;
        },
        partOptions() {
            return (this.references.parts || [])
                .filter((part) => part.active !== false)
                .map((part) => ({
                    ...part,
                    optionLabel: [part.name, part.article, part.manufacturer].filter(Boolean).join(" · "),
                }));
        },
        customerVehicles() {
            return this.vehicles.filter((row) => row.customerId === this.draft.customerId);
        },
        totals() {
            const rows = this.draft?.items || [];
            return {
                qty: rows.reduce((sum, row) => sum + Number(row.qty || 0), 0),
                amount: rows.reduce((sum, row) => sum + this.amount(row), 0),
            };
        },
        paymentMethods() {
            return this.draft?.paymentMethods || [
                { value: "bank", label: "Безналичный расчёт" },
                { value: "cash", label: "Наличные" },
                { value: "card", label: "Банковская карта" },
            ];
        },
        paymentStatusClass() {
            return { paid: "green", partial: "amber", unpaid: "red" }[this.draft?.paymentStatus] || "red";
        },
        canPay() {
            return Boolean(this.draft?.id) && !this.tab.dirty && Number(this.draft.debt || 0) > 0;
        },
        hasMissing() {
            return !this.isReceipt && (this.draft?.items || []).some((row) => row.sufficient === false);
        },
    },
    async mounted() {
        try {
            const [settings, parties] = await Promise.all([
                marketplaceApi.warehouseSettings(),
                marketplaceApi.warehouseCounterparties(),
            ]);
            this.warehouses = settings.warehouses;
            this.markup = settings.markup || null;
            this.customers = parties.customers;
            this.vehicles = parties.vehicles;
            this.managers = parties.managers;
            this.currentManagerId = parties.currentManagerId;
            if (!this.tab.draft) {
                if (this.tab.docId) {
                    this.accept((await marketplaceApi.stockDocument(this.tab.docId)).document);
                } else {
                    this.tab.draft = {
                        id: "",
                        type: this.tab.type,
                        status: "created",
                        statusLabel: "Создан",
                        number: "",
                        documentDate: today(),
                        warehouseId: this.warehouses[0]?.id || "",
                        supplierId: "",
                        customerId: "",
                        vehicleId: "",
                        comment: "",
                        revision: 0,
                        items: [],
                    };
                }
            }
            if (!this.draft.items.length && !this.posted) this.addRow();
        } catch (error) {
            this.error = error.message;
        } finally {
            this.loading = false;
        }
    },
    methods: {
        money,
        accept(doc) {
            this.tab.docId = doc.id;
            this.tab.draft = {
                ...doc,
                items: doc.items.map((row) => ({ ...row, _clientId: `row-${++sequence}` })),
            };
            this.tab.dirty = false;
            this.payment.amount = Number(doc.debt || 0) || null;
        },
        emptyRow() {
            return {
                _clientId: `row-${++sequence}`,
                id: "",
                catalogPartId: "",
                name: "",
                article: "",
                manufacturer: "",
                unit: "",
                qty: 1,
                purchasePrice: null,
                salePrice: null,
                managerId: this.currentManagerId,
            };
        },
        addRow() {
            this.draft.items.push(this.emptyRow());
        },
        removeRow(row) {
            const index = this.draft.items.indexOf(row);
            if (index >= 0) this.draft.items.splice(index, 1);
            this.touch();
        },
        /** Цена реализации по наценке из Настроек (как на сервере при сохранении). */
        markupPrice(purchase) {
            const price = Number(purchase);
            if (!this.markup || purchase === "" || purchase === null || !Number.isFinite(price)) return null;
            let percent = null;
            if (this.markup.mode === "flat") {
                percent = Number(this.markup.percent || 0);
            } else {
                const tier = (this.markup.tiers || []).find(
                    (row) => price >= Number(row.from || 0) && (row.to === null || row.to === "" || price <= Number(row.to)),
                );
                percent = tier ? Number(tier.percent) : null;
            }
            return percent === null ? null : Math.round(price * (1 + percent / 100) * 100) / 100;
        },
        purchaseChanged(row) {
            // Пока цену реализации не ввели вручную, она пересчитывается по наценке.
            if (row.salePrice === "" || row.salePrice === null || row.salePrice === undefined || row._saleAuto) {
                const price = this.markupPrice(row.purchasePrice);
                row.salePrice = price;
                row._saleAuto = price !== null;
            }
            this.touch();
        },
        saleChanged(row) {
            row._saleAuto = false;
            this.touch();
        },
        touch() {
            this.tab.dirty = true;
        },
        selectPart(row, part) {
            Object.assign(row, {
                catalogPartId: part.id,
                name: part.name,
                article: part.article || "",
                manufacturer: part.manufacturer || "",
                unit: part.unit || "",
                sufficient: undefined,
            });
            this.touch();
        },
        nameEdited(row, name) {
            row.name = name;
            row.catalogPartId = "";
            this.touch();
        },
        createPart(row, name) {
            this.$emit("create-part", { clientId: row._clientId, name });
        },
        amount(row) {
            const price = this.isReceipt ? row.purchasePrice : row.salePrice;
            return Math.round(Number(row.qty || 0) * Number(price || 0) * 100) / 100;
        },
        payload() {
            const blank = (value) => value === "" || value === null || value === undefined;
            const items = this.draft.items
                .filter((row) => row.catalogPartId || String(row.name || "").trim())
                .map((row) => ({
                    id: row.id || "",
                    catalogPartId: row.catalogPartId,
                    qty: Number(row.qty),
                    purchasePrice: this.isReceipt && !blank(row.purchasePrice) ? Number(row.purchasePrice) : null,
                    salePrice: blank(row.salePrice) ? null : Number(row.salePrice),
                    managerId: row.managerId || "",
                }));
            const body = {
                documentDate: this.draft.documentDate,
                warehouseId: this.draft.warehouseId,
                comment: this.draft.comment || "",
                items,
                ...(this.draft.id ? { revision: this.draft.revision } : { type: this.tab.type }),
            };
            if (this.isReceipt) body.supplierId = this.draft.supplierId || "";
            else Object.assign(body, { customerId: this.draft.customerId || "", vehicleId: this.draft.vehicleId || "" });
            return body;
        },
        async save(exit = false) {
            if (this.busy) return false;
            this.busy = true;
            this.error = "";
            try {
                const missing = this.draft.items.find((row) => String(row.name || "").trim() && !row.catalogPartId);
                if (missing) throw new Error(`Выберите запчасть из справочника: ${missing.name}`);
                const { document } = await marketplaceApi.saveStockDocument(this.draft.id, this.payload());
                this.accept(document);
                this.$emit("saved", document);
                this.$emit("notify", "Документ сохранён");
                if (exit) this.$emit("close");
                return true;
            } catch (error) {
                this.error = error.message;
                return false;
            } finally {
                this.busy = false;
            }
        },
        async post() {
            if (!(await this.save())) return;
            await this.command(() => marketplaceApi.postStockDocument(this.draft.id), "Документ проведён");
        },
        async unpost() {
            await this.command(() => marketplaceApi.unpostStockDocument(this.draft.id), "Проведение отменено");
        },
        async remove() {
            if (!window.confirm(`Удалить документ ${this.draft.number}?`)) return;
            this.busy = true;
            try {
                await marketplaceApi.deleteStockDocument(this.draft.id);
                this.$emit("saved", null);
                this.$emit("notify", "Документ удалён");
                this.$emit("close");
            } catch (error) {
                this.error = error.message;
            } finally {
                this.busy = false;
            }
        },
        async addPayment() {
            if (this.busy) return;
            this.busy = true;
            this.paymentError = "";
            try {
                const { document } = await marketplaceApi.addStockDocumentPayment(this.draft.id, {
                    paidOn: this.payment.paidOn,
                    amount: this.payment.amount === "" || this.payment.amount === null ? null : Number(this.payment.amount),
                    method: this.payment.method,
                    comment: this.payment.comment || "",
                });
                this.accept(document);
                this.payment.comment = "";
                this.$emit("saved", document);
                this.$emit("notify", "Оплата внесена");
            } catch (error) {
                this.paymentError = error.message;
            } finally {
                this.busy = false;
            }
        },
        async removePayment(row) {
            if (!window.confirm(`Удалить оплату ${this.money(row.amount)} ₽ от ${this.formatDate(row.paidOn)}?`)) return;
            this.busy = true;
            this.paymentError = "";
            try {
                const { document } = await marketplaceApi.deleteStockDocumentPayment(this.draft.id, row.id);
                this.accept(document);
                this.$emit("saved", document);
                this.$emit("notify", "Оплата удалена");
            } catch (error) {
                this.paymentError = error.message;
            } finally {
                this.busy = false;
            }
        },
        formatDate(value) {
            return String(value || "").split("-").reverse().join(".");
        },
        async command(action, message) {
            this.busy = true;
            this.error = "";
            try {
                const { document } = await action();
                this.accept(document);
                this.$emit("saved", document);
                this.$emit("notify", message);
            } catch (error) {
                this.error = error.message;
                // Обновляем отметки «недостаточно на складе».
                try {
                    this.accept((await marketplaceApi.stockDocument(this.draft.id)).document);
                } catch {}
            } finally {
                this.busy = false;
            }
        },
        rowClass(row) {
            if (this.isReceipt || this.posted) return "";
            return row.sufficient === false ? "stock-missing" : "";
        },
        customerChanged() {
            if (!this.customerVehicles.some((row) => row.id === this.draft.vehicleId)) this.draft.vehicleId = "";
            this.touch();
        },
    },
};
</script>

<template>
    <section class="document-panel stock-document">
        <div class="document-head">
            <div>
                <span class="eyebrow">Склад · {{ isReceipt ? "Приход" : "Реализация" }}</span>
                <h2>
                    {{ title }}
                    <span v-if="draft" class="status" :class="posted ? 'green' : 'amber'">{{ posted ? "Проведен" : "Создан" }}</span>
                    <span v-if="draft?.id" class="status" :class="paymentStatusClass">{{ draft.paymentStatusLabel || "Не оплачен" }}</span>
                </h2>
            </div>
        </div>
        <p v-if="loading" class="muted">Загружаем документ…</p>
        <template v-else-if="draft">
            <p class="stock-status-hint muted">
                <template v-if="isReceipt">
                    {{ posted ? "Проведен: ЗЧ оприходованы на склад." : "Создан: документ только содержит информацию, на склад ЗЧ не добавлены." }}
                </template>
                <template v-else>
                    {{ posted ? "Проведен: ЗЧ списаны со склада." : "Создан: ЗЧ в резерве, со склада не списаны." }}
                </template>
            </p>
            <div class="form-grid stock-head-grid">
                <label>
                    <span>Дата документа *</span>
                    <input v-model="draft.documentDate" type="date" :disabled="!editable" required @change="touch" />
                </label>
                <label>
                    <span>Склад *</span>
                    <select v-model="draft.warehouseId" :disabled="!editable" required @change="touch">
                        <option value="" disabled>Выберите склад</option>
                        <option v-for="row in warehouses" :key="row.id" :value="row.id">{{ row.name }}</option>
                    </select>
                    <small v-if="!warehouses.length" class="error-text">Создайте склад: Настройки → Складской учёт</small>
                </label>
                <label v-if="isReceipt" class="full-sm">
                    <span>Поставщик *</span>
                    <ReferencePicker
                        v-model="draft.supplierId"
                        :label="references.suppliers?.find((row) => row.id === draft.supplierId)?.name || ''"
                        :options="references.suppliers || []"
                        :max-results="100000"
                        :show-all-on-focus-limit="100000"
                        :disabled="!editable"
                        placeholder="Начните вводить поставщика"
                        @update:model-value="touch"
                    />
                </label>
                <template v-else>
                    <label>
                        <span>Заказчик *</span>
                        <ReferencePicker
                            v-model="draft.customerId"
                            :label="customers.find((row) => row.id === draft.customerId)?.name || ''"
                            :options="customers"
                            :max-results="100000"
                            :show-all-on-focus-limit="100000"
                            :disabled="!editable"
                            placeholder="Начните вводить Заказчика"
                            @update:model-value="customerChanged"
                        />
                    </label>
                    <label>
                        <span>ТС Заказчика *</span>
                        <select v-model="draft.vehicleId" :disabled="!editable || !draft.customerId" required @change="touch">
                            <option value="" disabled>{{ draft.customerId ? "Выберите ТС" : "Сначала выберите Заказчика" }}</option>
                            <option v-for="row in customerVehicles" :key="row.id" :value="row.id">{{ row.name }}</option>
                        </select>
                    </label>
                </template>
            </div>

            <h3 class="stock-items-title">Запчасти</h3>
            <div class="table-wrap excel-grid-wrap">
                <table class="excel-grid stock-grid" :class="isReceipt ? 'receipt-grid' : 'sale-grid'">
                    <thead>
                        <tr>
                            <th class="stock-col-name">Наименование ЗЧ</th>
                            <th>Артикул</th>
                            <th v-if="!isReceipt">Производитель</th>
                            <th v-if="!isReceipt">Ед.</th>
                            <th>Количество</th>
                            <th v-if="!isReceipt">На складе</th>
                            <th v-if="isReceipt">Цена прихода, ₽</th>
                            <th>Цена реализации, ₽</th>
                            <th>Стоимость, ₽</th>
                            <th v-if="!isReceipt">Склад</th>
                            <th>Менеджер</th>
                            <th class="row-command-column" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in draft.items" :key="row._clientId" :class="rowClass(row)">
                            <td :title="row.name">
                                <WorkTypePicker
                                    v-if="editable"
                                    v-model="row.catalogPartId"
                                    :name="row.name"
                                    :options="partOptions"
                                    placeholder="Начните вводить запчасть"
                                    create-label="Добавить запись"
                                    always-create
                                    @select="selectPart(row, $event)"
                                    @edit="nameEdited(row, $event)"
                                    @create="createPart(row, $event)"
                                /><span v-else>{{ row.name }}</span>
                            </td>
                            <td :title="row.article">{{ row.article || "—" }}</td>
                            <td v-if="!isReceipt" :title="row.manufacturer">{{ row.manufacturer || "—" }}</td>
                            <td v-if="!isReceipt">{{ row.unit || "—" }}</td>
                            <td>
                                <input v-if="editable" v-model.number="row.qty" type="number" min="0.001" step="0.001" aria-label="Количество" @input="touch" />
                                <span v-else>{{ row.qty }}</span>
                            </td>
                            <td v-if="!isReceipt">{{ row.onHand ?? "—" }}</td>
                            <td v-if="isReceipt">
                                <input v-if="editable" v-model.number="row.purchasePrice" type="number" min="0" step="0.01" aria-label="Цена прихода" @input="purchaseChanged(row)" />
                                <span v-else>{{ money(row.purchasePrice) }}</span>
                            </td>
                            <td>
                                <input
                                    v-if="editable"
                                    v-model.number="row.salePrice"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    :placeholder="isReceipt ? 'по наценке' : 'из Прихода'"
                                    aria-label="Цена реализации"
                                    @input="isReceipt ? saleChanged(row) : touch()"
                                />
                                <span v-else>{{ row.salePrice == null ? "—" : money(row.salePrice) }}</span>
                            </td>
                            <td>{{ money(amount(row)) }}</td>
                            <td v-if="!isReceipt" :title="row.stockLabel">
                                <span v-if="row.stockLabel" class="stock-badge" :class="{ missing: row.sufficient === false }">{{ row.stockLabel }}</span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td>
                                <select v-if="editable" v-model="row.managerId" aria-label="Менеджер" @change="touch">
                                    <option v-for="manager in managers" :key="manager.id" :value="manager.id">{{ manager.name }}</option>
                                </select>
                                <span v-else>{{ row.manager || "—" }}</span>
                            </td>
                            <td>
                                <button v-if="editable" type="button" class="delete-row" title="Удалить позицию" @click="removeRow(row)">×</button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td :colspan="isReceipt ? 2 : 4"><strong>Итого</strong></td>
                            <td><strong>{{ totals.qty }}</strong></td>
                            <td :colspan="isReceipt ? 2 : 2" />
                            <td><strong>{{ money(totals.amount) }}</strong></td>
                            <td :colspan="isReceipt ? 2 : 3" />
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="table-actions">
                <button v-if="editable" class="grid-add-row" type="button" title="Добавить строку" @click="addRow">＋</button>
                <small v-if="isReceipt && editable" class="muted">«Цена реализации» рассчитывается по наценке из Настроек, пока её не изменили вручную.</small>
                <small v-if="hasMissing" class="error-text">Строки, выделенные красным: ЗЧ нет на складе или их недостаточно.</small>
            </div>
            <label class="stock-comment">
                <span>Комментарий</span>
                <textarea v-model="draft.comment" :disabled="!editable" rows="2" maxlength="2000" @input="touch" />
            </label>
            <section v-if="draft.id" class="stock-payments">
                <h3>{{ isReceipt ? "Оплата поставщику" : "Оплата от Заказчика" }}</h3>
                <div class="stock-payment-summary">
                    <span>Сумма документа <strong>{{ money(draft.amount || 0) }} ₽</strong></span>
                    <span>Оплачено <strong>{{ money(draft.paid || 0) }} ₽</strong></span>
                    <span>К оплате <strong :class="{ 'error-text': Number(draft.debt || 0) > 0 }">{{ money(draft.debt || 0) }} ₽</strong></span>
                </div>
                <div v-if="draft.payments?.length" class="table-wrap">
                    <table class="stock-payment-table">
                        <thead>
                            <tr>
                                <th>Дата оплаты</th>
                                <th>Сумма, ₽</th>
                                <th>Способ</th>
                                <th>Комментарий</th>
                                <th>Внёс</th>
                                <th class="row-command-column" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in draft.payments" :key="row.id">
                                <td>{{ formatDate(row.paidOn) }}</td>
                                <td>{{ money(row.amount) }}</td>
                                <td>{{ row.methodLabel }}</td>
                                <td :title="row.comment">{{ row.comment || "—" }}</td>
                                <td>{{ row.author || "—" }}</td>
                                <td>
                                    <button type="button" class="delete-row" title="Удалить оплату" :disabled="busy" @click="removePayment(row)">×</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="muted">Оплат пока нет.</p>
                <p v-if="tab.dirty && Number(draft.debt || 0) > 0" class="muted">Сохраните документ, чтобы внести оплату.</p>
                <form v-if="canPay" class="stock-payment-form" @submit.prevent="addPayment">
                    <label>
                        <span>Дата оплаты</span>
                        <input v-model="payment.paidOn" type="date" required />
                    </label>
                    <label>
                        <span>Сумма, ₽</span>
                        <input v-model.number="payment.amount" type="number" min="0.01" step="0.01" :max="draft.debt" required />
                    </label>
                    <label>
                        <span>Способ оплаты</span>
                        <select v-model="payment.method">
                            <option v-for="item in paymentMethods" :key="item.value" :value="item.value">{{ item.label }}</option>
                        </select>
                    </label>
                    <label class="stock-payment-comment">
                        <span>Комментарий</span>
                        <input v-model="payment.comment" maxlength="1000" placeholder="Номер платёжки, чека…" />
                    </label>
                    <button class="primary-btn" :disabled="busy">{{ isReceipt ? "Оплатить" : "Принять оплату" }}</button>
                </form>
                <p v-if="paymentError" class="error-message">{{ paymentError }}</p>
            </section>
            <p v-if="error" class="error-message">{{ error }}</p>
            <div class="catalog-actions">
                <template v-if="!posted">
                    <button class="primary-btn" :disabled="busy" @click="save(false)">Сохранить</button>
                    <button class="ghost-btn" :disabled="busy" @click="save(true)">Сохранить и выйти</button>
                    <button class="primary-btn post-btn" :disabled="busy" @click="post">Провести</button>
                    <button v-if="draft.id" class="danger-btn" :disabled="busy" @click="remove">Удалить</button>
                </template>
                <button v-else class="ghost-btn" :disabled="busy" @click="unpost">Отменить проведение</button>
                <button class="ghost-btn" :disabled="busy" @click="$emit('close')">Закрыть</button>
            </div>
        </template>
        <p v-else-if="error" class="error-message">{{ error }}</p>
    </section>
</template>

<style scoped>
.stock-document {
    padding-bottom: 0;
}
.stock-document h2 .status {
    margin-left: 10px;
    vertical-align: middle;
    font-size: 13px;
}
.stock-status-hint {
    margin: 10px 0 0;
    font-size: 14px;
}
.stock-head-grid {
    grid-template-columns: 180px minmax(180px, 1fr) minmax(220px, 1.4fr) minmax(220px, 1.4fr);
    margin-top: 14px;
}
.stock-items-title {
    margin: 22px 0 8px;
}
.stock-grid {
    min-width: 1100px;
}
.stock-grid.sale-grid {
    min-width: 1460px;
}
.stock-grid th {
    white-space: nowrap;
}
.stock-col-name {
    width: 26%;
}
.sale-grid .stock-col-name {
    width: 20%;
}
.stock-grid tfoot td {
    background: var(--soft);
    border-top: 1px solid var(--line);
    padding: 6px 7px;
}
.stock-grid tr.stock-missing > td {
    background: #fde4e2;
    color: #8f1f17;
}
.stock-badge,
.table-wrap td > span.stock-badge {
    display: inline-block;
    overflow: visible;
    padding: 2px 8px;
    border-radius: 999px;
    background: #dcf1e8;
    color: #267353;
    font-size: 12px;
    font-weight: 650;
    white-space: normal;
    line-height: 1.25;
}
.stock-badge.missing,
.table-wrap td > span.stock-badge.missing {
    background: #f9c9c4;
    color: #8f1f17;
}
.stock-payments {
    margin-top: 18px;
    display: grid;
    gap: 10px;
}
.stock-payments h3 {
    margin: 0;
}
.stock-payments .muted {
    margin: 0;
}
.stock-payment-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 24px;
    padding: 10px 12px;
    border-radius: 6px;
    background: var(--soft);
    font-size: 14px;
}
.stock-payment-summary strong {
    margin-left: 4px;
}
.stock-payment-table {
    min-width: 0;
}
.stock-payment-form {
    display: grid;
    grid-template-columns: 170px 150px 200px minmax(200px, 1fr) auto;
    gap: 10px;
    align-items: end;
}
@media (max-width: 900px) {
    .stock-payment-form {
        grid-template-columns: 1fr 1fr;
    }
    .stock-payment-comment {
        grid-column: 1 / -1;
    }
}
.stock-comment {
    display: block;
    margin-top: 14px;
    max-width: 760px;
}
.stock-comment textarea {
    width: 100%;
}
.table-actions {
    justify-content: flex-start;
    margin-top: 6px;
}
.error-text {
    color: var(--red-error);
}
@media (max-width: 900px) {
    .stock-head-grid {
        grid-template-columns: 1fr;
    }
}
</style>
