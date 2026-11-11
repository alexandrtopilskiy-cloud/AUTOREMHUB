<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import FormAlert from "../../components/FormAlert.vue";
import { marketplaceApi } from '../../api/marketplace.js';
import { formatDateTime, money } from '../../data/permissions.js';

/*
 * Отчёт: ЗР на этапах «Подтверждение оплаты Заказчика», «Оплата в СТО»,
 * «Заявка на ремонт закрыта». Набор ЗР и колонок определяет сервер по ЛК:
 * УК — все Заказчики, СТО — только своё СТО, Заказчик — только свои ЗР.
 * Состав и порядок столбцов задан для каждого ЛК (REPORT_COLUMNS), фильтры —
 * по полям, которые видны в этом ЛК.
 */

const props = defineProps({
  user: { type: Object, required: true },
});

const emit = defineEmits(['open']);

const rows = ref([]);
const stages = ref([]);
const loading = ref(false);
const error = ref('');

/* Столбцы отчёта по ЛК, слева направо. */
const COLUMN_SETS = {
  customer: ['number', 'vehicle', 'transferredAt', 'issuedAt', 'amount'],
  manager: [
    'number', 'customer', 'station', 'vehicle', 'transferredAt', 'issuedAt',
    'amount', 'customerPaid', 'stationAmount', 'stationPaid', 'agentDiscount', 'stage',
  ],
  station: [
    'number', 'vehicle', 'transferredAt', 'issuedAt',
    'amount', 'customerPaid', 'stationAmount', 'stationPaid', 'agentDiscount', 'stage',
  ],
};

const COLUMN_LABELS = {
  number: '№ ЗР',
  customer: 'Заказчик',
  station: 'СТО',
  vehicle: 'ТС',
  transferredAt: 'Передано на СТО',
  issuedAt: 'Выдано с СТО',
  amount: 'Сумма ЗР (Заказчик), ₽',
  customerPaid: 'Оплата Заказчика',
  stationAmount: 'Сумма ЗР (СТО), ₽',
  stationPaid: 'Оплата СТО',
  agentDiscount: 'Сумма Агентского, ₽',
  stage: 'Этап',
};

const MONEY_COLUMNS = ['amount', 'stationAmount', 'agentDiscount'];

const emptyFilters = () => ({
  number: '',
  transferredFrom: '',
  transferredTo: '',
  issuedFrom: '',
  issuedTo: '',
  stage: '',
  customer: '',
  station: '',
  vehicle: '',
  reason: '',
  customerPaid: '',
  stationPaid: '',
  amountFrom: '',
  amountTo: '',
});
const filters = reactive(emptyFilters());

const role = computed(() => props.user.role);
const isManager = computed(() => role.value === 'manager');
const isStation = computed(() => role.value === 'station');
const isCustomer = computed(() => role.value === 'customer');
const columns = computed(() => COLUMN_SETS[role.value] || COLUMN_SETS.customer);
const has = (key) => columns.value.includes(key);
const showCustomer = computed(() => has('customer'));
const showStation = computed(() => has('station'));
const showStage = computed(() => has('stage'));
const showCustomerPayment = computed(() => has('customerPaid'));
const showStationPayment = computed(() => has('stationPaid'));

function columnLabel(key) {
  if (key === 'amount' && isCustomer.value) return 'Сумма, ₽';

  return COLUMN_LABELS[key];
}

function isMoney(key) {
  return MONEY_COLUMNS.includes(key);
}

/* Колонки до первой денежной — под подпись «Итого». */
const totalsLead = computed(() => columns.value.findIndex(isMoney));

const options = computed(() => {
  const unique = (key) => [...new Set(rows.value.map((row) => row[key]).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'ru'));

  return {
    customers: unique('customer'),
    stations: unique('station'),
    reasons: unique('reason'),
  };
});

function dateOnly(value) {
  if (!value) return '';
  const text = String(value);
  if (text.length <= 10) return text;
  const date = new Date(text);
  if (Number.isNaN(date.getTime())) return text.slice(0, 10);
  const pad = (number) => String(number).padStart(2, '0');

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function inRange(value, from, to) {
  const date = dateOnly(value);
  if (from && (!date || date < from)) return false;
  if (to && (!date || date > to)) return false;

  return true;
}

function paidMatches(value, filter) {
  if (filter === 'paid') return Boolean(value);
  if (filter === 'unpaid') return !value;

  return true;
}

const filtered = computed(() => rows.value.filter((row) => {
  const text = (value) => String(value || '').toLowerCase();
  const vehicleText = `${row.vehicle} ${row.plate} ${row.vin}`.toLowerCase();

  return (!filters.number || text(row.number).includes(filters.number.toLowerCase().trim()))
    && inRange(row.transferredAt, filters.transferredFrom, filters.transferredTo)
    && inRange(row.issuedAt, filters.issuedFrom, filters.issuedTo)
    && (!showStage.value || filters.stage === '' || row.stage === Number(filters.stage))
    && (!showCustomer.value || !filters.customer || row.customer === filters.customer)
    && (!showStation.value || !filters.station || row.station === filters.station)
    && (!filters.reason || row.reason === filters.reason)
    && (!filters.vehicle || vehicleText.includes(filters.vehicle.toLowerCase().trim()))
    && paidMatches(row.customerPaidAt, showCustomerPayment.value ? filters.customerPaid : '')
    && paidMatches(row.stationPaidAt, showStationPayment.value ? filters.stationPaid : '')
    && (filters.amountFrom === '' || row.amount >= Number(filters.amountFrom))
    && (filters.amountTo === '' || row.amount <= Number(filters.amountTo));
}));

const totals = computed(() => filtered.value.reduce((sum, row) => ({
  amount: sum.amount + Number(row.amount || 0),
  discount: sum.discount + Number(row.agentDiscount || 0),
  station: sum.station + Number(row.stationAmount || 0),
}), { amount: 0, discount: 0, station: 0 }));

const filtersApplied = computed(() => Object.entries(filters).some(([, value]) => String(value) !== ''));

function reset() {
  Object.assign(filters, emptyFilters());
}

/* Дата и время по местному времени; для «голой» даты — только дата. */
function formatMoment(value) {
  if (!value) return '—';
  if (String(value).length <= 10) return formatDate(value);

  return formatDateTime(value);
}

function formatDate(value) {
  if (!value) return '—';
  if (String(value).length > 10) return formatDateTime(value).slice(0, 10);
  const [year, month, day] = String(value).split('-');

  return `${day}.${month}.${year}`;
}

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const data = await marketplaceApi.reportRequests();
    rows.value = data.rows || [];
    stages.value = data.stages || [];
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <section class="report">
    <header class="report-head">
      <div>
        <h2>Отчёт по заявкам на ремонт</h2>
        <p class="muted">
          Заявки на этапах «Подтверждение оплаты Заказчика», «Оплата в СТО» и
          «Заявка на ремонт закрыта»<template v-if="isManager"> по всем Заказчикам</template><template v-else-if="isStation"> по вашему СТО</template><template v-else> вашей организации</template>.
        </p>
      </div>
      <button type="button" class="ghost-btn" :disabled="loading" @click="load">Обновить</button>
    </header>
    <FormAlert v-if="error" class="error-message">{{ error }}</FormAlert>

    <form class="report-filters" @submit.prevent>
      <label>№ ЗР<input v-model="filters.number" placeholder="ZR-000001" /></label>
      <label v-if="showCustomer">Заказчик
        <select v-model="filters.customer">
          <option value="">Все</option>
          <option v-for="item in options.customers" :key="item" :value="item">{{ item }}</option>
        </select>
      </label>
      <label v-if="showStation">СТО
        <select v-model="filters.station">
          <option value="">Все</option>
          <option v-for="item in options.stations" :key="item" :value="item">{{ item }}</option>
        </select>
      </label>
      <label>ТС<input v-model="filters.vehicle" placeholder="Марка, модель, госномер, VIN" /></label>
      <label>Причина обращения
        <select v-model="filters.reason">
          <option value="">Все</option>
          <option v-for="item in options.reasons" :key="item" :value="item">{{ item }}</option>
        </select>
      </label>
      <label>Передано на СТО с<input v-model="filters.transferredFrom" type="date" /></label>
      <label>по<input v-model="filters.transferredTo" type="date" /></label>
      <label>Выдано с СТО с<input v-model="filters.issuedFrom" type="date" /></label>
      <label>по<input v-model="filters.issuedTo" type="date" /></label>
      <label>Сумма от, ₽<input v-model="filters.amountFrom" type="number" min="0" /></label>
      <label>до, ₽<input v-model="filters.amountTo" type="number" min="0" /></label>
      <label v-if="showCustomerPayment">Оплата Заказчика
        <select v-model="filters.customerPaid">
          <option value="">Все</option><option value="paid">Оплачено</option><option value="unpaid">Не оплачено</option>
        </select>
      </label>
      <label v-if="showStationPayment">Оплата СТО
        <select v-model="filters.stationPaid">
          <option value="">Все</option><option value="paid">Оплачено</option><option value="unpaid">Не оплачено</option>
        </select>
      </label>
      <label v-if="showStage">Этап
        <select v-model="filters.stage">
          <option value="">Все</option>
          <option v-for="stage in stages" :key="stage.value" :value="stage.value">{{ stage.label }}</option>
        </select>
      </label>
      <div class="report-filter-actions">
        <button type="button" class="ghost-btn" :disabled="!filtersApplied" @click="reset">Сбросить фильтры</button>
      </div>
    </form>


    <div class="registry-wrap">
      <table class="registry report-table">
        <thead>
          <tr>
            <th v-for="key in columns" :key="key" :class="{ 'align-right': isMoney(key) }">{{ columnLabel(key) }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in filtered" :key="row.id" @dblclick="emit('open', row)">
            <td v-for="key in columns" :key="key" :class="{ 'align-right': isMoney(key) }">
              <template v-if="key === 'number'"><strong>{{ row.number }}</strong><small>{{ row.reason }}</small></template>
              <template v-else-if="key === 'customer'">{{ row.customer }}</template>
              <template v-else-if="key === 'station'">{{ row.station }}</template>
              <template v-else-if="key === 'vehicle'">{{ row.vehicle }}<small>{{ row.plate }}</small></template>
              <template v-else-if="key === 'transferredAt'">{{ formatMoment(row.transferredAt) }}</template>
              <template v-else-if="key === 'issuedAt'">{{ formatMoment(row.issuedAt) }}</template>
              <template v-else-if="key === 'amount'">{{ money(row.amount) }}</template>
              <template v-else-if="key === 'customerPaid'">{{ formatDate(row.customerPaidAt) }}<small v-if="row.customerInvoice">{{ row.customerInvoice }}</small></template>
              <template v-else-if="key === 'stationAmount'">{{ money(row.stationAmount) }}</template>
              <template v-else-if="key === 'stationPaid'">{{ formatDate(row.stationPaidAt) }}<small v-if="row.stationInvoice">{{ row.stationInvoice }}</small></template>
              <template v-else-if="key === 'agentDiscount'">{{ money(row.agentDiscount) }}<small v-if="row.agentDiscountPercent != null">{{ row.agentDiscountPercent }}%</small></template>
              <template v-else-if="key === 'stage'">{{ row.status }}</template>
            </td>
          </tr>
          <tr v-if="!filtered.length">
            <td :colspan="columns.length" class="muted">{{ loading ? 'Загружаем…' : 'Нет заявок по выбранным условиям' }}</td>
          </tr>
        </tbody>
        <tfoot v-if="filtered.length">
          <tr>
            <th :colspan="totalsLead">Итого: {{ filtered.length }} ЗР</th>
            <th v-for="key in columns.slice(totalsLead)" :key="key" :class="{ 'align-right': isMoney(key) }">
              <template v-if="key === 'amount'">{{ money(totals.amount) }}</template>
              <template v-else-if="key === 'stationAmount'">{{ money(totals.station) }}</template>
              <template v-else-if="key === 'agentDiscount'">{{ money(totals.discount) }}</template>
            </th>
          </tr>
        </tfoot>
      </table>
    </div>
    <p class="muted report-hint">Двойной щелчок по строке открывает заявку.</p>
  </section>
</template>

<style scoped>
.report { display: grid; gap: 14px; padding: 16px 18px; min-width: 0; }
.report > * { min-width: 0; }
.report-head { display: flex; align-items: flex-start; gap: 12px; }
.report-head > div { margin-right: auto; }
.report-head h2 { margin: 0 0 4px; font-size: 18px; color: var(--brand-navy); }
.report-head p { margin: 0; }
.report-filters {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  gap: 10px 12px;
  padding: 12px 14px;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: #fbfcfe;
}
.report-filters label { display: grid; gap: 4px; font-size: 12px; color: var(--muted); font-weight: 600; }
.report-filters input, .report-filters select { min-height: 34px; padding: 6px 8px; font-size: 13px; }
.report-filter-actions { display: flex; align-items: flex-end; }
.report-table tfoot th { background: #f4f7fb; color: var(--brand-navy); font-size: 13px; }
.report-hint { margin: 0; font-size: 12px; }
</style>
