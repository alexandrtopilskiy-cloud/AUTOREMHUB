<script>
import { marketplaceApi } from "../../api/marketplace.js";
import { catalogFields } from "../../data/permissions.js";

const FILTER_KEYS = Object.keys(catalogFields.vehicleModels);

export default {
    name: "VehicleReferenceCatalog",
    props: {
        reloadToken: { type: Number, default: 0 },
    },
    emits: ["total"],
    data() {
        return {
            records: [],
            filters: Object.fromEntries(FILTER_KEYS.map((key) => [key, ""])),
            page: 1,
            perPage: 100,
            total: 0,
            loading: false,
            error: "",
            filterTimer: null,
            requestSequence: 0,
        };
    },
    computed: {
        fields() {
            return catalogFields.vehicleModels;
        },
        lastPage() {
            return Math.max(1, Math.ceil(this.total / this.perPage));
        },
        rangeStart() {
            return this.total === 0 ? 0 : (this.page - 1) * this.perPage + 1;
        },
        rangeEnd() {
            return Math.min(this.total, this.page * this.perPage);
        },
    },
    watch: {
        reloadToken() {
            this.page = 1;
            this.load();
        },
        filters: {
            deep: true,
            handler() {
                this.page = 1;
                window.clearTimeout(this.filterTimer);
                this.filterTimer = window.setTimeout(() => this.load(), 300);
            },
        },
    },
    mounted() {
        this.load();
    },
    beforeUnmount() {
        window.clearTimeout(this.filterTimer);
    },
    methods: {
        async load() {
            const sequence = ++this.requestSequence;
            this.loading = true;
            this.error = "";

            try {
                const result = await marketplaceApi.vehicleReferenceCatalog(
                    this.filters,
                    this.page,
                    this.perPage,
                );

                if (sequence !== this.requestSequence) return;

                this.records = result.records || [];
                this.total = Number(result.total || 0);
                this.page = Number(result.page || this.page);
                this.perPage = Number(result.perPage || this.perPage);
                this.$emit("total", this.total);
            } catch (error) {
                if (sequence === this.requestSequence) {
                    this.error = error.message;
                }
            } finally {
                if (sequence === this.requestSequence) {
                    this.loading = false;
                }
            }
        },
        value(record, key) {
            if (key === "transmission") {
                return record.sourceTransmission || record.transmission || "—";
            }

            if (key === "driveType") {
                return record.sourceDriveType || record.driveType || "—";
            }

            if (key === "engineType") {
                return record.sourceEngineType || record.engineType || "—";
            }

            return record[key] ?? "—";
        },
        async previous() {
            if (this.page <= 1 || this.loading) return;
            this.page--;
            await this.load();
        },
        async next() {
            if (this.page >= this.lastPage || this.loading) return;
            this.page++;
            await this.load();
        },
    },
};
</script>

<template>
    <section class="vehicle-reference-catalog" aria-label="Внутренний справочник ТС">
        <p v-if="error" class="form-error" role="alert">{{ error }}</p>

        <div class="registry-wrap vehicle-reference-wrap">
            <table class="registry vehicle-reference-table">
                <thead>
                    <tr>
                        <th v-for="(label, key) in fields" :key="key">
                            {{ label }}
                        </th>
                    </tr>
                    <tr class="column-filter-row">
                        <th v-for="(label, key) in fields" :key="key">
                            <input
                                v-model="filters[key]"
                                :aria-label="`Фильтр: ${label}`"
                                placeholder="Фильтр"
                            />
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="record in records" :key="record.id">
                        <td v-for="(label, key) in fields" :key="key">
                            <span class="cell-ellipsis" :title="String(value(record, key))">
                                {{ value(record, key) }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="loading" class="catalog-loading">Загружаем справочник…</div>
        <div v-else-if="!records.length" class="empty-state compact-empty">
            <h3>Записи не найдены</h3>
            <p>Измените значения фильтров.</p>
        </div>

        <footer class="catalog-pagination">
            <span>Показано {{ rangeStart }}–{{ rangeEnd }} из {{ total }}</span>
            <div>
                <button class="ghost-btn" :disabled="page <= 1 || loading" @click="previous">
                    ← Назад
                </button>
                <span>Страница {{ page }} из {{ lastPage }}</span>
                <button
                    class="ghost-btn"
                    :disabled="page >= lastPage || loading"
                    @click="next"
                >
                    Далее →
                </button>
            </div>
        </footer>
    </section>
</template>
