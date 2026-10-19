<script>
import PhoneInput from "../../components/PhoneInput.vue";

export default {
    name: "PersonCatalogEditor",
    components: { PhoneInput },
    props: {
        record: {
            type: Object,
            required: true,
        },
        records: {
            type: Array,
            default: () => [],
        },
        user: {
            type: Object,
            required: true,
        },
        saveRecord: {
            type: Function,
            required: true,
        },
        deleteRecord: {
            type: Function,
            required: true,
        },
    },
    emits: ["saved", "saved-and-exit", "deleted"],
    data() {
        return {
            form: this.initialForm(),
            deleteRequested: false,
            busy: false,
            error: "",
        };
    },
    computed: {
        isManager() {
            return this.user.role === "manager";
        },
        isNew() {
            return this.record.isNew === true;
        },
        editable() {
            return this.record.permissions?.canEdit !== false;
        },
        title() {
            return this.record.kind === "drivers" ? "Водитель" : "Контактное лицо";
        },
        customers() {
            return this.records.filter((item) => item.kind === "customers");
        },
        organizationName() {
            if (!this.isManager) return this.user.organization;

            return this.customers.find(
                (item) => item.id === this.form.organizationId,
            )?.name || this.record.company || "";
        },
    },
    watch: {
        "record.id"() {
            this.form = this.initialForm();
            this.deleteRequested = false;
            this.error = "";
        },
    },
    methods: {
        initialForm() {
            return {
                kind: this.record.kind === "drivers" ? "driver" : "contact",
                organizationId:
                    this.record.organizationId
                    || (this.user.role === "customer" ? this.user.organizationId : ""),
                lastName: this.record.lastName || "",
                firstName: this.record.firstName || "",
                middleName: this.record.middleName || "",
                phone: this.record.phone || "+7",
            };
        },
        markDelete() {
            this.deleteRequested = !this.deleteRequested;
        },
        async save(exitAfter = false) {
            if (!this.editable) {
                return;
            }

            this.busy = true;
            this.error = "";

            try {
                if (this.deleteRequested && !this.isNew) {
                    const result = await this.deleteRecord(this.record);
                    this.$emit("deleted", result);
                    return;
                }

                const payload = { ...this.form };

                if (!this.isManager) delete payload.organizationId;

                const saved = await this.saveRecord(this.record, payload);
                this.$emit(exitAfter ? "saved-and-exit" : "saved", saved);
            } catch (error) {
                this.error = error.message;
            } finally {
                this.busy = false;
            }
        },
    },
};
</script>

<template>
    <section class="document-panel person-catalog-editor">
        <span class="eyebrow">{{ record.kind === "drivers" ? "Водители" : "Контактные лица" }}</span>
        <h1>{{ isNew ? `Новое: ${title.toLowerCase()}` : record.fullName }}</h1>

        <form @submit.prevent="save()">
            <fieldset class="catalog-fieldset" :disabled="!editable || busy">
                <div class="form-grid">
                    <label>
                        Фамилия
                        <input v-model.trim="form.lastName" required maxlength="100" />
                    </label>
                    <label>
                        Имя
                        <input v-model.trim="form.firstName" required maxlength="100" />
                    </label>
                    <label>
                        Отчество
                        <input v-model.trim="form.middleName" maxlength="100" />
                    </label>
                    <label v-if="isManager">
                        Организация
                        <select
                            v-model="form.organizationId"
                            required
                            :disabled="!isNew"
                        >
                            <option value="">Выберите организацию</option>
                            <option
                                v-for="customer in customers"
                                :key="customer.id"
                                :value="customer.id"
                            >
                                {{ customer.name }}
                            </option>
                        </select>
                    </label>
                    <label v-else>
                        Организация
                        <input :value="organizationName" readonly disabled />
                    </label>
                    <label>
                        Сотовый телефон
                        <PhoneInput v-model="form.phone" required />
                    </label>
                </div>

                <p v-if="deleteRequested" class="warning-banner">
                    Запись будет удалена после нажатия кнопки «Сохранить».
                </p>

                <div class="catalog-actions">
                    <button class="office-action primary-btn" :disabled="busy" title="Сохранить" aria-label="Сохранить"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 3h12l4 4v14H3V3h2z M7 3v7h10V3 M7 21v-7h10v7" /></svg></button>
                    <button
                        type="button"
                        class="office-action ghost-btn"
                        :disabled="busy"
                        @click="save(true)"
                     title="Сохранить и выйти" aria-label="Сохранить и выйти"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 3h11v6H7V3 M3 3v18h11 M15 12h7m-3-3 3 3-3 3" /></svg></button>
                    <button
                        v-if="!isNew"
                        type="button"
                        class="danger-btn"
                        :disabled="busy"
                        @click="markDelete"
                    >
                        {{ deleteRequested ? "Отменить удаление" : "Удалить запись" }}
                    </button>
                </div>
            </fieldset>

            <p v-if="error" class="error-message">{{ error }}</p>
        </form>
    </section>
</template>
