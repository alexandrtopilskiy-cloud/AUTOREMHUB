<script>
import { formatDateTime } from '../../data/permissions.js';
import { handoverSheet } from './handoverSheet.js';

/*
 * Блок «Приём ТС от Заказчика на СТО» вкладки «Акты приёма-передачи».
 * СТО печатает предзаполненный лист приёма-передачи ТС, загружает
 * подписанный экземпляр и переводит ЗР «В работу» кнопкой «Далее по маршруту».
 */
const PURPOSE = 'acceptance_act';
const STAGE_WAITING_VEHICLE = 4;

export default {
    name: "AcceptanceActPanel",
    props: {
        request: { type: Object, required: true },
        busy: { type: Boolean, default: false },
        perform: { type: Function, required: true },
    },
    data() {
        return {
            mileage: this.request.mileage || "",
            attachmentId: "",
            uploading: false,
            loadingSheet: "",
            error: "",
        };
    },
    computed: {
        act() {
            return this.request.acceptanceAct || null;
        },
        canAccept() {
            return this.request.permissions?.canAcceptVehicle === true;
        },
        available() {
            return this.request.stage >= STAGE_WAITING_VEHICLE || Boolean(this.act);
        },
        files() {
            return (this.request.attachments || []).filter(
                (file) => file.purpose === PURPOSE && !file.linked,
            );
        },
        canSubmit() {
            return !this.busy && !this.uploading && Boolean(this.attachmentId) && this.mileage !== "" && Number(this.mileage) >= 0;
        },
    },
    watch: {
        files: {
            immediate: true,
            handler(files) {
                if (files.length && !files.some((file) => file.id === this.attachmentId)) {
                    this.attachmentId = files[files.length - 1].id;
                }
            },
        },
    },
    methods: {
        formatDateTime,
        async sheet(mode) {
            this.error = "";
            this.loadingSheet = mode;
            try {
                await handoverSheet(this.request, "in", mode);
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loadingSheet = "";
            }
        },
        async upload(event) {
            const file = event.target.files?.[0];
            event.target.value = "";
            if (!file) {
                return;
            }

            this.error = "";
            this.uploading = true;
            try {
                await this.perform("upload", file, PURPOSE);
            } catch (error) {
                this.error = error.message;
            } finally {
                this.uploading = false;
            }
        },
        async submit() {
            if (!this.canSubmit) {
                return;
            }

            this.error = "";
            try {
                await this.perform("acceptVehicle", {
                    mode: "uploaded",
                    mileage: Number(this.mileage),
                    attachmentId: this.attachmentId,
                });
            } catch (error) {
                this.error = error.message;
            }
        },
    },
};
</script>

<template>
    <section class="feature-card handover-block">
        <div class="handover-head">
            <div>
                <h3>Приём ТС от Заказчика на СТО</h3>
                <small>Лист приёма-передачи ТС {{ act?.number || '' }}</small>
            </div>
            <span v-if="act" class="handover-state done">Подписанный лист загружен</span>
            <span v-else-if="available" class="handover-state">Ожидает подписанного листа</span>
            <span v-else class="handover-state muted-state">Этап «Ожидание ТС на СТО»</span>
        </div>

        <p v-if="!available" class="muted handover-note">
            Лист станет доступен, когда ЗР перейдёт на этап «Ожидание ТС на СТО».
        </p>

        <template v-else>
            <div class="handover-actions">
                <button type="button" class="ghost-btn" :disabled="busy || Boolean(loadingSheet)" @click="sheet('pdf')">
                    {{ loadingSheet === 'pdf' ? 'Формируем…' : 'Выгрузить в PDF' }}
                </button>
                <button type="button" class="ghost-btn" :disabled="busy || Boolean(loadingSheet)" @click="sheet('print')">
                    {{ loadingSheet === 'print' ? 'Формируем…' : 'Распечатать' }}
                </button>
                <small class="muted">
                    Заказчик, водитель, ТС и СТО заполняются автоматически; пробег, топливо, отметки и подписи — от руки.
                </small>
            </div>

            <dl v-if="act" class="handover-facts">
                <div><dt>Подписанный лист</dt><dd>{{ act.attachmentName || act.number }}<small v-if="act.lockedAt">{{ formatDateTime(act.lockedAt) }}</small></dd></div>
                <div><dt>Пробег при приёме</dt><dd>{{ act.mileage }} км</dd></div>
                <div><dt>Водитель</dt><dd>{{ act.driver?.fullName || '—' }}</dd></div>
            </dl>

            <form v-else-if="canAccept" class="handover-form" @submit.prevent="submit">
                <p class="muted handover-note">
                    Распечатайте лист, заполните его при осмотре ТС вместе с водителем,
                    подпишите и загрузите скан или фото подписанного листа.
                </p>
                <div class="handover-row">
                    <button type="button" class="ghost-btn" :disabled="uploading || busy" @click="$refs.file.click()">
                        {{ uploading ? 'Загружаем…' : 'Загрузить подписанный лист' }}
                    </button>
                    <input ref="file" type="file" hidden accept="application/pdf,image/jpeg,image/png,image/webp" @change="upload" />
                    <select v-if="files.length" v-model="attachmentId" :disabled="busy" aria-label="Подписанный лист приёма-передачи">
                        <option v-for="file in files" :key="file.id" :value="file.id">{{ file.name }}</option>
                    </select>
                    <span v-else class="muted">Лист ещё не загружен</span>
                </div>
                <label class="handover-mileage">
                    Пробег при приёме, км
                    <input v-model="mileage" type="number" min="0" step="1" required :disabled="busy" />
                </label>
                <div class="handover-actions">
                    <button class="primary-btn" :disabled="!canSubmit">Прикрепить лист к ЗР</button>
                </div>
            </form>

            <p v-else class="muted handover-note">Подписанный лист загружает СТО при приёме ТС.</p>

            <p v-if="act && request.stage === 4 && request.permissions?.canAdvance" class="info-banner">
                Лист прикреплён. Нажмите «Далее по маршруту» — ЗР перейдёт на этап «В работе».
            </p>
        </template>

        <p v-if="error" class="error-message">{{ error }}</p>
    </section>
</template>
