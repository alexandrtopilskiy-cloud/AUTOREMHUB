<script setup>
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { marketplaceApi } from "../../api/marketplace.js";

const SEARCH_DEBOUNCE_MS = 250;

const props = defineProps({
    disabled: {
        type: Boolean,
        default: false,
    },
    requestId: {
        type: String,
        default: "",
    },
    vehicleId: {
        type: String,
        default: "",
    },
    vehicle: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(["select", "history"]);

const query = ref("");
const results = ref([]);
const error = ref("");
const searching = ref(false);
const choosing = ref(!props.vehicleId);

const selectedLabel = computed(() => {
    if (!props.vehicleId) {
        return "";
    }

    return [
        props.vehicle?.vehicle || "Автомобиль",
        props.vehicle?.vin ? `VIN ${props.vehicle.vin}` : "",
        props.vehicle?.plate || "",
    ]
        .filter(Boolean)
        .join(" · ");
});

let timer = null;
let sequence = 0;

function search() {
    if (!choosing.value || props.disabled) {
        return;
    }

    clearTimeout(timer);

    const version = ++sequence;

    timer = setTimeout(async () => {
        searching.value = true;
        error.value = "";

        try {
            const rows = await marketplaceApi.searchVehicles(
                query.value,
                props.requestId,
            );

            if (version === sequence) {
                results.value = rows;
            }
        } catch (e) {
            if (version === sequence) {
                error.value = e.message;
            }
        } finally {
            if (version === sequence) {
                searching.value = false;
            }
        }
    }, SEARCH_DEBOUNCE_MS);
}

function select(vehicle) {
    results.value = [];
    query.value = "";
    choosing.value = false;
    sequence++;

    emit("select", vehicle);
}

function chooseAnother() {
    choosing.value = true;
    query.value = "";
    results.value = [];
    error.value = "";
    sequence++;
}

function openHistory() {
    emit("history", props.vehicleId);
}

watch(
    () => props.vehicleId,
    (vehicleId) => {
        choosing.value = !vehicleId;
        query.value = "";
        results.value = [];
        error.value = "";
    },
);

onBeforeUnmount(() => {
    clearTimeout(timer);
    sequence++;
});
</script>

<template>
    <div class="vehicle-picker">
        <label>
            <span>Выбор ТС</span>

            <div
                v-if="vehicleId && !choosing"
                class="selected-vehicle-control"
            >
                <input :value="selectedLabel" readonly />
                <button
                    v-if="!disabled"
                    type="button"
                    class="ghost-btn"
                    @click="chooseAnother"
                >
                    Выбрать другое ТС
                </button>
            </div>

            <input
                v-else
                v-model="query"
                :disabled="disabled"
                placeholder="Введите VIN, госномер, марку или модель"
                autocomplete="off"
                @input="search"
                @focus="search"
            />
        </label>

        <small v-if="searching">Ищем в перечне вашей организации…</small>

        <p v-if="error" class="error-message">{{ error }}</p>

        <ul v-if="results.length" class="search-results">
            <li v-for="item in results" :key="item.id">
                <button type="button" @click="select(item)">
                    <strong>{{ item.vehicle }}</strong>
                    <small>
                        VIN {{ item.vin }} · {{ item.plate || "—" }}
                    </small>
                </button>
            </li>
        </ul>

        <p
            v-if="query && !searching && !results.length && !error"
            class="muted"
        >
            Нет подходящих автомобилей.
        </p>

        <button
            v-if="vehicleId"
            type="button"
            class="ghost-btn vehicle-history-button"
            @click="openHistory"
        >
            История ТО и работ
        </button>
    </div>
</template>
