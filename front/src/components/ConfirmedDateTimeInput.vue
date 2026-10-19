<script>
function splitValue(value) {
    if (!value || !String(value).includes("T")) {
        return { date: "", time: "" };
    }

    const [date, rawTime] = String(value).split("T");

    return { date, time: rawTime.slice(0, 5) };
}

function validTime(value) {
    return /^([01]\d|2[0-3]):(?:00|30)$/.test(value);
}

function displayValue(value) {
    const { date, time } = splitValue(value);

    if (!date || !time) {
        return "Выберите дату и время";
    }

    const [year, month, day] = date.split("-").map(Number);
    const formattedDate = new Intl.DateTimeFormat("ru-RU", {
        day: "numeric",
        month: "long",
        year: "numeric",
        timeZone: "UTC",
    }).format(new Date(Date.UTC(year, month - 1, day)));

    return `${formattedDate}, ${time}`;
}

const SERVICE_TIMEZONE = import.meta.env.VITE_SERVICE_TIMEZONE || "Europe/Moscow";
const HALF_HOUR_MS = 30 * 60 * 1000;

function minimumLocalValue(timestamp) {
    const parts = new Intl.DateTimeFormat("en-CA", {
        timeZone: SERVICE_TIMEZONE,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hourCycle: "h23",
    }).formatToParts(new Date(timestamp + HALF_HOUR_MS));
    const value = Object.fromEntries(parts.map((part) => [part.type, part.value]));
    const logicalTime = Date.UTC(
        Number(value.year),
        Number(value.month) - 1,
        Number(value.day),
        Number(value.hour),
        Number(value.minute),
        Number(value.second),
    );
    const rounded = new Date(Math.ceil(logicalTime / HALF_HOUR_MS) * HALF_HOUR_MS);

    return rounded.toISOString().slice(0, 16);
}

// Последний получасовой слот, не позже текущего местного времени браузера.
function latestPastLocalValue(timestamp) {
    const now = new Date(timestamp);
    const slot = now.getMinutes() < 30 ? "00" : "30";
    const pad = (value) => String(value).padStart(2, "0");

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
        + `T${pad(now.getHours())}:${slot}`;
}

export default {
    name: "ConfirmedDateTimeInput",
    props: {
        modelValue: {
            type: String,
            default: "",
        },
        title: {
            type: String,
            required: true,
        },
        disabled: {
            type: Boolean,
            default: false,
        },
        minTime: {
            type: String,
            default: "00:00",
            validator: validTime,
        },
        maxTime: {
            type: String,
            default: "23:30",
            validator: validTime,
        },
        // Фактическая дата события (например, оплаты): допускается прошлое
        // местное время и запрещено будущее вместо правила «сейчас + 30 минут».
        pastOnly: {
            type: Boolean,
            default: false,
        },
    },
    emits: ["update:modelValue", "confirm"],
    data() {
        const pending = splitValue(this.modelValue);

        return {
            pendingDate: pending.date,
            pendingTime: pending.time,
            open: false,
            nowTick: Date.now(),
            clockTimer: null,
        };
    },
    computed: {
        minimumValue() {
            return minimumLocalValue(this.nowTick);
        },
        minimumSelectableValue() {
            const date = this.minimumValue.slice(0, 10);
            const time = this.minimumValue.slice(11, 16);

            if (time < this.minTime) {
                return `${date}T${this.minTime}`;
            }

            if (time > this.maxTime) {
                const nextDate = new Date(`${date}T00:00:00Z`);
                nextDate.setUTCDate(nextDate.getUTCDate() + 1);

                return `${nextDate.toISOString().slice(0, 10)}T${this.minTime}`;
            }

            return this.minimumValue;
        },
        minimumDate() {
            return this.pastOnly ? null : this.minimumSelectableValue.slice(0, 10);
        },
        maximumValue() {
            return this.pastOnly ? latestPastLocalValue(this.nowTick) : "";
        },
        maximumDate() {
            return this.pastOnly ? this.maximumValue.slice(0, 10) : null;
        },
        minimumAllowedTime() {
            if (this.minimumDate !== this.pendingDate) {
                return this.minTime;
            }

            return this.minimumSelectableValue.slice(11, 16) > this.minTime
                ? this.minimumSelectableValue.slice(11, 16)
                : this.minTime;
        },
        timeOptions() {
            const values = Array.from({ length: 48 }, (_, index) => {
                const hour = String(Math.floor(index / 2)).padStart(2, "0");
                const minute = index % 2 === 0 ? "00" : "30";

                return `${hour}:${minute}`;
            }).filter((time) => time >= this.minTime && time <= this.maxTime);

            // Расчётное завершение может содержать минуты вне получасового
            // шага или выходить за окно приёма. Поле только для чтения должно
            // отобразить точное рассчитанное значение.
            if (
                this.disabled
                && this.pendingTime
                && !values.includes(this.pendingTime)
            ) {
                values.push(this.pendingTime);
                values.sort();
            }

            return values;
        },
        pendingValue() {
            return this.pendingDate && this.pendingTime
                ? `${this.pendingDate}T${this.pendingTime}`
                : "";
        },
        formattedValue() {
            return displayValue(this.pendingValue);
        },
        invalidPast() {
            return Boolean(
                !this.disabled
                && !this.pastOnly
                && this.pendingValue
                && this.pendingValue < this.minimumSelectableValue,
            );
        },
        invalidFuture() {
            return Boolean(
                !this.disabled
                && this.pastOnly
                && this.pendingValue
                && this.pendingValue > this.maximumValue,
            );
        },
        invalidRange() {
            return Boolean(
                !this.disabled
                && this.pendingTime
                && (
                    this.pendingTime < this.minTime
                    || this.pendingTime > this.maxTime
                ),
            );
        },
        rangeHint() {
            if (this.minTime === "00:00" && this.maxTime === "23:30") {
                return "24-часовой формат, интервал выбора — 30 минут";
            }

            return `24-часовой формат, шаг 30 минут · ${this.minTime}–${this.maxTime}`;
        },
    },
    watch: {
        modelValue(value) {
            const pending = splitValue(value);
            this.pendingDate = pending.date;
            this.pendingTime = pending.time;
        },
        disabled(value) {
            if (value) {
                this.closePanel();
            }
        },
    },
    methods: {
        timeDisabled(time) {
            if (this.pastOnly) {
                return !this.disabled
                    && this.pendingDate === this.maximumDate
                    && time > this.maximumValue.slice(11, 16);
            }

            return !this.disabled
                && this.pendingDate === this.minimumDate
                && time < this.minimumAllowedTime;
        },
        togglePanel() {
            if (this.disabled) {
                return;
            }

            this.open = !this.open;
        },
        closePanel() {
            this.open = false;
        },
        handleDateChange() {
            if (
                this.pendingTime
                && (
                    this.pendingTime < this.minTime
                    || this.pendingTime > this.maxTime
                    || this.timeDisabled(this.pendingTime)
                )
            ) {
                this.pendingTime = "";
            }

            this.commit();
        },
        handleTimeChange() {
            this.commit();

            if (this.pendingValue && !this.invalidPast && !this.invalidFuture && !this.invalidRange) {
                this.closePanel();
            }
        },
        commit() {
            if (this.invalidPast || this.invalidFuture || this.invalidRange) {
                return;
            }

            if (this.pendingValue === this.modelValue) {
                return;
            }

            this.$emit("update:modelValue", this.pendingValue);
            this.$emit("confirm", this.pendingValue);
        },
        handleDocumentClick(event) {
            if (
                this.open
                && this.$refs.root
                && !this.$refs.root.contains(event.target)
            ) {
                this.closePanel();
            }
        },
        handleDocumentKeydown(event) {
            if (event.key === "Escape") {
                this.closePanel();
            }
        },
    },
    mounted() {
        this.clockTimer = window.setInterval(() => {
            this.nowTick = Date.now();
        }, 30_000);
        document.addEventListener("click", this.handleDocumentClick);
        document.addEventListener("keydown", this.handleDocumentKeydown);
    },
    beforeUnmount() {
        if (this.clockTimer !== null) {
            window.clearInterval(this.clockTimer);
        }

        document.removeEventListener("click", this.handleDocumentClick);
        document.removeEventListener("keydown", this.handleDocumentKeydown);
    },
};
</script>

<template>
    <div
        ref="root"
        class="confirmed-datetime"
        :class="{ 'is-disabled': disabled }"
    >
        <span class="confirmed-datetime__label">{{ title }}</span>

        <div class="confirmed-datetime-control">
            <button
                type="button"
                class="confirmed-datetime-trigger"
                :disabled="disabled"
                :aria-expanded="open"
                aria-haspopup="dialog"
                @click.stop="togglePanel"
            >
                <span class="confirmed-datetime-trigger__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path d="M7 3v3M17 3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z" />
                    </svg>
                </span>
                <span class="confirmed-datetime-trigger__value">
                    <small>Дата и время</small>
                    <strong>{{ formattedValue }}</strong>
                </span>
                <span class="confirmed-datetime-trigger__chevron" aria-hidden="true">⌄</span>
            </button>

            <div
                v-show="open"
                class="confirmed-datetime-panel"
                role="dialog"
                :aria-label="title"
                @click.stop
            >
                <div class="confirmed-datetime-panel__head">
                    <strong>{{ title }}</strong>
                    <button
                        type="button"
                        class="confirmed-datetime-panel__close"
                        aria-label="Закрыть выбор даты и времени"
                        @click="closePanel"
                    >
                        ×
                    </button>
                </div>

                <div class="confirmed-datetime-panel__fields">
                    <label>
                        <span>Дата</span>
                        <input
                            v-model="pendingDate"
                            type="date"
                            :min="minimumDate"
                            :max="maximumDate"
                            :disabled="disabled"
                            @change="handleDateChange"
                        />
                    </label>
                    <label>
                        <span>Время</span>
                        <select
                            v-model="pendingTime"
                            :disabled="disabled || !pendingDate"
                            @change="handleTimeChange"
                        >
                            <option value="">
                                {{ pendingDate ? "Выберите время" : "Сначала выберите дату" }}
                            </option>
                            <option
                                v-for="time in timeOptions"
                                :key="time"
                                :value="time"
                                :disabled="timeDisabled(time)"
                            >
                                {{ time }}
                            </option>
                        </select>
                    </label>
                </div>

            </div>
        </div>

        <small v-if="invalidPast" class="error-message">
            Выберите дату и время не ранее текущего времени плюс 30 минут.
        </small>
        <small v-else-if="invalidFuture" class="error-message">
            Выберите дату и время не позже текущего времени.
        </small>
        <small v-else-if="invalidRange" class="error-message">
            Выберите время с {{ minTime }} до {{ maxTime }}.
        </small>
        <small class="confirmed-datetime__hint">{{ rangeHint }}</small>
    </div>
</template>
