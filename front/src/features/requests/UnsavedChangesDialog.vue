<script>
export default {
    name: "UnsavedChangesDialog",
    props: {
        title: {
            type: String,
            required: true,
        },
        busy: {
            type: Boolean,
            default: false,
        },
    },
    emits: ["save", "discard"],
    mounted() {
        this.$refs.dialog.showModal();
    },
};
</script>

<template>
    <dialog ref="dialog" class="action-dialog unsaved-dialog" @cancel.prevent>
        <h2>Изменения не сохранены</h2>
        <p>
            В документе «{{ title }}» есть несохранённые изменения. Что сделать
            перед закрытием?
        </p>

        <div class="action-buttons">
            <button
                type="button"
                class="ghost-btn"
                :disabled="busy"
                @click="$emit('discard')"
            >
                Не сохранить и продолжить
            </button>
            <button
                type="button"
                class="primary-btn"
                :disabled="busy"
                @click="$emit('save')"
            >
                {{ busy ? "Сохраняем…" : "Сохранить и продолжить" }}
            </button>
        </div>
    </dialog>
</template>
