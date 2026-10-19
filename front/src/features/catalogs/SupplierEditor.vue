<script>
import PhoneInput from "../../components/PhoneInput.vue";

const FIELDS = {
    name: "Полное наименование",
    shortName: "Краткое наименование",
    taxId: "ИНН",
    kpp: "КПП",
    ogrn: "ОГРН",
    registrationDate: "Дата регистрации",
    legalFormName: "Наименование организационно-правовой формы",
    lastName: "Фамилия ИП",
    firstName: "Имя ИП",
    middleName: "Отчество ИП",
    legalAddress: "Юридический адрес / адрес регистрации ИП",
    actualAddress: "Фактический адрес",
    postalAddress: "Почтовый адрес",
    directorName: "Руководитель",
    okpo: "ОКПО",
    oktmo: "ОКТМО",
    okved: "ОКВЭД",
    bankName: "Наименование банка",
    bik: "БИК",
    settlementAccount: "Расчётный счёт",
    correspondentAccount: "Корреспондентский счёт",
    treasuryAccount: "Казначейский счёт",
    taxRegime: "Система налогообложения",
    vatRate: "Ставка НДС / без НДС",
};
const OPTIONAL = [
    "shortName",
    "middleName",
    "okpo",
    "oktmo",
    "okved",
    "treasuryAccount",
];

export default {
    name: "SupplierEditor",
    components: { PhoneInput },
    props: {
        record: { type: Object, required: true },
        references: { type: Object, required: true },
        saveRecord: { type: Function, required: true },
        embedded: Boolean,
    },
    emits: ["saved", "saved-and-exit", "close"],
    data() {
        return { form: {}, busy: false, error: "", fieldErrors: {} };
    },
    computed: {
        editable() {
            return this.record.permissions?.canEdit === true;
        },
        individual() {
            return (
                this.references.supplierLegalForms?.find(
                    (row) => row.id === this.form.legalForm,
                )?.individual === true
            );
        },
        fields() {
            return Object.fromEntries(
                Object.entries(FIELDS)
                    .filter(([key]) => {
                        if (
                            ["lastName", "firstName", "middleName"].includes(
                                key,
                            )
                        )
                            return this.individual;
                        if (["kpp", "directorName"].includes(key))
                            return !this.individual;
                        if (key === "legalFormName")
                            return this.form.legalForm === "other";
                        return true;
                    })
                    .map(([key, label]) => [
                        key,
                        key === "ogrn" && this.individual ? "ОГРНИП" : label,
                    ]),
            );
        },
    },
    watch: {
        record: {
            immediate: true,
            handler(record) {
                this.form = Object.fromEntries(
                    Object.keys(FIELDS).map((key) => [key, record[key] ?? ""]),
                );
                this.form.legalForm = record.legalForm || "";
                this.form.active = record.active !== false;
                this.form.contacts = JSON.parse(
                    JSON.stringify(
                        record.contacts?.length
                            ? record.contacts
                            : [this.emptyContact()],
                    ),
                );
            },
        },
    },
    methods: {
        emptyContact() {
            return {
                lastName: "",
                firstName: "",
                middleName: "",
                position: "",
                phone: "",
                email: "",
            };
        },
        required(key) {
            return !OPTIONAL.includes(key);
        },
        changedForm() {
            if (this.individual) {
                this.form.kpp = "";
                this.form.directorName = "";
            } else {
                this.form.lastName = "";
                this.form.firstName = "";
                this.form.middleName = "";
            }
            if (this.form.legalForm !== "other") this.form.legalFormName = "";
        },
        maxLength(key) {
            return (
                {
                    taxId: this.individual ? 12 : 10,
                    kpp: 9,
                    ogrn: this.individual ? 15 : 13,
                    bik: 9,
                    settlementAccount: 20,
                    correspondentAccount: 20,
                    treasuryAccount: 20,
                }[key] || (key.toLowerCase().includes("address") ? 1000 : 255)
            );
        },
        async save(exit = false) {
            if (!this.editable || this.busy) return;
            if (!this.$refs.form.reportValidity()) return;
            this.busy = true;
            this.error = "";
            this.fieldErrors = {};
            try {
                const saved = await this.saveRecord(
                    this.record,
                    JSON.parse(JSON.stringify(this.form)),
                );
                this.$emit(exit ? "saved-and-exit" : "saved", saved);
            } catch (error) {
                this.error = error.message;
                this.fieldErrors = error.details || error.errors || {};
            } finally {
                this.busy = false;
            }
        },
    },
};
</script>

<template>
    <section class="document-panel catalog-editor supplier-editor">
        <div class="document-head">
            <div>
                <span class="eyebrow">Поставщики</span>
                <h2>{{ record.isNew ? "Новый поставщик" : record.name }}</h2>
            </div>
        </div>
        <form ref="form" @submit.prevent="save(false)">
            <fieldset :disabled="busy || !editable">
                <label class="supplier-legal-form"
                    ><span>Организационно-правовая форма *</span>
                    <select
                        v-model="form.legalForm"
                        required
                        @change="changedForm"
                    >
                        <option value="" disabled>
                            Выберите правовую форму
                        </option>
                        <option
                            v-for="row in references.supplierLegalForms || []"
                            :key="row.id"
                            :value="row.id"
                        >
                            {{ row.name }}
                        </option>
                    </select>
                </label>
                <template v-if="form.legalForm">
                    <div class="form-grid supplier-fields">
                        <label v-for="(label, key) in fields" :key="key">
                            <span
                                >{{ label
                                }}{{ required(key) ? " *" : "" }}</span
                            >
                            <input
                                v-model="form[key]"
                                :type="
                                    key === 'registrationDate' ? 'date' : 'text'
                                "
                                :maxlength="maxLength(key)"
                                :required="required(key)"
                                :title="form[key]"
                            />
                            <small
                                v-if="fieldErrors[key]"
                                class="field-error"
                                >{{ fieldErrors[key] }}</small
                            >
                        </label>
                    </div>
                    <h3>Контактные лица</h3>
                    <div
                        v-for="(person, index) in form.contacts"
                        :key="index"
                        class="supplier-contact form-grid"
                    >
                        <label
                            ><span>Фамилия *</span
                            ><input
                                v-model="person.lastName"
                                required
                                maxlength="100"
                        /></label>
                        <label
                            ><span>Имя *</span
                            ><input
                                v-model="person.firstName"
                                required
                                maxlength="100"
                        /></label>
                        <label
                            ><span>Отчество</span
                            ><input v-model="person.middleName" maxlength="100"
                        /></label>
                        <label
                            ><span>Должность</span
                            ><input v-model="person.position" maxlength="150"
                        /></label>
                        <label
                            ><span>Мобильный телефон *</span
                            ><PhoneInput v-model="person.phone" required
                        /></label>
                        <label
                            ><span>E-mail</span
                            ><input
                                v-model="person.email"
                                type="email"
                                maxlength="255"
                        /></label>
                        <button
                            v-if="form.contacts.length > 1"
                            type="button"
                            class="link-btn"
                            @click="form.contacts.splice(index, 1)"
                        >
                            Удалить контакт
                        </button>
                    </div>
                    <button
                        type="button"
                        class="ghost-btn"
                        @click="form.contacts.push(emptyContact())"
                    >
                        Добавить контактное лицо
                    </button>
                    <label class="checkbox-label supplier-active"
                        ><input v-model="form.active" type="checkbox" /><span
                            >Активен</span
                        ></label
                    >
                </template>
            </fieldset>
        </form>
        <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
        <div class="record-editor-footer action-buttons">
            <button
                v-if="editable"
                class="office-action primary-btn"
                title="Сохранить"
                aria-label="Сохранить"
                :disabled="busy || !form.legalForm"
                @click="save(false)"
            >
                <svg
                    width="20"
                    height="20"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                    aria-hidden="true"
                >
                    <path d="M5 3h12l4 4v14H3V3h2z M7 3v7h10V3 M7 21v-7h10v7" />
                </svg>
            </button>
            <button
                v-if="editable && !embedded"
                class="office-action ghost-btn"
                title="Сохранить и выйти"
                aria-label="Сохранить и выйти"
                :disabled="busy || !form.legalForm"
                @click="save(true)"
            >
                <svg
                    width="20"
                    height="20"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                    aria-hidden="true"
                >
                    <path d="M4 3h11v6H7V3 M3 3v18h11 M15 12h7m-3-3 3 3-3 3" />
                </svg>
            </button>
            <button
                v-if="embedded"
                class="ghost-btn"
                :disabled="busy"
                @click="$emit('close')"
            >
                Отмена
            </button>
        </div>
    </section>
</template>
