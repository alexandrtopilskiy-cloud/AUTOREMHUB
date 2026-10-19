<script>
import { matchesReference } from "../utils/referenceSearch.js";
const SEARCH_DELAY_MS = 220;

function normalize(value) {
    return String(value || "").trim().toLocaleLowerCase("ru-RU");
}

export default {
    name: "ReferencePicker",
    props: {
        teleportTo: { type: [String, Object], default: "body" },
        modelValue: {
            type: String,
            default: "",
        },
        label: {
            type: String,
            default: "",
        },
        options: {
            type: Array,
            default: () => [],
        },
        loadOptions: {
            type: Function,
            default: null,
        },
        disabled: {
            type: Boolean,
            default: false,
        },
        placeholder: {
            type: String,
            default: "Начните вводить",
        },
        maxResults: { type: Number, default: 50 },
        showAllOnFocusLimit: { type: Number, default: 0 },
        searchMode: {
            type: String,
            default: "fragments",
            validator: (value) => ["prefix", "fragments"].includes(value),
        },
        openOnInputOnly: {
            type: Boolean,
            default: false,
        },
        createLabel: {
            type: String,
            default: "",
        },
    },
    emits: ["update:modelValue", "select", "create", "query"],
    data() {
        return {
            query: this.label,
            browseAll: false,
            rows: [...this.options],
            open: false,
            loading: false,
            error: "",
            timer: null,
            sequence: 0,
            floatingStyle: {},
        };
    },
    computed: {
        filtered() {
            const query = normalize(this.query);

            return this.rows
                .filter((item) => item.active !== false)
                .filter((item) => this.loadOptions
                    || this.browseAll
                    || !query
                    || matchesReference(item.name, query)
                ,
                )
                .slice(0, this.maxResults);
        },
        exactMatch() {
            const query = normalize(this.query);

            return (
                query !== ""
                && this.rows.some((item) => normalize(item.name) === query));
        },
        canCreate() {
            return (
                this.createLabel && normalize(this.query) && !this.exactMatch);
        },
    },
    watch: {
        label(value) {
            if (!this.open) this.query = value || "";
        },
        options: {
            deep: true,
            handler(value) {
                if (!this.loadOptions) this.rows = [...value];
            },
        },
    },
    mounted() {
        window.addEventListener("resize", this.positionOptions);
        document.addEventListener("scroll", this.positionOptions, true);
    },
    beforeUnmount() {
        window.clearTimeout(this.timer);
        window.removeEventListener("resize", this.positionOptions);
        document.removeEventListener("scroll", this.positionOptions, true);
        this.sequence += 1;
    },
    methods: {
        async show() {
            if (this.disabled) return;
            this.browseAll = this.showAllOnFocusLimit > 0
                && this.rows.filter((item) => item.active !== false).length <= this.showAllOnFocusLimit;
            if (this.openOnInputOnly && !this.browseAll) return;
            this.open = true;
            await this.$nextTick();
            this.positionOptions();
            await this.load();
        },
        input() {
            this.browseAll = false;
            this.$emit("update:modelValue", "");
            this.$emit("query", this.query);
            this.open = !this.openOnInputOnly || this.query.trim() !== "";
            if (this.loadOptions) this.rows = [];
            this.$nextTick(this.positionOptions);
            window.clearTimeout(this.timer);
            this.timer = window.setTimeout(() => this.load(), SEARCH_DELAY_MS);
        },
        async load() {
            if (!this.loadOptions) {
                this.rows = [...this.options];
                return;
            }

            const sequence = ++this.sequence;
            this.loading = true;
            this.error = "";

            try {
                const rows = await this.loadOptions(this.query);
                if (sequence === this.sequence) this.rows = rows || [];
            } catch (error) {
                if (sequence === this.sequence) {
                    this.rows = [];
                    this.error = error.message;
                }
            } finally {
                if (sequence === this.sequence) this.loading = false;
                this.$nextTick(this.positionOptions);
            }
        },
        positionOptions() {
            if (!this.open || !this.$refs.input) return;

            const rect = this.$refs.input.getBoundingClientRect();
            const viewportGap = 8;
            const desiredHeight = 230;
            const availableBelow = window.innerHeight - rect.bottom - viewportGap;
            const availableAbove = rect.top - viewportGap;
            const placeAbove = availableBelow < 140 && availableAbove > availableBelow;
            const availableHeight = placeAbove ? availableAbove : availableBelow;
            const maxHeight = Math.max(90, Math.min(desiredHeight, availableHeight),
            );
            const desiredWidth = Math.max(rect.width, 260);
            const width = Math.min(desiredWidth, window.innerWidth - viewportGap * 2,
            );
            const left = Math.max(
                viewportGap,
                Math.min(rect.left, window.innerWidth - width - viewportGap),
            );

            this.floatingStyle = {
                top: placeAbove
                    ? `${Math.max(viewportGap, rect.top - maxHeight - 3)}px`
                    : `${rect.bottom + 3}px`,
                left: `${left}px`,
                minWidth: `${width}px`,
                maxWidth: `${Math.min(420, window.innerWidth - viewportGap * 2)}px`,
                maxHeight: `${maxHeight}px`,
            };
        },
        choose(item) {
            this.browseAll = false;
            this.query = item.name;
            this.open = false;
            this.$emit("update:modelValue", item.id);
            this.$emit("select", item);
        },
        create() {
            const query = this.query.trim();
            this.open = false;
            this.$emit("create", query);
        },
        blur() {
            window.setTimeout(() => {
                this.open = false;
                this.query = this.modelValue ? this.label : "";
            }, 160);
        },
    },
};
</script>

<template>
    <div class="reference-picker">
        <input
            ref="input"
            v-model="query"
            autocomplete="off"
            :title="query || label"
            :disabled="disabled"
            :placeholder="placeholder"
            @focus="show"
            @input="input"
            @blur="blur"
        />
    </div>
    <Teleport :to="teleportTo">
        <div
            v-if="open && !disabled"
            class="reference-picker-options reference-picker-options-floating"
            :style="floatingStyle"
        >
            <p v-if="loading" class="muted">Ищем…</p>
            <p v-else-if="error" class="error-message">{{ error }}</p>
            <button
                v-for="item in filtered"
                :key="item.id"
                :title="item.name"
                type="button"
                @mousedown.prevent="choose(item)"
            >
                {{ item.name }}
            </button>
            <p v-if="!loading && query && !filtered.length" class="muted">
                Совпадений по введённым фрагментам нет.
            </p>
            <button
                v-if="canCreate"
                type="button"
                class="reference-picker-create"
                @mousedown.prevent="create"
            >
                ＋ {{ createLabel }}
            </button>
        </div>
    </Teleport>
</template>
