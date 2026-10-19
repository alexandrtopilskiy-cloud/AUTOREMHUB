<script>
import { marketplaceApi } from "../../api/marketplace.js";
import ReferencePicker from "../../components/ReferencePicker.vue";
import VehicleModelCreateDialog from "./VehicleModelCreateDialog.vue";

const STEPS = [
    ["model", "Модель"],
    ["generation", "Поколение · годы выпуска с–до · номер кузова"],
    ["engineType", "Тип двигателя"],
    ["engineIndex", "Индекс двигателя"],
    ["horsepower", "Мощность двигателя, л.с."],
    ["engineVolumeLiters", "Объём двигателя, л"],
    ["transmission", "Вид трансмиссии"],
    ["driveType", "Тип привода"],
];

export default {
    name: "SequentialVehiclePicker",
    components: { VehicleModelCreateDialog, ReferencePicker },
    props: {
        reference: { type: Object, default: null },
        disabled: { type: Boolean, default: false },
    },
    emits: ["change"],
    data() {
        return {
            steps: STEPS,
            makeSearch: "",
            makes: [],
            make: null,
            filters: {},
            options: {},
            busy: false,
            error: "",
            createOpen: false,
            sequence: 0,
            search: {},
        };
    },
    watch: {
        reference(record) {
            if (record)
                this.restore(record).catch((error) => {
                    this.error = error.message;
                });
            else {
                this.make = null;
                this.filters = {};
                this.options = {};
                this.search = {};
            }
        },
    },
    async mounted() {
        try {
            this.makes = await marketplaceApi.vehicleMakes("");
            if (this.reference) await this.restore(this.reference);
        } catch (error) {
            this.error = error.message;
        }
    },
    methods: {
        isClosedList(key) {
            return ["engineType", "driveType", "transmission"].includes(key);
        },
        stepOptions(key) {
            return (this.options[key] || []).map((item) => ({
                id: item.value,
                name: item.label,
            }));
        },
        stepLabel(key) {
            return (this.options[key] || []).find((item) => item.value === this.filters[key])?.label || "";
        },
        clearStep(key, value) {
            if (value) return;
            const index = this.steps.findIndex(([name]) => name === key);
            for (const [name] of this.steps.slice(index)) {
                delete this.filters[name];
                if (name !== key) delete this.options[name];
                delete this.search[name];
            }
            this.$emit("change", {
                make: this.make?.name || "",
                model: this.filters.model || "",
                vehicleModelId: "",
            });
        },
        async selectStep(key, item) {
            this.filters[key] = item.id;
            await this.choose(key);
        },
        async selectMake(item) {
            this.make = item;
            await this.chooseMake();
        },
        clearMake(value) {
            if (value) return;
            this.make = null;
            this.chooseMake();
        },
        async restore(record) {
            this.make = { id: record.makeId, name: record.make };
            this.filters = {};
            for (const [key] of this.steps) {
                await this.load(key);
                this.filters[key] =
                    key === "generation"
                        ? JSON.stringify([
                              record.bodyCode,
                              record.modelYearFrom,
                              record.modelYearTo,
                          ])
                        : key === "engineType" && record.sourceEngineType
                          ? record.sourceEngineType
                          : record[key] == null || record[key] === ""
                            ? "__unknown__"
                            : String(record[key]);
            }
            await this.resolve();
        },
        async chooseMake() {
            this.filters = {};
            this.options = {};
            this.search = {};
            this.$emit("change", { make: this.make?.name || "", model: "", vehicleModelId: "" });
            if (this.make) await this.load("model");
        },
        async load(key) {
            const response = await marketplaceApi.vehicleSelection(this.make.id, key, this.filters);
            this.options[key] = response.options;
        },
        async choose(key) {
            const index = this.steps.findIndex(([name]) => name === key);
            for (const [name] of this.steps.slice(index + 1)) {
                delete this.filters[name];
                delete this.options[name];
                delete this.search[name];
            }
            this.$emit("change", {
                make: this.make.name,
                model: this.filters.model || "",
                vehicleModelId: "",
            });
            this.busy = true;
            this.error = "";
            const sequence = ++this.sequence;
            try {
                if (this.steps[index + 1]) await this.load(this.steps[index + 1][0]);
                else await this.resolve();
            } catch (error) {
                if (sequence === this.sequence) this.error = error.message;
            } finally {
                if (sequence === this.sequence) this.busy = false;
            }
        },
        async resolve() {
            const response = await marketplaceApi.vehicleSelection(
                this.make.id,
                "driveType",
                this.filters,
            );
            if (!response.variant) {
                this.error = "Сочетание неоднозначно: требуется уточнение записи каталога в УК";
                return;
            }
            const record = response.variant;
            this.$emit("change", {
                make: record.make,
                model: record.model,
                vehicleModelId: record.id,
                technicalReference: record,
            });
        },
        createModel(record) {
            return marketplaceApi.createVehicleModel(record);
        },
        async created(record) {
            this.createOpen = false;
            await this.restore(record);
        },
    },
};
</script>

<template>
    <div class="form-grid full sequential-vehicle-picker">
        <label>Марка
            <ReferencePicker
                :model-value="make?.id || ''"
                :label="make?.name || ''"
                :options="makes"
                :disabled="disabled || busy"
                :open-on-input-only="true"
                search-mode="fragments"
                placeholder="Начните вводить марку"
                @update:model-value="clearMake"
                @query="makeSearch = $event"
                @select="selectMake"
            />
        </label>
        <label v-for="([key, label], index) in steps" :key="key">{{ label }}
            <select
                v-if="isClosedList(key)"
                :value="filters[key] || ''"
                :disabled="disabled || busy || !options[key]"
                @change="selectStep(key, { id: $event.target.value })"
            >
                <option disabled value="">{{ options[key] ? 'Выберите значение' : 'Сначала выберите предыдущий признак' }}</option>
                <option v-for="item in stepOptions(key)" :key="item.id" :value="item.id">{{ item.name }}</option>
            </select>
            <ReferencePicker
                v-else
                :show-all-on-focus-limit="15"
                :model-value="filters[key] || ''"
                :label="stepLabel(key)"
                :options="stepOptions(key)"
                :disabled="disabled || busy || !options[key]"
                :open-on-input-only="true"
                search-mode="fragments"
                :placeholder="options[key] ? 'Начните вводить значение' : 'Сначала выберите предыдущий признак'"
                @update:model-value="clearStep(key, $event)"
                @select="selectStep(key, $event)"
            />
        </label>
        <p v-if="error" class="error-message full">{{ error }}</p>
        <button
            v-if="!disabled"
            type="button"
            class="ghost-btn"
            :disabled="busy"
            @click="createOpen = true"
        >
            Создать отсутствующий вариант ТС
        </button>
        <VehicleModelCreateDialog
            v-if="createOpen"
            :initial-make="make?.name || makeSearch"
            :initial-model="filters.model || ''"
            :create-model="createModel"
            @created="created"
            @close="createOpen = false"
        />
    </div>
</template>
