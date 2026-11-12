<script setup>
import { computed, onMounted, ref } from "vue";
import FormAlert from "../../components/FormAlert.vue";
import { marketplaceApi } from "../../api/marketplace.js";

/*
 * Меню «Разработчик» → «Прочитать все уведомления» (только ЛК УК).
 * Отмечает прочитанными непрочитанные уведомления всех пользователей контура:
 * УК, СТО и Заказчиков. Прочитанное уведомление в системе не хранится —
 * так же, как при открытии уведомления самим пользователем.
 */

const emit = defineEmits(["read", "notify"]);

const users = ref([]);
const total = ref(0);
const loading = ref(false);
const busy = ref(false);
const error = ref("");
const result = ref(null);

const byRole = computed(() => {
    const groups = {};
    for (const row of users.value) {
        groups[row.roleLabel] = (groups[row.roleLabel] || 0) + row.unread;
    }
    return Object.entries(groups);
});

async function load() {
    loading.value = true;
    error.value = "";
    try {
        const data = await marketplaceApi.developerNotifications();
        users.value = data.users || [];
        total.value = data.total || 0;
    } catch (exception) {
        error.value = exception.message || "Не удалось загрузить уведомления";
    } finally {
        loading.value = false;
    }
}

async function readAll() {
    if (!total.value || busy.value) return;
    if (!window.confirm(`Отметить прочитанными все уведомления (${total.value}) во всех ЛК? Вернуть их будет нельзя.`)) return;
    busy.value = true;
    error.value = "";
    try {
        const data = await marketplaceApi.developerReadAllNotifications();
        result.value = data.read || 0;
        emit("read");
        emit("notify", `Прочитано уведомлений: ${result.value}`);
        await load();
    } catch (exception) {
        error.value = exception.message || "Не удалось прочитать уведомления";
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="developer-notifications">
        <div class="list-heading">
            <div>
                <span class="eyebrow">Разработчик</span>
                <h1>Прочитать все уведомления <span class="count">{{ total }}</span></h1>
            </div>
            <div class="developer-actions">
                <button class="ghost-btn" :disabled="loading || busy" @click="load">Обновить</button>
                <button class="primary-btn" :disabled="!total || busy" @click="readAll">
                    {{ busy ? "Читаем…" : `Прочитать все (${total})` }}
                </button>
            </div>
        </div>
        <FormAlert v-if="error" class="error-message">{{ error }}</FormAlert>
        <p v-if="result !== null" class="developer-result">Прочитано уведомлений: {{ result }}.</p>
        <p class="list-hint developer-warning">
            Непрочитанные уведомления всех пользователей во всех ЛК — УК, СТО и Заказчиков — будут отмечены
            прочитанными и исчезнут из блока «Уведомления» в меню. Сообщения чатов по заявкам не затрагиваются.
        </p>
        <p v-if="byRole.length" class="developer-summary">
            <span v-for="[label, count] in byRole" :key="label">{{ label }}: <strong>{{ count }}</strong></span>
        </p>
        <div v-if="users.length" class="registry-wrap">
            <table class="registry">
                <thead>
                    <tr>
                        <th>ЛК</th>
                        <th>Организация</th>
                        <th>Логин</th>
                        <th class="align-right">Непрочитанных</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in users" :key="row.login">
                        <td>{{ row.roleLabel }}</td>
                        <td>{{ row.organization }}</td>
                        <td><strong>{{ row.login }}</strong></td>
                        <td class="align-right">{{ row.unread }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-else class="empty-state">
            <h3>{{ loading ? "Загружаем…" : "Непрочитанных уведомлений нет" }}</h3>
        </div>
    </div>
</template>

<style scoped>
.developer-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.developer-warning,
.developer-result,
.developer-summary {
    margin: 0 24px 12px;
}
.developer-result {
    font-weight: 600;
}
.developer-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
}
</style>
