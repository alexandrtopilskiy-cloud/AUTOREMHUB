<script setup>
import { ref, computed, onMounted } from "vue";
import FormAlert from "../FormAlert.vue";
import { marketplaceApi } from "../../api/marketplace";

/*
 * Подключение организации пользователя к оператору ЭДО (EDO_PROVIDER на сервере):
 *  - СБИС: логин и пароль кабинета СБИС + идентификатор участника ЭДО;
 *  - Диадок: BoxId и refresh token.
 */
const emit = defineEmits(["close", "connected"]);

const settings = ref(null);
const clientId = ref("");
const refreshToken = ref("");
const login = ref("");
const password = ref("");
const ediCode = ref("");
const submitting = ref(false);
const error = ref("");
const success = ref("");

const isSbis = computed(() => settings.value?.provider === "sbis");

const submitButtonText = computed(() => {
    return submitting.value ? "Подключение..." : "Подключить";
});

const isFormValid = computed(() => {
    if (isSbis.value) {
        return login.value.trim() !== "" && (password.value.trim() !== "" || settings.value?.connected);
    }
    return clientId.value.trim() !== "" && refreshToken.value.trim() !== "";
});

const submitBtnClass = computed(() => ({
    "primary-btn": !submitting.value,
    "loading-btn": submitting.value,
}));

onMounted(async () => {
    try {
        settings.value = await marketplaceApi.getEdoSettings();
        login.value = settings.value?.login || "";
        ediCode.value = settings.value?.ediCode || "";
    } catch (e) {
        error.value = e.message;
    }
});

const submit = async () => {
    error.value = "";
    success.value = "";

    if (!isFormValid.value) {
        error.value = isSbis.value ? "Укажите логин и пароль СБИС" : "Заполните оба поля";
        return;
    }

    submitting.value = true;
    try {
        if (isSbis.value) {
            const result = await marketplaceApi.connectSbisOrganization({
                login: login.value.trim(),
                password: password.value,
                ediCode: ediCode.value.trim(),
            });
            success.value = result?.message || "Организация подключена к СБИС";
        } else {
            await marketplaceApi.connectEdoOrganization(clientId.value, refreshToken.value);
            success.value = "Организация успешно подключена к Диадоку!";
        }
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
                <FormAlert v-if="error" class="error-banner">{{ error }}</FormAlert>
                <FormAlert v-if="success" class="success-banner">{{ success }}</FormAlert>
                <template v-if="isSbis">
                    <p class="hint">
                        Оператор ЭДО — СБИС{{ settings?.mock ? " (тестовый режим: документы в СБИС не уходят)" : "" }}.
                        Организация: {{ settings?.organization || "—" }}.
                        <template v-if="settings?.connected"> Подключено, логин {{ settings.login }}.</template>
                        Пароль шифруется и хранится в базе данных; закрывающие документы
                        записываются в СБИС черновиками от имени этой организации.
                    </p>

                    <label class="field">
                        <span>Логин СБИС</span>
                        <input v-model="login" type="text" autocomplete="username" placeholder="Логин кабинета СБИС" />
                    </label>

                    <label class="field">
                        <span>Пароль СБИС</span>
                        <input
                            v-model="password"
                            type="password"
                            autocomplete="new-password"
                            :placeholder="settings?.connected ? 'Оставьте пустым, чтобы не менять' : 'Пароль кабинета СБИС'"
                        />
                    </label>

                    <label class="field">
                        <span>Идентификатор участника ЭДО</span>
                        <input v-model="ediCode" type="text" placeholder="Например: 2BE1234567890123456789012345678901" />
                    </label>
                </template>

                <template v-else>
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
                </template>

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
