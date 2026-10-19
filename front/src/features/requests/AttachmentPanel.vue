<script setup>
import { computed, ref, watch } from "vue";
import { http } from "../../api/client.js";
import { marketplaceApi } from "../../api/marketplace.js";

/* ─── Константы ─── */

const MAX_FILE_SIZE_BYTES = 100 * 1024 * 1024;
const BYTES_PER_KB = 1024;

const IMAGE_MIME_TYPES = ["image/jpeg", "image/png", "image/webp"];
const VIDEO_MIME_TYPES = ["video/mp4", "video/webm", "video/quicktime"];
const MEDIA_MIME_TYPES = [...IMAGE_MIME_TYPES, ...VIDEO_MIME_TYPES];

const PDF_MIME_TYPE = "application/pdf";

const PURPOSE_EVIDENCE = "evidence";
const PURPOSE_DIAGNOSTIC = "diagnostic";
const PURPOSE_ISSUED_INVOICE = "issued_invoice";
const PURPOSE_PAID_INVOICE = "paid_invoice";

/* ─── Props ─── */

const props = defineProps({
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
    optional: {
        type: Boolean,
        default: false,
    },
    hideTitle: {
        type: Boolean,
        default: false,
    },
});

/* ─── Состояние ─── */

const purpose = ref(PURPOSE_EVIDENCE);
const error = ref("");

/* ─── Список доступных назначений ─── */

const purposes = computed(() => {
    const values = props.request.permissions?.canIssueInvoice
        ? [[PURPOSE_ISSUED_INVOICE, "Выставленный счёт"]]
        : [[PURPOSE_EVIDENCE, "Фото / видео этапа"]];

    if (props.request.permissions?.canDiagnose) {
        values.push([PURPOSE_DIAGNOSTIC, "Материалы диагностики"]);
    }

    if (props.request.permissions?.canSubmitPayment) {
        values.push([PURPOSE_PAID_INVOICE, "Закрытый счёт"]);
    }

    return values;
});

/* ─── Accept для input[type=file] ─── */

const accept = computed(() => {
    const media = MEDIA_MIME_TYPES.join(",");

    switch (purpose.value) {
        case PURPOSE_EVIDENCE:
            return `${media},${PDF_MIME_TYPE}`;
        case PURPOSE_DIAGNOSTIC:
            return `${media},${PDF_MIME_TYPE}`;
        default:
            return `${PDF_MIME_TYPE},${IMAGE_MIME_TYPES.join(",")}`;
    }
});

/* ─── Сброс назначения, если оно стало недоступным ─── */

watch(
    purposes,
    (values) => {
        const available = values.some(([key]) => key === purpose.value);

        if (!available) {
            purpose.value = values[0]?.[0] || PURPOSE_EVIDENCE;
        }
    },
    { immediate: true },
);

/* ─── Загрузка файлов ─── */

async function upload(event) {
    const files = [...event.target.files];

    error.value = "";

    try {
        for (const file of files) {
            if (file.size > MAX_FILE_SIZE_BYTES) {
                throw new Error("Файл должен быть не больше 100 МБ");
            }

            await props.perform("upload", file, purpose.value);
        }

        event.target.value = "";
    } catch (e) {
        error.value = e.message;
    }
}

/* ─── Скачивание файла с Authorization ─── */

async function open(file) {
    error.value = "";

    try {
        const url = await marketplaceApi.attachmentUrl(file.id);

        const response = await fetch(url, {
            headers: http.getToken()
                ? { Authorization: `Bearer ${http.getToken()}` }
                : {},
        });

        if (!response.ok) {
            throw new Error("Не удалось скачать файл");
        }

        const blob = await response.blob();
        const objectUrl = URL.createObjectURL(blob);

        const link = document.createElement("a");
        link.href = objectUrl;
        link.download = file.name;
        link.click();

        URL.revokeObjectURL(objectUrl);
    } catch (e) {
        error.value = e.message;
    }
}

/* ─── Форматирование размера ─── */

function fileSizeKb(size) {
    return Math.ceil((size || 0) / BYTES_PER_KB);
}

function fileStatus(file) {
    return file.linked ? "в истории этапа" : "прикреплён";
}
</script>

<template>
    <section class="feature-card">
        <h3 v-if="!hideTitle">Фото, видео и документы</h3>

        <div v-if="request.permissions?.canUpload" class="inline-form">
            <label>
                Назначение
                <select v-model="purpose" :disabled="busy">
                    <option
                        v-for="[key, title] in purposes"
                        :key="key"
                        :value="key"
                    >
                        {{ title }}
                    </option>
                </select>
            </label>

            <label class="upload-label">
                {{ busy ? "Загрузка / сохранение…" : "Прикрепить файлы" }}
                <input
                    type="file"
                    multiple
                    :disabled="busy"
                    :accept="accept"
                    @change="upload"
                />
            </label>
        </div>

        <p class="muted">
            Фото, видео и PDF — до 100 МБ на файл. Вложения необязательны для
            перехода между кабинетами.
        </p>

        <p v-if="error" class="error-message">{{ error }}</p>

        <ul class="attachment-list">
            <li v-for="file in request.attachments" :key="file.id">
                <button type="button" @click="open(file)">
                    {{ file.name }}
                </button>
                <small>
                    {{ fileSizeKb(file.size) }} КБ · {{ fileStatus(file) }}
                </small>
            </li>
        </ul>

        <p v-if="!request.attachments.length" class="muted">
            Вложения отсутствуют.
        </p>
    </section>
</template>
