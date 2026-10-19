<script>
import ConfirmedDateTimeInput from "../../components/ConfirmedDateTimeInput.vue";
import { VISIT_TIME_END, VISIT_TIME_START } from "../../data/schedule.js";

export default {
    name: "CustomerSlotDecisionDialog",
    components: { ConfirmedDateTimeInput },
    props: {
        request: {
            type: Object,
            required: true,
        },
        busy: {
            type: Boolean,
            default: false,
        },
        perform: {
            type: Function,
            required: true,
        },
    },
    emits: ["close"],
    data() {
        return {
            slot: "",
            comment: "",
            error: "",
            visitTimeStart: VISIT_TIME_START,
            visitTimeEnd: VISIT_TIME_END,
        };
    },
    mounted() {
        this.$refs.dialog?.showModal();
    },
    methods: {
        close() {
            this.$emit("close");
        },
        async submit() {
            if (!this.slot) {
                this.error = "Выберите другую дату и время";
                return;
            }

            this.error = "";

            try {
                await this.perform("customerSlotDecision", "rescheduled", {
                    slot: this.slot,
                    comment: this.comment,
                });
                this.$emit("close");
            } catch (error) {
                this.error = error.message;
            }
        },
    },
};
</script>

<template>
    <dialog
        ref="dialog"
        class="action-dialog"
        @cancel.prevent="close"
    >
        <form @submit.prevent="submit">
            <h2>Предложить другую дату и время</h2>
            <p>{{ request.number }}</p>

            <ConfirmedDateTimeInput
                v-model="slot"
                title="Желаемая дата и время визита"
                :disabled="busy"
                :min-time="visitTimeStart"
                :max-time="visitTimeEnd"
            />

            <label>
                Комментарий (необязательно)
                <textarea v-model="comment" :disabled="busy" />
            </label>

            <p v-if="error" class="error-message">{{ error }}</p>

            <div class="action-buttons">
                <button
                    type="button"
                    class="ghost-btn"
                    :disabled="busy"
                    @click="close"
                >
                    Отмена
                </button>
                <button class="primary-btn" :disabled="busy || !slot">
                    Отправить на повторное согласование в УК
                </button>
            </div>
        </form>
    </dialog>
</template>
