<script>
import { marketplaceApi } from "../../api/marketplace.js";
import {
    cryptoProErrorMessage,
    listCertificates,
    signAttachments,
} from "../../utils/cryptoProSign.js";

/**
 * Подписание документа СБИС УКЕП с носителя (КриптоПро ЭЦП Browser plug-in):
 * выбор сертификата → ПодготовитьДействие → подпись вложений в браузере →
 * ВыполнитьДействие (отправка получателю или утверждение получателем).
 */
export default {
    name: "EdoSignModal",
    props: {
        document: { type: Object, required: true },
    },
    emits: ["close", "signed"],
    data() {
        return {
            certificates: [],
            thumbprint: "",
            loading: true,
            busy: false,
            step: "",
            error: "",
        };
    },
    computed: {
        selected() {
            return this.certificates.find((item) => item.thumbprint === this.thumbprint) || null;
        },
        title() {
            return this.document.direction === "outgoing"
                ? "Подписать и отправить"
                : "Подписать входящий документ";
        },
    },
    async mounted() {
        try {
            this.certificates = await listCertificates();
            this.thumbprint = this.certificates.find((item) => item.valid)?.thumbprint || "";
            if (!this.certificates.length) {
                this.error = "Сертификаты не найдены. Вставьте носитель с УКЕП и нажмите «Обновить список».";
            }
        } catch (error) {
            this.error = cryptoProErrorMessage(error);
        } finally {
            this.loading = false;
        }
    },
    methods: {
        async reloadCertificates() {
            this.loading = true;
            this.error = "";
            try {
                this.certificates = await listCertificates();
                this.thumbprint = this.certificates.find((item) => item.valid)?.thumbprint || "";
                if (!this.certificates.length) this.error = "Сертификаты не найдены.";
            } catch (error) {
                this.error = cryptoProErrorMessage(error);
            } finally {
                this.loading = false;
            }
        },
        formatDate(value) {
            return value ? new Date(value).toLocaleDateString("ru-RU") : "—";
        },
        async sign() {
            if (!this.selected || this.busy) return;
            this.busy = true;
            this.error = "";
            try {
                this.step = "Готовим документ в СБИС…";
                const prepared = await marketplaceApi.prepareSbisSigning(this.document.id);
                this.step = `Подписываем вложения (${prepared.attachments.length})… Подтвердите доступ к ключу, если КриптоПро спросит`;
                const signatures = await signAttachments(this.selected, prepared.attachments);
                this.step = `СБИС: «${prepared.action}»…`;
                const response = await marketplaceApi.executeSbisSigning(this.document.id, signatures);
                this.$emit("signed", response.data || response);
            } catch (error) {
                this.error = error?.status ? error.message : cryptoProErrorMessage(error);
            } finally {
                this.busy = false;
                this.step = "";
            }
        },
    },
};
</script>

<template>
    <div class="modal-backdrop" @click.self="!busy && $emit('close')">
        <div class="modal edo-sign-modal" role="dialog" aria-modal="true" :aria-label="title">
            <header class="edo-sign-head">
                <h2>{{ title }}</h2>
                <button class="ghost-btn" :disabled="busy" aria-label="Закрыть" @click="$emit('close')">✕</button>
            </header>
            <p class="edo-sign-doc">
                <strong>{{ document.typeLabel }} {{ document.number }}</strong>
                <span v-if="document.request"> · {{ document.request.number }}</span>
                <span> · {{ document.counterparty }}</span>
            </p>
            <p v-if="loading" class="edo-sign-muted">Ищем сертификаты КриптоПро…</p>
            <template v-else-if="certificates.length">
                <label class="edo-sign-label" for="edo-sign-cert">Сертификат подписи</label>
                <select id="edo-sign-cert" v-model="thumbprint" :disabled="busy">
                    <option v-for="item in certificates" :key="item.thumbprint" :value="item.thumbprint" :disabled="!item.valid">
                        {{ item.name }} · до {{ formatDate(item.validTo) }}{{ item.valid ? "" : " (истёк)" }}
                    </option>
                </select>
                <p v-if="selected" class="edo-sign-muted">Издатель: {{ selected.issuer }}</p>
            </template>
            <p v-if="step" class="edo-sign-step">{{ step }}</p>
            <p v-if="error" class="edo-sign-error">{{ error }}</p>
            <footer class="edo-sign-actions">
                <button class="ghost-btn" :disabled="loading || busy" @click="reloadCertificates">Обновить список</button>
                <button class="primary-btn" :disabled="!selected || busy" @click="sign">
                    {{ busy ? "Подписываем…" : document.signLabel || "Подписать" }}
                </button>
            </footer>
        </div>
    </div>
</template>

<style scoped>
.modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    background: rgba(15, 30, 55, 0.45);
}

.edo-sign-modal {
    width: min(560px, 100%);
    padding: 20px;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
}

.edo-sign-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.edo-sign-head h2 {
    margin: 0;
    font-size: 18px;
}

.edo-sign-doc {
    margin: 10px 0 16px;
}

.edo-sign-label {
    display: block;
    margin-bottom: 6px;
    font-size: 13px;
    font-weight: 650;
}

.edo-sign-modal select {
    width: 100%;
    min-height: 38px;
}

.edo-sign-muted {
    color: var(--muted);
    font-size: 13px;
}

.edo-sign-step {
    color: #1f5fbf;
}

.edo-sign-error {
    padding: 10px 12px;
    border-radius: 4px;
    background: #fbe3e2;
    color: #a32d29;
    white-space: pre-line;
}

.edo-sign-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 16px;
}
</style>
