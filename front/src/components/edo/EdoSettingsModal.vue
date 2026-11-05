<script setup>
import { ref, computed } from "vue";
import { marketplaceApi } from "../../api/marketplace";

const emit = defineEmits(["close", "connected"]);

const clientId = ref("");
const refreshToken = ref("");
const submitting = ref(false);
const error = ref("");
const success = ref("");

const submitButtonText = computed(() => {
    return submitting.value ? "Подключение..." : "Подключить";
});

const isFormValid = computed(() => {
    return clientId.value.trim() !== "" && refreshToken.value.trim() !== "";
});

const submitBtnClass = computed(() => ({
    "primary-btn": !submitting.value,
    "loading-btn": submitting.value,
}));

const submit = async () => {
    error.value = "";
    success.value = "";

    // Используем computed для проверки
    if (!isFormValid.value) {
        error.value = "Заполните оба поля";
        return;
    }

    submitting.value = true;
    try {
        await marketplaceApi.connectEdoOrganization(
            clientId.value,
            refreshToken.value,
        );

        success.value = "Организация успешно подключена к Диадоку!";
        emit("connected");

        setTimeout(() => {
            emit("close");
        }, 1500);
    } catch (e) {
        error.value = e.message;
    } finally {
        submitting.value = false;
    }
};
</script>

<template>
    <div class="modal-overlay" @click.self="$emit('close')">
        <div class="modal-content">
            <header class="modal-header">
                <h2>Настройки ЭДО</h2>
                <button class="close-btn" @click="$emit('close')">×</button>
            </header>

            <div class="modal-body">
                <p class="hint">
                    Введите данные из Кабинета интегратора Контура. Токен будет
                    зашифрован и сохранен в базе данных.
                </p>

                <label class="field">
                    <span>BoxId (Идентификатор ящика)</span>
                    <input
                        v-model="clientId"
                        type="text"
                        placeholder="Например: bc664699-a7cf-4c80-b221-a34a0c97d22c"
                    />
                </label>

                <label class="field">
                    <span>Refresh Token</span>
                    <textarea
                        v-model="refreshToken"
                        rows="3"
                        placeholder="Вставьте длинный токен из кабинета"
                    ></textarea>
                </label>

                <div v-if="error" class="error-banner">{{ error }}</div>
                <div v-if="success" class="success-banner">{{ success }}</div>
            </div>

            <footer class="modal-footer">
                <button class="ghost-btn" @click="$emit('close')">
                    Отмена
                </button>
                <button
                    class="primary-btn"
                    :class="submitBtnClass"
                    :disabled="submitting || !isFormValid"
                    @click="submit"
                >
                    {{ submitButtonText }}
                </button>
            </footer>
        </div>
    </div>
</template>

<style scoped>
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}
.modal-content {
    background: white;
    border-radius: 8px;
    width: 100%;
    max-width: 500px;
    padding: 20px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}
.close-btn {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
}
.hint {
    font-size: 13px;
    color: #666;
    margin-bottom: 20px;
}
.field {
    display: block;
    margin-bottom: 15px;
}
.field span {
    display: block;
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 5px;
}
.field input,
.field textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-family: inherit;
    box-sizing: border-box;
}
.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
}
.ghost-btn {
    padding: 8px 16px;
    background: none;
    border: 1px solid #ccc;
    border-radius: 4px;
    cursor: pointer;
}
.primary-btn {
    padding: 8px 16px;
    background: #007bff;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}
.error-banner {
    color: #d32f2f;
    background: #ffebee;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 15px;
    font-size: 14px;
}
.success-banner {
    color: #2e507d;
    background: #e8eef5;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 15px;
    font-size: 14px;
}
</style>
