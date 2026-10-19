<script>
import VehicleModelCreateDialog from "./VehicleModelCreateDialog.vue";

const SEARCH_DELAY_MS = 220;

function normalized(value) {
    return String(value || "").trim().toLocaleLowerCase("ru-RU");
}

export default {
    name: "VehicleReferencePicker",
    components: { VehicleModelCreateDialog },
    props: {
        make: {
            type: String,
            default: "",
        },
        model: {
            type: String,
            default: "",
        },
        modelId: {
            type: String,
            default: "",
        },
        disabled: {
            type: Boolean,
            default: false,
        },
        loadMakes: {
            type: Function,
            required: true,
        },
        loadModels: {
            type: Function,
            required: true,
        },
        createModel: {
            type: Function,
            required: true,
        },
    },
    emits: ["change"],
    data() {
        return {
            makeSearch: this.make,
            modelSearch: this.model,
            selectedMake: null,
            selectedModel: null,
            makes: [],
            models: [],
            makeOpen: false,
            modelOpen: false,
            createOpen: false,
            loadingMakes: false,
            loadingModels: false,
            error: "",
            makeTimer: null,
            modelTimer: null,
            makeSequence: 0,
            modelSequence: 0,
        };
    },
    computed: {
        modelDisabled() {
            return this.disabled || !this.selectedMake;
        },
    },
    async mounted() {
        await this.findInitialSelection();
    },
    beforeUnmount() {
        window.clearTimeout(this.makeTimer);
        window.clearTimeout(this.modelTimer);
    },
    methods: {
        async findInitialSelection() {
            if (!this.make) {
                return;
            }

            await this.fetchMakes(this.make);
            const make = this.makes.find(
                (item) => normalized(item.name) === normalized(this.make),
            );

            if (!make) {
                return;
            }

            this.selectedMake = make;
            await this.fetchModels(this.model);
            this.selectedModel = this.models.find(
                (item) => item.id === this.modelId,
            ) || this.models.find(
                (item) => normalized(item.model) === normalized(this.model),
            ) || null;
        },
        scheduleMakes() {
            this.selectedMake = null;
            this.selectedModel = null;
            this.modelSearch = "";
            this.models = [];
            this.emitSelection();
            this.makeOpen = true;
            window.clearTimeout(this.makeTimer);
            this.makeTimer = window.setTimeout(
                () => this.fetchMakes(this.makeSearch),
                SEARCH_DELAY_MS,
            );
        },
        scheduleModels() {
            this.selectedModel = null;
            this.emitSelection();
            this.modelOpen = true;
            window.clearTimeout(this.modelTimer);
            this.modelTimer = window.setTimeout(
                () => this.fetchModels(this.modelSearch),
                SEARCH_DELAY_MS,
            );
        },
        async fetchMakes(search = "") {
            const sequence = ++this.makeSequence;
            this.loadingMakes = true;
            this.error = "";

            try {
                const rows = await this.loadMakes(search);
                if (sequence === this.makeSequence) this.makes = rows;
            } catch (error) {
                if (sequence === this.makeSequence) this.error = error.message;
            } finally {
                if (sequence === this.makeSequence) this.loadingMakes = false;
            }
        },
        async fetchModels(search = "") {
            if (!this.selectedMake) {
                this.models = [];
                return;
            }

            const sequence = ++this.modelSequence;
            this.loadingModels = true;
            this.error = "";

            try {
                const rows = await this.loadModels(this.selectedMake.id, search);
                if (sequence === this.modelSequence) this.models = rows;
            } catch (error) {
                if (sequence === this.modelSequence) this.error = error.message;
            } finally {
                if (sequence === this.modelSequence) this.loadingModels = false;
            }
        },
        async openMakes() {
            if (this.disabled) return;
            this.makeOpen = true;
            await this.fetchMakes(this.makeSearch);
        },
        async openModels() {
            if (this.modelDisabled) return;
            this.modelOpen = true;
            await this.fetchModels(this.modelSearch);
        },
        chooseMake(make) {
            this.selectedMake = make;
            this.makeSearch = make.name;
            this.selectedModel = null;
            this.modelSearch = "";
            this.models = [];
            this.makeOpen = false;
            this.emitSelection();
        },
        chooseModel(model) {
            this.selectedModel = model;
            this.modelSearch = model.model;
            this.modelOpen = false;
            this.emitSelection();
        },
        emitSelection() {
            this.$emit("change", {
                make: this.selectedMake?.name || this.makeSearch.trim(),
                model: this.selectedModel?.model || this.modelSearch.trim(),
                vehicleModelId: this.selectedModel?.id || "",
            });
        },
        blur(kind) {
            window.setTimeout(() => {
                if (kind === "make") this.makeOpen = false;
                if (kind === "model") this.modelOpen = false;
            }, 160);
        },
        modelDetails(model) {
            const years = [model.modelYearFrom, model.modelYearTo]
                .filter(Boolean)
                .join("–");
            return [years, model.engineIndex, model.horsepower && `${model.horsepower} л.с.`]
                .filter(Boolean)
                .join(" · ");
        },
        openCreate() {
            this.makeOpen = false;
            this.modelOpen = false;
            this.createOpen = true;
        },
        async created(model) {
            this.createOpen = false;
            this.selectedMake = { id: model.makeId, name: model.make };
            this.selectedModel = model;
            this.makeSearch = model.make;
            this.modelSearch = model.model;
            this.emitSelection();
        },
    },
};
</script>

<template>
    <div class="vehicle-reference-grid">
        <label class="reference-combobox">
            Марка
            <input
                v-model="makeSearch"
                autocomplete="off"
                required
                :disabled="disabled"
                placeholder="Начните вводить марку"
                @focus="openMakes"
                @input="scheduleMakes"
                @blur="blur('make')"
            />
            <div v-if="makeOpen" class="reference-options">
                <p v-if="loadingMakes" class="muted">Ищем марки…</p>
                <button
                    v-for="item in makes"
                    :key="item.id"
                    type="button"
                    @mousedown.prevent="chooseMake(item)"
                >
                    {{ item.name }}
                </button>
                <button
                    type="button"
                    class="reference-create"
                    @mousedown.prevent="openCreate"
                >
                    ＋ Создать марку и модель
                </button>
            </div>
        </label>

        <label class="reference-combobox">
            Модель
            <input
                v-model="modelSearch"
                autocomplete="off"
                required
                :disabled="modelDisabled"
                :placeholder="selectedMake ? 'Начните вводить модель' : 'Сначала выберите марку'"
                @focus="openModels"
                @input="scheduleModels"
                @blur="blur('model')"
            />
            <div v-if="modelOpen" class="reference-options">
                <p v-if="loadingModels" class="muted">Ищем модели…</p>
                <button
                    v-for="item in models"
                    :key="item.id"
                    type="button"
                    @mousedown.prevent="chooseModel(item)"
                >
                    <strong>{{ item.model }}</strong>
                    <small v-if="modelDetails(item)">{{ modelDetails(item) }}</small>
                </button>
                <button
                    type="button"
                    class="reference-create"
                    @mousedown.prevent="openCreate"
                >
                    ＋ Создать марку и модель
                </button>
            </div>
        </label>

        <p v-if="error" class="error-message full">{{ error }}</p>

        <VehicleModelCreateDialog
            v-if="createOpen"
            :initial-make="makeSearch"
            :initial-model="modelSearch"
            :create-model="createModel"
            @created="created"
            @close="createOpen = false"
        />
    </div>
</template>
