<script>
import { formatDateTime } from "../../data/permissions.js";
import { marketplaceApi } from "../../api/marketplace.js";
import SequentialVehiclePicker from "./SequentialVehiclePicker.vue";
import {
    apiVehicleFieldErrors,
    validateVehicleForm,
} from "./vehicleValidation.js";

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

export default {
    name: "VehicleCatalogEditor",
    components: { SequentialVehiclePicker },
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
            default: null,
        },
        userRole: {
            type: String,
            default: "",
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
            fieldErrors: {},
        };
    },
    computed: {
        isManager() {
            return (this.user?.role || this.userRole) === "manager";
        },
        customers() {
            return this.records.filter((item) => item.kind === "customers");
        },
        isNew() {
            return this.record.isNew === true;
        },
        editable() {
            return this.record.permissions?.canEdit !== false
                && this.record.pendingApproval !== true;
        },
        peopleByKind() {
            const organizationId = this.form.organizationId
                || this.record.organizationId
                || this.user?.organizationId;

            return {
                contacts: this.records.filter(
                    (item) => item.kind === "contactPersons"
                        && item.organizationId === organizationId,
                ),
                drivers: this.records.filter(
                    (item) => item.kind === "drivers"
                        && item.organizationId === organizationId,
                ),
            };
        },
    },
    watch: {
        "record.id"() {
            this.form = this.initialForm();
            this.deleteRequested = false;
            this.error = "";
            this.fieldErrors = {};
        },
    },
    methods: {
        formatDateTime,
        initialForm() {
            return {
                make: this.record.make || "",
                model: this.record.model || "",
                vehicleModelId: this.record.vehicleModelId || "",
                vin: this.record.vin || "",
                plate: this.record.plate || "",
                year: this.record.year ?? null,
                organizationId:
                    this.record.organizationId || this.user?.organizationId || "",
                baselineServiceMileage:
                    this.record.baselineServiceMileage
                    ?? this.record.previousServiceMileage
                    ?? null,
                laborHourRate: this.record.laborHourRate ?? null,
                laborHourRateOfficial: this.record.laborHourRateOfficial ?? null,
                laborHourRateOther: this.record.laborHourRateOther ?? null,
                laborRateCoefficient:
                    this.record.laborRateCoefficient ?? 1,
                contactPersonIds: this.initialPersonIds("contacts"),
                driverPersonIds: this.initialPersonIds("drivers"),
            };
        },
        initialPersonIds(kind) {
            const ids = (this.record[kind] || [])
                .map((person) => person.personId)
                .filter(Boolean);

            return ids.length ? ids : [""];
        },
        organizationChanged() {
            this.form.contactPersonIds = [""];
            this.form.driverPersonIds = [""];
            this.clearFieldError("organizationId");
            this.clearFieldError("contactPersonIds");
            this.clearFieldError("driverPersonIds");
        },
        vehicleReferenceChanged(selection) {
            this.form.make = selection.make;
            this.form.model = selection.model;
            this.form.vehicleModelId = selection.vehicleModelId;
            this.clearFieldError("vehicleReference");
        },
        loadVehicleMakes(search) {
            return marketplaceApi.vehicleMakes(search);
        },
        loadVehicleModels(makeId, search) {
            return marketplaceApi.vehicleModels(makeId, search);
        },
        createVehicleModel(record) {
            return marketplaceApi.createVehicleModel(record);
        },
        addPersonRow(field) {
            this.form[field].push("");
        },
        removePersonRow(field, index) {
            if (this.form[field].length === 1) {
                this.form[field][0] = "";

                return;
            }

            this.form[field].splice(index, 1);
        },
        personById(kind, id) {
            return this.peopleByKind[kind].find((person) => person.id === id);
        },
        personOptionDisabled(field, id, index) {
            return this.form[field].some(
                (selected, selectedIndex) =>
                    selectedIndex !== index && selected === id,
            );
        },
        markDelete() {
            this.deleteRequested = !this.deleteRequested;
        },
        clearFieldError(field) {
            if (!this.fieldErrors[field] && !this.error) return;

            const next = { ...this.fieldErrors };
            delete next[field];
            this.fieldErrors = next;

            if (Object.keys(next).length === 0) {
                this.error = "";
            }
        },
        payload() {
            const payload = clone(this.form);

            payload.make = String(payload.make || "").trim();
            payload.model = String(payload.model || "").trim();
            payload.vin = String(payload.vin || "").trim().toUpperCase();
            payload.plate = String(payload.plate || "").trim().toUpperCase();

            payload.contactPersonIds = [...new Set(
                payload.contactPersonIds.filter(Boolean),
            )];
            payload.driverPersonIds = [...new Set(
                payload.driverPersonIds.filter(Boolean),
            )];

            if (!this.isManager) {
                delete payload.organizationId;
                delete payload.laborHourRate;
                delete payload.laborHourRateOfficial;
                delete payload.laborHourRateOther;
                delete payload.laborRateCoefficient;
            }

            for (const key of ["laborHourRate", "laborHourRateOfficial", "laborHourRateOther"]) {
                if (payload[key] === "") payload[key] = null;
            }

            return payload;
        },
        async save(exitAfter = false) {
            if (!this.editable) {
                return;
            }

            const referenceChanged = this.form.make !== (this.record.make || "")
                || this.form.model !== (this.record.model || "")
                || this.form.vehicleModelId !== (this.record.vehicleModelId || "");
            const errors = validateVehicleForm(this.form, {
                isManager: this.isManager,
                isNew: this.isNew,
                referenceChanged,
                contactIds: this.peopleByKind.contacts.map((item) => item.id),
                driverIds: this.peopleByKind.drivers.map((item) => item.id),
            });

            if (Object.keys(errors).length > 0) {
                this.fieldErrors = errors;
                this.error = "Проверьте выделенные поля. Под каждым полем указан правильный формат.";
                return;
            }

            this.form.vin = this.form.vin.trim().toUpperCase();
            this.form.plate = this.form.plate.trim().toUpperCase();

            this.busy = true;
            this.error = "";
            this.fieldErrors = {};

            try {
                if (this.deleteRequested && !this.isNew) {
                    const result = await this.deleteRecord(this.record);
                    this.$emit("deleted", result);
                    return;
                }

                const result = await this.saveRecord(
                    this.record,
                    this.payload(),
                );
                this.$emit(
                    exitAfter ? "saved-and-exit" : "saved",
                    result.record,
                    result.pendingApproval,
                );
            } catch (error) {
                this.fieldErrors = apiVehicleFieldErrors(error);
                this.error = Object.values(this.fieldErrors)[0] || error.message;
            } finally {
                this.busy = false;
            }
        },
    },
};
</script>

<template>
    <section class="document-panel vehicle-catalog-editor">
        <span class="eyebrow">Справочник ТС Заказчиков</span>
        <h1>{{ isNew ? "Новый автомобиль" : record.vehicle }}</h1>
        <p v-if="record.pendingApproval" class="info-banner">
            Изменение ожидает согласования Управляющей компании.
        </p>

        <form novalidate @submit.prevent="save()">
            <fieldset class="catalog-fieldset" :disabled="!editable || busy">
            <div class="form-grid">
                <label v-if="isManager">
                    Наименование заказчика
                    <select
                        v-model="form.organizationId"
                        required
                        :aria-invalid="Boolean(fieldErrors.organizationId)"
                        @change="organizationChanged"
                    >
                        <option value="">Выберите заказчика</option>
                        <option
                            v-for="customer in customers"
                            :key="customer.id"
                            :value="customer.id"
                        >
                            {{ customer.name }}
                        </option>
                    </select>
                    <small v-if="fieldErrors.organizationId" class="field-error">
                        {{ fieldErrors.organizationId }}
                    </small>
                </label>

                <SequentialVehiclePicker
                    class="full"
                    :reference="record.technicalReference"
                    :disabled="!editable || busy"
                    @change="vehicleReferenceChanged"
                />
                <p v-if="fieldErrors.vehicleReference" class="field-error full">
                    {{ fieldErrors.vehicleReference }}
                </p>

                <label>
                    VIN
                    <input
                        v-model.trim="form.vin"
                        maxlength="17"
                        autocomplete="off"
                        :aria-invalid="Boolean(fieldErrors.vin)"
                        @input="clearFieldError('vin')"
                    />
                    <small v-if="fieldErrors.vin" class="field-error">
                        {{ fieldErrors.vin }}
                    </small>
                </label>

                <label>
                    Госномер
                    <input
                        v-model="form.plate"
                        maxlength="30"
                        :aria-invalid="Boolean(fieldErrors.plate)"
                        @input="clearFieldError('plate')"
                    />
                    <small v-if="fieldErrors.plate" class="field-error">
                        {{ fieldErrors.plate }}
                    </small>
                </label>

                <label>
                    Год выпуска
                    <input
                        v-model.number="form.year"
                        type="number"
                        min="1900"
                        max="2100"
                        :aria-invalid="Boolean(fieldErrors.year)"
                        @input="clearFieldError('year')"
                    />
                    <small v-if="fieldErrors.year" class="field-error">
                        {{ fieldErrors.year }}
                    </small>
                </label>

                <label>
                    Предыдущий пробег, км
                    <input
                        v-model.number="form.baselineServiceMileage"
                        type="number"
                        min="0"
                        max="999999999"
                        step="1"
                        :aria-invalid="Boolean(fieldErrors.baselineServiceMileage)"
                        @input="clearFieldError('baselineServiceMileage')"
                    />
                    <small v-if="fieldErrors.baselineServiceMileage" class="field-error">
                        {{ fieldErrors.baselineServiceMileage }}
                    </small>
                </label>

                <div class="previous-mileage-info">
                    <span>Дата предыдущего пробега</span>
                    <strong>{{ formatDateTime(record.previousServiceAt) }}</strong>
                </div>

                <label v-if="isManager">
                    Общая стоимость нормо/часа, ₽
                    <input
                        v-model.number="form.laborHourRate"
                        type="number"
                        min="0"
                        max="1000000000"
                        step="0.01"
                        :aria-invalid="Boolean(fieldErrors.laborHourRate)"
                        @input="clearFieldError('laborHourRate')"
                    />
                    <small v-if="fieldErrors.laborHourRate" class="field-error">
                        {{ fieldErrors.laborHourRate }}
                    </small>
                </label>

                <label v-if="isManager" v-for="[key, label] in [['laborHourRateOfficial', 'Официальная'], ['laborHourRateOther', 'Прочая']]" :key="key">
                    {{ label }} стоимость нормо/часа, ₽
                    <input v-model.number="form[key]" type="number" min="0" max="1000000000" step="0.01" />
                    <small v-if="fieldErrors[key]" class="field-error">{{ fieldErrors[key] }}</small>
                </label>
                <label v-if="isManager">
                    Поправочный коэффициент
                    <input
                        v-model.number="form.laborRateCoefficient"
                        type="number"
                        min="0.001"
                        max="100"
                        step="0.001"
                        :aria-invalid="Boolean(fieldErrors.laborRateCoefficient)"
                        @input="clearFieldError('laborRateCoefficient')"
                    />
                    <small v-if="fieldErrors.laborRateCoefficient" class="field-error">
                        {{ fieldErrors.laborRateCoefficient }}
                    </small>
                </label>
            </div>

            <section
                v-for="group in [
                    { key: 'contacts', field: 'contactPersonIds', title: 'Контактные лица' },
                    { key: 'drivers', field: 'driverPersonIds', title: 'Водители' },
                ]"
                :key="group.key"
                class="catalog-related"
            >
                <div class="section-title person-assignment-head">
                    <h3>{{ group.title }}</h3>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ФИО</th>
                                <th>Телефон</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(personId, index) in form[group.field]"
                                :key="`${group.key}-${index}`"
                            >
                                <td>
                                    <select
                                        v-model="form[group.field][index]"
                                        :aria-invalid="Boolean(fieldErrors[group.field])"
                                        @change="clearFieldError(group.field)"
                                    >
                                        <option value="">Выберите из справочника</option>
                                        <option
                                            v-for="person in peopleByKind[group.key]"
                                            :key="person.id"
                                            :value="person.id"
                                            :disabled="personOptionDisabled(group.field, person.id, index)"
                                        >
                                            {{ person.fullName }}
                                        </option>
                                    </select>
                                </td>
                                <td>
                                    {{ personById(group.key, personId)?.phone || "—" }}
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        class="delete-row"
                                        aria-label="Удалить связанное лицо"
                                        @click="removePersonRow(group.field, index)"
                                    >
                                        ×
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-if="fieldErrors[group.field]" class="field-error">
                    {{ fieldErrors[group.field] }}
                </p>

                <button
                    type="button"
                    class="ghost-btn"
                    @click="addPersonRow(group.field)"
                >
                    Добавить ещё
                </button>
            </section>

            <p v-if="deleteRequested" class="warning-banner">
                Запись будет удалена только после нажатия кнопки «Сохранить».
                Для Заказчика удаление вступит в силу после согласования УК.
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

            <p v-if="error" role="alert" class="error-message">{{ error }}</p>
        </form>
    </section>
</template>
