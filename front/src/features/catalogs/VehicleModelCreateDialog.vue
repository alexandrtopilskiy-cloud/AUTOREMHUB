<script>
import ReferencePicker from "../../components/ReferencePicker.vue";
import { marketplaceApi } from "../../api/marketplace.js";
import { matchesReference } from "../../utils/referenceSearch.js";
const FREE_FIELDS = [
    { key: "make", label: "Марка *" },
    { key: "model", label: "Модель *" },
    { key: "bodyCode", label: "Поколение / номер кузова" },
    { key: "engineIndex", label: "Индекс двигателя" },
    { key: "horsepower", label: "Мощность, л.с." },
    { key: "engineVolumeLiters", label: "Объём двигателя, л" },
];
const STEP = {
    model: "model",
    bodyCode: "generation",
    engineIndex: "engineIndex",
    horsepower: "horsepower",
    engineVolumeLiters: "engineVolumeLiters",
};
export default {
    name: "VehicleModelCreateDialog",
    components: { ReferencePicker },
    props: {
        initialMake: { type: String, default: "" },
        initialModel: { type: String, default: "" },
        createModel: { type: Function, required: true },
    },
    emits: ["created", "close"],
    data() {
        return {
            busy: false,
            error: "",
            makeId: "",
            generation: null,
            dialogTarget: null,
            freeFields: FREE_FIELDS,
            form: {
                make: this.initialMake,
                model: this.initialModel,
                modelYearFrom: null,
                modelYearTo: null,
                bodyCode: "",
                engineIndex: "",
                horsepower: null,
                engineVolumeLiters: null,
                engineType: null,
                transmission: null,
                driveType: null,
            },
        };
    },
    mounted() {
        this.dialogTarget = this.$refs.dialog;
        this.$refs.dialog?.showModal();
    },
    methods: {
        close() {
            if (!this.busy) this.$emit("close");
        },
        filters(key) {
            const result = {};
            const target = STEP[key];
            const sequence = [
                "model",
                "generation",
                "engineType",
                "engineIndex",
                "horsepower",
                "engineVolumeLiters",
                "transmission",
                "driveType",
            ];
            for (const step of sequence.slice(0, sequence.indexOf(target))) {
                if (step === "generation") {
                    if (
                        this.generation &&
                        this.form.bodyCode === this.generation.bodyCode &&
                        this.form.modelYearFrom === this.generation.yearFrom &&
                        this.form.modelYearTo === this.generation.yearTo
                    )
                        result.generation = this.generation.value;
                } else if (this.form[step] !== null && this.form[step] !== "")
                    result[step] = String(this.form[step]);
            }
            return result;
        },
        async options(key, query) {
            if (key === "make")
                return (await marketplaceApi.vehicleMakes(query)).map(
                    (row) => ({
                        ...row,
                        id: row.name,
                        referenceId: row.id,
                    }),
                );
            if (!this.form.make) return [];
            if (!this.makeId) {
                const makes = await marketplaceApi.vehicleMakes(this.form.make);
                this.makeId =
                    makes.find(
                        (row) =>
                            row.name.toLocaleLowerCase() ===
                            this.form.make.toLocaleLowerCase(),
                    )?.id || "";
            }
            if (!this.makeId) return [];
            const response = await marketplaceApi.vehicleSelection(
                this.makeId,
                STEP[key],
                this.filters(key),
            );
            return (response.options || [])
                .filter((row) => row.value !== "__unknown__")
                .map((row) => ({
                    id: String(row.value),
                    name: row.label || String(row.value),
                    value: row.value,
                }))
                .filter((row) => matchesReference(row.name, query));
        },
        query(key, value) {
            this.form[key] = value;
            if (key === "make") this.makeId = "";
            if (key === "bodyCode") this.generation = null;
        },
        select(key, row) {
            if (key === "make") {
                this.form.make = row.name;
                this.makeId = row.referenceId || "";
            } else if (key === "bodyCode") {
                try {
                    const [bodyCode, yearFrom, yearTo] = JSON.parse(row.value);
                    this.generation = {
                        value: row.value,
                        bodyCode,
                        yearFrom,
                        yearTo,
                    };
                    Object.assign(this.form, {
                        bodyCode: bodyCode || "",
                        modelYearFrom: yearFrom,
                        modelYearTo: yearTo,
                    });
                } catch {
                    this.form.bodyCode = row.name;
                }
            } else
                this.form[key] = ["horsepower", "engineVolumeLiters"].includes(
                    key,
                )
                    ? Number(row.value)
                    : row.name;
        },
        async submit() {
            const payload = {
                ...this.form,
                make: this.form.make.trim(),
                model: this.form.model.trim(),
            };
            if (!payload.make || !payload.model) {
                this.error = "Заполните марку и модель";
                return;
            }
            for (const key of [
                "modelYearFrom",
                "modelYearTo",
                "horsepower",
                "engineVolumeLiters",
            ]) {
                payload[key] =
                    payload[key] === "" || payload[key] === null
                        ? null
                        : Number(String(payload[key]).replace(",", "."));
                if (payload[key] !== null && !Number.isFinite(payload[key])) {
                    this.error = "Числовые поля должны содержать число";
                    return;
                }
            }
            if (
                [payload.modelYearFrom, payload.modelYearTo].some(
                    (year) =>
                        year !== null &&
                        (!Number.isInteger(year) || year < 1900 || year > 2100),
                ) ||
                (payload.modelYearFrom &&
                    payload.modelYearTo &&
                    payload.modelYearTo < payload.modelYearFrom)
            ) {
                this.error = "Укажите корректные годы YYYY от 1900 до 2100";
                return;
            }
            if (
                payload.horsepower !== null &&
                (payload.horsepower < 0.1 ||
                    payload.horsepower > 3000 ||
                    Math.abs(
                        payload.horsepower * 10 -
                            Math.round(payload.horsepower * 10),
                    ) > 1e-9)
            ) {
                this.error =
                    "Мощность должна быть от 0,1 до 3000,0 с точностью до десятых";
                return;
            }
            if (payload.engineType === "электро")
                payload.engineVolumeLiters = null;
            this.busy = true;
            this.error = "";
            try {
                this.$emit("created", await this.createModel(payload));
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
    <dialog
        ref="dialog"
        class="action-dialog vehicle-model-dialog"
        @cancel.prevent="close"
    >
        <div class="vehicle-model-dialog-content">
            <span class="eyebrow">Справочник ТС (марки, модели)</span>
            <h2>Добавить вариант автомобиля</h2>
            <div class="form-grid">
                <label v-for="field in freeFields.slice(0, 3)" :key="field.key"
                    ><span>{{ field.label }}</span>
                    <ReferencePicker
                        :model-value="String(form[field.key] ?? '')"
                        :label="String(form[field.key] ?? '')"
                        :load-options="(query) => options(field.key, query)"
                        :teleport-to="dialogTarget || 'body'"
                        :disabled="
                            busy ||
                            (field.key === 'engineVolumeLiters' &&
                                form.engineType === 'электро')
                        "
                        :show-all-on-focus-limit="15"
                        placeholder="Выберите подсказку или введите своё значение"
                        @query="query(field.key, $event)"
                        @select="select(field.key, $event)"
                    />
                </label>
                <label
                    ><span>Год выпуска с</span
                    ><input
                        v-model.number="form.modelYearFrom"
                        type="number"
                        min="1900"
                        max="2100"
                        :disabled="busy"
                /></label>
                <label
                    ><span>Год выпуска до</span
                    ><input
                        v-model.number="form.modelYearTo"
                        type="number"
                        min="1900"
                        max="2100"
                        :disabled="busy"
                /></label>
                <label
                    ><span>Тип двигателя</span
                    ><select v-model="form.engineType" :disabled="busy">
                        <option :value="null">Не указано</option>
                        <option
                            v-for="value in [
                                'Бензин',
                                'дизель',
                                'гибрид',
                                'электро',
                                'газ',
                                'газ/бензин',
                                'водородный',
                            ]"
                            :key="value"
                        >
                            {{ value }}
                        </option>
                    </select></label
                >
                <label v-for="field in freeFields.slice(3)" :key="field.key"
                    ><span>{{ field.label }}</span>
                    <ReferencePicker
                        :model-value="String(form[field.key] ?? '')"
                        :label="String(form[field.key] ?? '')"
                        :load-options="(query) => options(field.key, query)"
                        :teleport-to="dialogTarget || 'body'"
                        :disabled="
                            busy ||
                            (field.key === 'engineVolumeLiters' &&
                                form.engineType === 'электро')
                        "
                        :show-all-on-focus-limit="15"
                        placeholder="Выберите подсказку или введите своё значение"
                        @query="query(field.key, $event)"
                        @select="select(field.key, $event)"
                    />
                </label>
                <label
                    ><span>Вид трансмиссии</span
                    ><select v-model="form.transmission" :disabled="busy">
                        <option :value="null">Не указано</option>
                        <option
                            v-for="value in [
                                'МКПП',
                                'РКПП',
                                'АКПП',
                                'CVT',
                                'электро',
                            ]"
                            :key="value"
                        >
                            {{ value }}
                        </option>
                    </select></label
                >
                <label
                    ><span>Тип привода</span
                    ><select v-model="form.driveType" :disabled="busy">
                        <option :value="null">Не указано</option>
                        <option
                            v-for="value in ['передний', 'задний', 'полный']"
                            :key="value"
                        >
                            {{ value }}
                        </option>
                    </select></label
                >
            </div>
            <p v-if="error" class="error-banner" role="alert">{{ error }}</p>
            <div class="record-editor-footer action-buttons">
                <button class="ghost-btn" :disabled="busy" @click="close">
                    Отмена</button
                ><button class="primary-btn" :disabled="busy" @click="submit">
                    Добавить
                </button>
            </div>
        </div>
    </dialog>
</template>
