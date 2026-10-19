<script setup>
import { computed, ref } from 'vue';
import { formatDateTime, money } from '../../data/permissions.js';
import ConfirmedDateTimeInput from '../../components/ConfirmedDateTimeInput.vue';

/* ─── Константы ─── */

const PURPOSE_ISSUED_INVOICE = 'issued_invoice';
const PURPOSE_PAID_INVOICE = 'paid_invoice';

const INVOICE_STATUS_LABELS = {
  issued: 'Ожидает оплаты',
  submitted: 'Оплата передана на проверку',
  paid: 'Оплата проверена УК',
};

const INVOICE_STATUS_SUBMITTED = 'submitted';

const PERFORM_ISSUE_INVOICE = 'invoice';
const PERFORM_SUBMIT_PAYMENT = 'payment';
const PERFORM_VERIFY_PAYMENT = 'verifyPayment';

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
});

/* ─── Состояние форм ─── */

const number = ref('');
const issueFile = ref('');
const paidFile = ref('');
const paidAmount = ref(null);
const paidAt = ref('');
const comment = ref('');
const error = ref('');

/* ─── Права ─── */

const canIssueInvoice = computed(
  () => props.request.permissions?.canIssueInvoice === true,
);

const canSubmitPayment = computed(
  () => props.request.permissions?.canSubmitPayment === true,
);

const canVerifyPayment = computed(
  () => props.request.permissions?.canVerifyPayment === true,
);

/* ─── Файлы ─── */

const invoiceFiles = computed(() =>
  (props.request.attachments || []).filter(
    (attachment) => attachment.purpose === PURPOSE_ISSUED_INVOICE,
  ),
);

const paidFiles = computed(() =>
  (props.request.attachments || []).filter(
    (attachment) => attachment.purpose === PURPOSE_PAID_INVOICE,
  ),
);

/* ─── Статус счёта ─── */

const invoiceStatus = computed(() => {
  const status = props.request.invoice?.status;

  return INVOICE_STATUS_LABELS[status] || 'Статус не определён';
});

const showVerifyForm = computed(
  () =>
    canVerifyPayment.value
    && props.request.invoice?.status === INVOICE_STATUS_SUBMITTED,
);

/* ─── Общий запуск команды ─── */

async function run(method, body) {
  error.value = '';

  try {
    await props.perform(method, body);
  } catch (e) {
    error.value = e.message;
  }
}

/* ─── Выставление счёта ─── */

function issue() {
  return run(PERFORM_ISSUE_INVOICE, {
    number: number.value,
    amount: props.request.workOrder.total,
    attachmentId: issueFile.value,
  });
}

/* ─── Подтверждение оплаты ─── */

function payment() {
  if (!paidAt.value) {
    error.value = 'Выберите дату и время оплаты';

    return;
  }

  return run(PERFORM_SUBMIT_PAYMENT, {
    paidAmount: paidAmount.value,
    paidAt: new Date(paidAt.value).toISOString(),
    attachmentId: paidFile.value,
    comment: comment.value,
  });
}

/* ─── Проверка оплаты УК ─── */

function verify() {
  return run(PERFORM_VERIFY_PAYMENT, { comment: comment.value });
}
</script>

<template>
  <section class="feature-card">
    <h3>Заказ-наряд и оплата</h3>

    <!-- Состав заказ-наряда -->
    <template v-if="request.workOrder">
      <p>
        <strong>{{ request.workOrder.number }}</strong>
        · {{ formatDateTime(request.workOrder.completedAt) }}
        · {{ request.workOrder.mileage }} км
      </p>
      <p>
        Выполнено на <strong>{{ money(request.workOrder.total) }} ₽</strong>
      </p>

      <details>
        <summary>Состав заказ-наряда</summary>
        <p
          v-for="(item, index) in request.workOrder.items"
          :key="index"
        >
          {{ item.name }} · {{ item.qty }} × {{ money(item.price) }} ₽
        </p>
        <p>{{ request.workOrder.conclusion }}</p>
      </details>
    </template>

    <p v-else class="muted">
      Заказ-наряд формируется после завершения ремонта СТО.
    </p>

    <!-- Сводка по счёту -->
    <div v-if="request.invoice" class="invoice-summary">
      <strong>
        Счёт {{ request.invoice.number }} · {{ money(request.invoice.amount) }} ₽
      </strong>
      <p>{{ invoiceStatus }}</p>
      <p v-if="request.invoice.paidAt">
        Дата оплаты: {{ formatDateTime(request.invoice.paidAt) }}
      </p>
    </div>

    <!-- Форма выставления счёта -->
    <form
      v-if="canIssueInvoice"
      class="form-grid inset-form"
      @submit.prevent="issue"
    >
      <label>
        Номер счёта
        <input v-model="number" required />
      </label>

      <label>
        Выставленный счёт
        <select v-model="issueFile" required>
          <option value="">Выберите загруженный файл</option>
          <option
            v-for="file in invoiceFiles"
            :key="file.id"
            :value="file.id"
          >
            {{ file.name }}
          </option>
        </select>
      </label>

      <button
        class="primary-btn"
        :disabled="busy || !request.workOrder"
      >
        Выставить счёт на {{ money(request.workOrder?.total) }} ₽
      </button>
    </form>

    <!-- Форма подтверждения оплаты -->
    <form
      v-if="canSubmitPayment"
      class="form-grid inset-form"
      @submit.prevent="payment"
    >
      <label>
        Оплачено, ₽
        <input
          v-model.number="paidAmount"
          type="number"
          step="0.01"
          min="0"
          required
        />
      </label>

      <ConfirmedDateTimeInput
        v-model="paidAt"
        title="Дата оплаты (ваше местное время)"
        :disabled="busy"
      />

      <label class="full">
        Закрытый счёт
        <select v-model="paidFile" required>
          <option value="">Выберите загруженный файл</option>
          <option
            v-for="file in paidFiles"
            :key="file.id"
            :value="file.id"
          >
            {{ file.name }}
          </option>
        </select>
      </label>

      <label class="full">
        Комментарий
        <textarea v-model="comment" required />
      </label>

      <button class="primary-btn" :disabled="busy || !paidAt">
        Сохранить подтверждение оплаты
      </button>
    </form>

    <!-- Форма проверки оплаты УК -->
    <form
      v-if="showVerifyForm"
      class="inset-form"
      @submit.prevent="verify"
    >
      <label>
        Комментарий проверки
        <textarea v-model="comment" required />
      </label>

      <button class="primary-btn" :disabled="busy">
        Подтвердить оплату
      </button>
    </form>

    <p v-if="error" class="error-message">{{ error }}</p>
  </section>
</template>
