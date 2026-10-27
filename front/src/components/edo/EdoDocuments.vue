<template>
    <div class="edo-cabinet">
        <header class="edo-header">
            <h2>Документы ЭДО</h2>
            <div class="edo-actions">
                <input
                    v-model="filters.search"
                    type="text"
                    placeholder="Поиск по номеру..."
                    class="input-search"
                    @keyup.enter="fetchDocuments"
                />
                <select
                    v-model="filters.status"
                    class="select-status"
                    @change="fetchDocuments"
                >
                    <option value="">Все статусы</option>
                    <option value="sended">Отправлен</option>
                    <option value="delivered">Доставлен</option>
                    <option value="signed">Подписан</option>
                    <option value="rejected">Отклонен</option>
                </select>
                <button
                    @click="fetchDocuments"
                    class="btn-refresh"
                    :disabled="loading"
                >
                    {{ loading ? "Загрузка..." : "Обновить" }}
                </button>
            </div>
        </header>

        <div v-if="error" class="error-alert">{{ error }}</div>

        <table class="edo-table">
            <thead>
                <tr>
                    <th>Тип</th>
                    <th>Номер</th>
                    <th>Контрагент</th>
                    <th>Направление</th>
                    <th>Статус</th>
                    <th>Дата</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="doc in documents" :key="doc.id">
                    <td>{{ doc.typeLabel }}</td>
                    <td>
                        <strong>{{ doc.number }}</strong>
                    </td>
                    <td>{{ doc.counterparty }}</td>
                    <td>
                        <span
                            :class="[
                                'badge',
                                doc.direction === 'outgoing'
                                    ? 'badge-out'
                                    : 'badge-in',
                            ]"
                        >
                            {{
                                doc.direction === "outgoing"
                                    ? "Исходящий"
                                    : "Входящий"
                            }}
                        </span>
                    </td>
                    <td>
                        <span :class="['status', `status-${doc.status}`]">
                            {{ doc.statusLabel }}
                        </span>
                    </td>
                    <td>{{ formatDate(doc.sentAt) }}</td>
                    <td class="actions">
                        <a
                            v-if="doc.pdfUrl"
                            :href="doc.pdfUrl"
                            target="_blank"
                            class="btn-icon"
                            title="Скачать PDF"
                        >
                            📄
                        </a>
                        <button
                            v-if="doc.canRetry"
                            @click="retryDocument(doc.id)"
                            class="btn-icon btn-retry"
                            title="Повторить отправку"
                        >
                            🔄
                        </button>
                    </td>
                </tr>
                <tr v-if="documents.length === 0 && !loading">
                    <td colspan="7" class="empty-state">
                        Документы не найдены
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<script setup>
import { ref, onMounted } from "vue";
import { marketplaceApi } from "../../api/marketplace";

const documents = ref([]);
const loading = ref(false);
const error = ref(null);

const filters = ref({
    search: "",
    status: "",
});

const fetchDocuments = async () => {
    loading.value = true;
    error.value = null;
    try {
        const response = await marketplaceApi.listEdoDocuments(filters.value);
        // Laravel Resource возвращает { data: [...] } при пагинации
        documents.value = response.documents?.data || response.documents || [];
    } catch (err) {
        error.value = err.message || "Ошибка загрузки документов";
    } finally {
        loading.value = false;
    }
};

const retryDocument = async (uuid) => {
    if (!confirm("Отправить документ повторно?")) return;
    try {
        await marketplaceApi.retryEdoDocument(uuid);
        alert("Документ поставлен в очередь");
        fetchDocuments();
    } catch (err) {
        alert("Ошибка: " + err.message);
    }
};

const formatDate = (isoString) => {
    if (!isoString) return "—";
    return new Date(isoString).toLocaleDateString("ru-RU", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
};

onMounted(fetchDocuments);
</script>

<style scoped>
.edo-cabinet {
    padding: 20px;
    font-family: sans-serif;
}
.edo-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
.edo-actions {
    display: flex;
    gap: 10px;
}
.input-search,
.select-status {
    padding: 8px;
    border: 1px solid #ccc;
    border-radius: 4px;
}
.btn-refresh {
    padding: 8px 16px;
    background: #007bff;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.edo-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}
.edo-table th,
.edo-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #eee;
}
.edo-table th {
    background: #f8f9fa;
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
    color: #666;
}

.status {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: bold;
}
.status-sended {
    background: #e3f2fd;
    color: #1976d2;
}
.status-delivered {
    background: #fff3e0;
    color: #f57c00;
}
.status-signed {
    background: #e8f5e9;
    color: #388e3c;
}
.status-rejected {
    background: #ffebee;
    color: #d32f2f;
}

.badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
}
.badge-out {
    background: #f3e5f5;
    color: #7b1fa2;
}
.badge-in {
    background: #e0f7fa;
    color: #00838f;
}

.actions {
    display: flex;
    gap: 8px;
}
.btn-icon {
    background: none;
    border: none;
    font-size: 18px;
    cursor: pointer;
    opacity: 0.7;
    text-decoration: none;
}
.btn-icon:hover {
    opacity: 1;
}
.btn-retry:hover {
    color: #f57c00;
}

.error-alert {
    background: #ffebee;
    color: #c62828;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 15px;
}
.empty-state {
    text-align: center;
    color: #999;
    padding: 30px;
}
</style>
