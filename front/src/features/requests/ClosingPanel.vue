<script setup>
import { computed, ref, watch } from 'vue';
import { marketplaceApi } from '../../api/marketplace.js';
import { formatDateTime, money } from '../../data/permissions.js';
import { handoverSheet } from './handoverSheet.js';

/*
 * Закрытие ЗР:
 *  6 «ТС готово к выдаче Заказчику» — акт передачи ТС; СТО загружает скан,
 *    подписанный водителем, и подтверждает сверку документов с доверенностью;
 *  7 «Выдан. Подготовка документов» — УК формирует 7 документов;
 *  8 «Подтверждение оплаты Заказчика», 9 «Оплата в СТО» — УК принимает оплату
 *    («Принять оплату») и может удалить её («Удалить оплату») только на своём этапе.
 */

const PURPOSE_TRANSFER_ACT = 'transfer_act';

const props = defineProps({
  request: { type: Object, required: true },
  busy: { type: Boolean, default: false },
  perform: { type: Function, required: true },
  /** Часть панели для вкладки ЗР: act | documents | payments | all. */
  part: { type: String, default: 'all' },
});

const error = ref('');
const fileInput = ref(null);
const signedFileId = ref('');
const driverVerified = ref(false);
const payment = ref({ paidAt: today(), amount: '', comment: '' });

const permissions = computed(() => props.request.permissions || {});
const closing = computed(() => props.request.closing || { documents: [], transferAct: null });
const act = computed(() => closing.value.transferAct);
const documents = computed(() => closing.value.documents || []);
const stationDocuments = computed(() => documents.value.filter((doc) => doc.side === 'station'));
const customerDocuments = computed(() => documents.value.filter((doc) => doc.side === 'customer'));
const showPart = (name) => props.part === 'all' || props.part === name;
const showAct = computed(() => showPart('act') && Boolean(act.value));
const showDocuments = computed(() => showPart('documents') && Boolean(documents.value.length || permissions.value.canGenerateDocuments && props.request.stage === 7));
const invoices = computed(() => documents.value.filter((doc) => doc.type.endsWith('invoice')));
const showPayment = computed(() => showPart('payments') && Boolean(payableInvoice.value));
/** Вкладка «Взаиморасчёты»: сводка по счетам ЗР, без дублирования в режиме all. */
const showSettlements = computed(() => props.part === 'payments' && invoices.value.length > 0);
const visible = computed(() => props.request.stage >= 6 && (showAct.value || showDocuments.value || showPayment.value || showSettlements.value));

const signedFiles = computed(() =>
  (props.request.attachments || []).filter((file) => file.purpose === PURPOSE_TRANSFER_ACT),
);

const payableInvoice = computed(() => {
  if (permissions.value.canMarkCustomerPayment) {
    return documents.value.find((doc) => doc.type === 'customer_invoice') || null;
  }
  if (permissions.value.canMarkStationPayment) {
    return documents.value.find((doc) => doc.type === 'station_invoice') || null;
  }
  return null;
});

const paymentTitle = computed(() =>
  permissions.value.canMarkCustomerPayment ? 'Оплата Заказчика' : 'Оплата в пользу СТО',
);

watch(
  payableInvoice,
  (invoice) => {
    if (invoice && !payment.value.amount) {
      payment.value.amount = String(invoice.amount);
    }
  },
  { immediate: true },
);

watch(
  signedFiles,
  (files) => {
    if (files.length && !files.some((file) => file.id === signedFileId.value)) {
      signedFileId.value = files[files.length - 1].id;
    }
  },
  { immediate: true },
);

function today() {
  const now = new Date();
  const offset = now.getTimezoneOffset() * 60_000;

  return new Date(now.getTime() - offset).toISOString().slice(0, 10);
}

function formatDate(value) {
  if (!value) return '—';
  const [year, month, day] = String(value).slice(0, 10).split('-');

  return `${day}.${month}.${year}`;
}

async function run(method, ...args) {
  error.value = '';
  try {
    await props.perform(method, ...args);
  } catch (e) {
    error.value = e.message;
  }
}

async function upload(event) {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (!file) return;
  await run('upload', file, PURPOSE_TRANSFER_ACT);
}

function sign() {
  return run('signTransferAct', {
    attachmentId: signedFileId.value,
    driverDocumentsVerified: driverVerified.value,
  });
}

function markPaid() {
  const method = permissions.value.canMarkCustomerPayment ? 'markCustomerPayment' : 'markStationPayment';

  return run(method, { ...payment.value });
}

function deletePayment() {
  if (!payableInvoice.value?.paidAt) return undefined;
  if (!window.confirm(`Удалить принятую оплату по счёту ${payableInvoice.value.number}?`)) return undefined;
  const method = permissions.value.canMarkCustomerPayment ? 'deleteCustomerPayment' : 'deleteStationPayment';

  return run(method);
}

/** Лист приёма-передачи ТС (выдача): формируется в момент выгрузки — дата и представитель СТО из ЛК. */
async function sheet(mode) {
  error.value = '';
  try {
    await handoverSheet(props.request, 'out', mode);
  } catch (e) {
    error.value = e.message;
  }
}

async function download(doc) {
  error.value = '';
  try {
    const blob = await marketplaceApi.requestDocumentPdf(doc.id);
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `${doc.number.replaceAll('/', '-')} ${doc.label}.pdf`;
    link.click();
    URL.revokeObjectURL(url);
  } catch (e) {
    error.value = e.message;
  }
}
</script>

<template>
  <section v-if="visible" class="feature-card closing-panel" :class="{ 'closing-panel-act': part === 'act' }">
    <h3 v-if="part === 'all'">Выдача ТС и закрывающие документы</h3>

    <!-- Приём ТС от СТО на Заказчика: лист приёма-передачи ТС (APP-…/2) -->
    <div v-if="showAct" class="closing-block">
      <div class="closing-head">
        <div>
          <h3 v-if="part === 'act'">Приём ТС от СТО на Заказчика</h3>
          <strong v-else>Лист приёма-передачи ТС {{ act.number }}</strong>
          <small>Лист приёма-передачи ТС {{ act.number }} от {{ formatDate(act.date) }}</small>
        </div>
        <span class="closing-state" :class="{ done: act.driverDocumentsVerified }">
          {{ act.driverDocumentsVerified ? 'Подписанный лист загружен, доверенность сверена' : 'Ожидает подписанного листа' }}
        </span>
      </div>
      <div class="closing-actions">
        <button type="button" class="ghost-btn" :disabled="busy" @click="sheet('pdf')">Выгрузить в PDF</button>
        <button type="button" class="ghost-btn" :disabled="busy" @click="sheet('print')">Распечатать</button>
        <small class="muted">Стороны, водитель, ТС и доверенность заполняются автоматически; пробег, отметки и подписи — от руки.</small>
      </div>

      <dl class="closing-facts">
        <div><dt>Подписанный лист</dt><dd><template v-if="act.signedAt">{{ act.signedAttachmentName }}<small>{{ formatDateTime(act.signedAt) }}</small></template><template v-else>—</template></dd></div>
        <div><dt>Доверенность</dt><dd>{{ act.powerOfAttorney?.number || '—' }}<small v-if="act.powerOfAttorney?.date">от {{ formatDate(act.powerOfAttorney.date) }}</small></dd></div>
        <div><dt>Водитель Заказчика</dt><dd>{{ act.driver?.fullName || '—' }}<small v-if="act.driver?.phone">{{ act.driver.phone }}</small></dd></div>
      </dl>

      <form v-if="permissions.canSignTransferAct" class="closing-sign" @submit.prevent="sign">
        <p class="muted">
          Распечатайте лист, передайте ТС водителю и загрузите скан листа с
          подписями. Перед выдачей сверьте паспорт и водительское удостоверение
          водителя с доверенностью {{ act.powerOfAttorney?.number }}.
        </p>
        <div class="closing-sign-row">
          <button type="button" class="ghost-btn" :disabled="busy" @click="fileInput?.click()">
            Загрузить подписанный лист
          </button>
          <input ref="fileInput" type="file" hidden accept="application/pdf,image/jpeg,image/png,image/webp" @change="upload" />
          <select v-if="signedFiles.length" v-model="signedFileId" :disabled="busy" aria-label="Подписанный лист приёма-передачи">
            <option v-for="file in signedFiles" :key="file.id" :value="file.id">{{ file.name }}</option>
          </select>
          <span v-else class="muted">Лист ещё не загружен</span>
        </div>
        <label class="closing-check">
          <input v-model="driverVerified" type="checkbox" :disabled="busy" />
          Лицо, которому передаётся ТС, сверено с действующей доверенностью
          <template v-if="act.powerOfAttorney?.number">{{ act.powerOfAttorney.number }}<template v-if="act.driver?.fullName"> ({{ act.driver.fullName }})</template></template>
        </label>
        <div class="closing-actions">
          <button class="primary-btn" :disabled="busy || !signedFileId || !driverVerified">
            Прикрепить лист к ЗР
          </button>
          <button
            v-if="permissions.canGenerateDocuments"
            type="button"
            class="ghost-btn"
            :disabled="busy"
            @click="run('generateDocuments')"
          >
            Переформировать лист
          </button>
        </div>
        <p v-if="act.driverDocumentsVerified" class="info-banner">
          Лист прикреплён. Нажмите «Далее по маршруту» — ЗР перейдёт в УК
          на этап «Выдан. Подготовка документов».
        </p>
      </form>
    </div>

    <!-- Документы по ЗР -->
    <div v-if="showDocuments" class="closing-block">
      <div class="closing-head">
        <strong>Документы по заявке</strong>
        <button
          v-if="permissions.canGenerateDocuments && request.stage === 7"
          type="button"
          class="ghost-btn"
          :disabled="busy"
          @click="run('generateDocuments')"
        >
          {{ documents.length ? 'Переформировать документы' : 'Сформировать документы' }}
        </button>
      </div>

      <template v-for="group in [
        { key: 'station', title: 'СТО → УК (с учётом агентской скидки)', rows: stationDocuments },
        { key: 'customer', title: stationDocuments.length ? 'УК → Заказчик (без агентской скидки)' : '', rows: customerDocuments },
      ]" :key="group.key">
        <div v-if="group.rows.length" class="table-wrap closing-docs">
          <p v-if="group.title" class="closing-group">{{ group.title }}</p>
          <table>
            <thead>
              <tr>
                <th>Документ</th><th>Номер, дата</th><th>Исполнитель</th><th>Заказчик</th>
                <th class="num">Сумма, ₽</th><th class="num">НДС, ₽</th><th>Оплата</th><th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="doc in group.rows" :key="doc.id">
                <td><strong>{{ doc.label }}</strong><small v-if="doc.basis">{{ doc.basis }}</small></td>
                <td>{{ doc.number }}<small>{{ formatDate(doc.date) }}</small></td>
                <td>{{ doc.seller }}</td>
                <td>{{ doc.buyer }}</td>
                <td class="num">{{ money(doc.amount) }}</td>
                <td class="num">{{ doc.vatAmount ? money(doc.vatAmount) : 'без НДС' }}</td>
                <td>
                  <span v-if="doc.paidAt" class="closing-state done">Оплачен {{ formatDate(doc.paidAt) }}</span>
                  <span v-else-if="doc.type.endsWith('invoice')" class="closing-state">Не оплачен</span>
                </td>
                <td><button type="button" class="ghost-btn" :disabled="busy" @click="download(doc)">PDF</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>

    <!-- Взаиморасчёты по счетам -->
    <div v-if="showSettlements" class="closing-block">
      <strong>Счета по заявке</strong>
      <div class="table-wrap closing-docs">
        <table>
          <thead>
            <tr>
              <th>Счёт</th><th>Плательщик → получатель</th>
              <th class="num">Сумма, ₽</th><th class="num">Оплачено, ₽</th><th>Статус</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="doc in invoices" :key="doc.id">
              <td><strong>{{ doc.number }}</strong><small>от {{ formatDate(doc.date) }}</small></td>
              <td>{{ doc.buyer }} → {{ doc.seller }}</td>
              <td class="num">{{ money(doc.amount) }}</td>
              <td class="num">{{ doc.paidAt ? money(doc.paidAmount ?? doc.amount) : '—' }}</td>
              <td>
                <span v-if="doc.paidAt" class="closing-state done">Оплачен {{ formatDate(doc.paidAt) }}</span>
                <span v-else class="closing-state">Не оплачен</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Отметка об оплате -->
    <form v-if="showPayment" class="closing-block closing-payment" @submit.prevent="markPaid">
      <strong>{{ paymentTitle }} по счёту {{ payableInvoice.number }} на {{ money(payableInvoice.amount) }} ₽</strong>
      <div class="form-grid">
        <label>Дата оплаты<input v-model="payment.paidAt" type="date" :max="today()" required :disabled="busy" /></label>
        <label>Сумма, ₽<input v-model="payment.amount" type="number" min="0" step="0.01" required :disabled="busy" /></label>
        <label class="full">Комментарий (необязательно)<input v-model="payment.comment" :disabled="busy" placeholder="Номер платёжного поручения" /></label>
      </div>
      <div class="closing-actions">
        <button class="primary-btn" :disabled="busy">Принять оплату</button>
        <button
          type="button"
          class="ghost-btn danger-btn"
          :disabled="busy || !payableInvoice.paidAt"
          :title="payableInvoice.paidAt ? '' : 'Оплата ещё не принята'"
          @click="deletePayment"
        >
          Удалить оплату
        </button>
        <span v-if="payableInvoice.paidAt" class="closing-state done">
          Принято: {{ formatDate(payableInvoice.paidAt) }}, {{ money(payableInvoice.paidAmount) }} ₽ — нажмите «Далее по маршруту»
        </span>
      </div>
    </form>

    <p v-if="error" class="error-message">{{ error }}</p>
  </section>
</template>

<style scoped>
.closing-panel { display: grid; gap: 14px; }
.closing-block { display: grid; gap: 10px; padding: 12px 14px; border: 1px solid var(--line); border-radius: 8px; background: #fbfcfe; }
.closing-head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.closing-head > div { display: grid; }
.closing-head > :first-child { margin-right: auto; }
.closing-head small, td small, dd small { display: block; color: var(--muted); font-size: 12px; }
.closing-state { padding: 4px 10px; border-radius: 999px; background: #fff5de; color: #815c18; font-size: 12px; font-weight: 650; white-space: nowrap; }
.closing-state.done { background: #e8eef7; color: #173863; }
.closing-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 8px 16px; margin: 0; }
.closing-facts dt { font-size: 12px; color: var(--muted); }
.closing-facts dd { margin: 2px 0 0; font-weight: 600; }
.closing-sign { display: grid; gap: 10px; }
.closing-sign p { margin: 0; font-size: 13px; }
.closing-panel-act { padding: 0; border: 0; background: none; }
.closing-panel-act .closing-block { padding: 20px; border: 1px solid #c0d5f0; border-radius: 12px; background: #fff; }
.closing-head h3 { margin: 0; }
.closing-sign-row, .closing-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.closing-upload { position: relative; cursor: pointer; }
.closing-upload input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.closing-check { display: flex; align-items: center; gap: 10px; font-weight: 600; }
.closing-check input { flex: none; width: 18px; height: 18px; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
.closing-sign-row select { flex: 1; min-width: 220px; }
.closing-group { margin: 4px 0 6px; font-size: 13px; font-weight: 700; color: var(--brand-navy); }
.closing-docs table { min-width: 760px; }
.closing-docs .num { text-align: right; white-space: nowrap; }
.closing-payment .form-grid { grid-template-columns: repeat(2, minmax(0, 220px)); }
</style>
