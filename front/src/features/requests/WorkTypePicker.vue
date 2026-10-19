<script>
import { matchesReference } from "../../utils/referenceSearch.js";
export default {
    name: "WorkTypePicker",
    props: {
        modelValue: {
            type: String,
            default: "",
        },
        name: {
            type: String,
            default: "",
        },
        options: {
            type: Array,
            default: () => [],
        },
        disabled: {
            type: Boolean,
            default: false,
        },
        placeholder: {
            type: String,
            default: "Начните вводить работу",
        },
        createLabel: {
            type: String,
            default: "Добавить работу",
        },
    },
    emits: ["update:modelValue", "select", "create", "edit"],
    data() {
        return {
            query:
                this.options.find((item) => item.id === this.modelValue)?.name
                || this.name
                || "",
            edited: false,
            browseAll: false,
            dropdownStyle: {},
        };
    },
    computed: {
        results() {
            const tokens = this.normalize(this.query).split(" ").filter(Boolean);

            if (!this.edited || (tokens.length === 0 && !this.browseAll)) {
                return [];
            }

            return this.options
                .filter((item) => item.active !== false)
                .filter((item) => {
                    const name = this.normalize([
                        item.name,
                        item.article,
                        item.manufacturer,
                    ].filter(Boolean).join(" "));

                    return this.browseAll || matchesReference(name, this.query);
                })
                .slice(0, 15);
        },
        showResults() {
            return !this.disabled
                && this.edited
                && (this.browseAll || this.normalize(this.query) !== "");
        },
        exactMatch() {
            const query = this.normalize(this.query);

            return query !== ""
                && this.options.some(
                    (item) => this.normalize(item.name) === query,
                );
        },
        canCreate() {
            return Boolean(
                this.createLabel
                && this.normalize(this.query)
                && !this.exactMatch,
            );
        },
    },
    watch: {
        showResults(visible) {
            if (visible) {
                this.$nextTick(this.updateDropdownPosition);
            }
        },
        modelValue(value) {
            if (value) {
                this.query = this.options.find((item) => item.id === value)?.name
                    || this.name
                    || "";
                this.edited = false;
            } else if (!this.edited) {
                this.query = this.name || "";
            }
        },
        name(value) {
            if (!this.edited && !this.modelValue) {
                this.query = value || "";
            }
        },
    },
    mounted() {
        window.addEventListener("resize", this.handleViewportChange);
        window.addEventListener("scroll", this.handleViewportChange, true);
    },
    beforeUnmount() {
        window.removeEventListener("resize", this.handleViewportChange);
        window.removeEventListener("scroll", this.handleViewportChange, true);
    },
    methods: {
        normalize(value) {
            return String(value || "")
                .trim()
                .toLocaleLowerCase("ru-RU")
                .replaceAll("ё", "е")
                .replace(/\s+/g, " ");
        },
        edit() {
            this.browseAll = false;
            this.edited = true;

            if (this.modelValue) {
                this.$emit("update:modelValue", "");
            }

            this.$emit("edit", this.query);
            this.$nextTick(this.updateDropdownPosition);
        },
        handleViewportChange() {
            if (this.showResults) {
                this.updateDropdownPosition();
            }
        },
        updateDropdownPosition() {
            const input = this.$refs.input;

            if (!input) {
                return;
            }

            const rect = input.getBoundingClientRect();
            const gutter = 8;
            const availableWidth = Math.max(0, window.innerWidth - gutter * 2);
            const width = Math.min(
                Math.max(rect.width, 300),
                Math.min(560, availableWidth),
            );
            const left = Math.min(
                Math.max(gutter, rect.left),
                Math.max(gutter, window.innerWidth - gutter - width),
            );

            this.dropdownStyle = {
                top: `${rect.bottom + 3}px`,
                left: `${left}px`,
                width: `${width}px`,
            };
        },
        selectCurrentText(event) {
            this.browseAll = this.options.filter((item) => item.active !== false).length <= 15;
            if (this.browseAll && !this.disabled) {
                this.edited = true;
                this.$nextTick(this.updateDropdownPosition);
            }
            if (this.modelValue && !this.edited) {
                event.target.select();
            }
        },
        choose(item) {
            this.browseAll = false;
            this.query = item.name;
            this.edited = false;
            this.$emit("update:modelValue", item.id);
            this.$emit("select", item);
        },
        createWork() {
            const name = this.query.trim();

            if (!name) {
                return;
            }

            this.$emit("create", name);
        },
        closeResults() {
            window.setTimeout(() => { this.edited = false; this.browseAll = false; }, 160);
        },
        optionLabel(item) {
            return item.optionLabel || item.name;
        },
    },
};
</script>

<template>
    <div class="work-type-picker">
        <input
            ref="input"
            v-model="query"
            :disabled="disabled"
            :placeholder="placeholder"
            :title="query || name"
            autocomplete="off"
            @focus="selectCurrentText"
            @input="edit"
            @blur="closeResults"
        />

        <Teleport to="body">
            <ul
                v-if="showResults"
                class="search-results work-search-results"
                :style="dropdownStyle"
            >
                <li v-for="item in results" :key="item.id" :title="item.optionLabel || item.name">
                    <button type="button" @click="choose(item)">
                        {{ optionLabel(item) }}
                    </button>
                </li>
                <li v-if="!results.length" class="work-search-empty">
                    Совпадений не найдено.
                </li>
                <li v-if="canCreate" class="work-search-create">
                    <button type="button" @click="createWork">
                        ＋ {{ createLabel }}
                    </button>
                </li>
            </ul>
        </Teleport>
    </div>
</template>
