<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { marketplaceApi } from '../../api/marketplace.js';
import { formatDateTime, money } from '../../data/permissions.js';

/*
 * Отчёт: ЗР на этапах «Подтверждение оплаты Заказчика», «Оплата в СТО»,
 * «Заявка на ремонт закрыта». Набор ЗР и колонок определяет сервер по ЛК:
 * УК — все Заказчики, СТО — только своё СТО, Заказчик — только свои ЗР.
 * Фильтры — по всем полям, доступным в этом ЛК.
 */

const props = defineProps({
  user: { type: Object, required: true },
});

const emit = defineEmits(['open']);

const rows = ref([]);
const stages = ref([]);
const loading = ref(false);
const error = ref('');

const emptyFilters = () => ({
  number: '',
  issuedFrom: '',
  issuedTo: '',
  closedFrom: '',
  closedTo: '',
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
const showCustomer = computed(() => !isStation.value);
const showStation = computed(() => true);
const showDiscount = computed(() => !isCustomer.value);
const showCustomerPayment = computed(() => !isStation.value);
const showStationPayment = computed(() => !isCustomer.value);

const options = computed(() => {
  const unique = (key) => [...new Set(rows.value.map((row) => row[key]).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'ru'));

  return {
    customers: unique('customer'),
    stations: unique('station'),
    reasons: unique('reason'),
  };
});

function dateOnly(value) {
  return value ? String(value).slice(0, 10) : '';
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
    && inRange(row.issuedAt, filters.issuedFrom, filters.issuedTo)
    && inRange(row.closedAt, filters.closedFrom, filters.closedTo)
    && (filters.stage === '' || row.stage === Number(filters.stage))
    && (!filters.customer || row.customer === filters.customer)
    && (!filters.station || row.station === filters.station)
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

    <form class="report-filters" @submit.prevent>
      <label>№ ЗР<input v-model="filters.number" placeholder="ZR-000001" /></label>
      <label>Этап
        <select v-model="filters.stage">
          <option value="">Все</option>
          <option v-for="stage in stages" :key="stage.value" :value="stage.value">{{ stage.label }}</option>
        </select>
      </label>
      <label v-if="showCustomer && !isCustomer">Заказчик
        <select v-model="filters.customer">
          <option value="">Все</option>
          <option v-for="item in options.customers" :key="item" :value="item">{{ item }}</option>
        </select>
      </label>
      <label v-if="showStation && !isStation">СТО
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
      <label>Выдача ТС с<input v-model="filters.issuedFrom" type="date" /></label>
      <label>по<input v-model="filters.issuedTo" type="date" /></label>
      <label>Закрыта с<input v-model="filters.closedFrom" type="date" /></label>
      <label>по<input v-model="filters.closedTo" type="date" /></label>
      <label>Сумма от, ₽<input v-model="filters.amountFrom" type="number" min="0" /></label>
      <label>до, ₽<input v-model="filters.amountTo" type="number" min="0" /></label>
      <label v-if="showCustomerPayment">Оплата Заказчика
        <select v-model="filters.customerPaid">
          <option value="">Все</option><option value="paid">Оплачено</option><option value="unpaid">Не оплачено</option>
        </select>
      </label>
      <label v-if="showStationPayment">Оплата в СТО
        <select v-model="filters.stationPaid">
          <option value="">Все</option><option value="paid">Оплачено</option><option value="unpaid">Не оплачено</option>
        </select>
      </label>
      <div class="report-filter-actions">
        <button type="button" class="ghost-btn" :disabled="!filtersApplied" @click="reset">Сбросить фильтры</button>
      </div>
    </form>

    <p v-if="error" class="error-message">{{ error }}</p>

    <div class="registry-wrap">
      <table class="registry report-table">
        <thead>
          <tr>
            <th>№ ЗР</th>
            <th>Этап</th>
            <th v-if="showCustomer">Заказчик</th>
            <th>СТО</th>
            <th>ТС</th>
            <th>Выдача ТС</th>
            <th class="align-right">Сумма, ₽</th>
            <th v-if="showDiscount" class="align-right">Агентская скидка, ₽</th>
            <th v-if="showDiscount" class="align-right">К оплате СТО, ₽</th>
            <th v-if="showCustomerPayment">Оплата Заказчика</th>
            <th v-if="showStationPayment">Оплата в СТО</th>
            <th>Закрыта</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in filtered" :key="row.id" @dblclick="emit('open', row)">
            <td><strong>{{ row.number }}</strong><small>{{ row.reason }}</small></td>
            <td>{{ row.status }}</td>
            <td v-if="showCustomer">{{ row.customer }}</td>
            <td>{{ row.station }}</td>
            <td>{{ row.vehicle }}<small>{{ row.plate }}</small></td>
            <td>{{ formatDate(row.issuedAt) }}</td>
            <td class="align-right">{{ money(row.amount) }}</td>
            <td v-if="showDiscount" class="align-right">
              {{ money(row.agentDiscount) }}<small v-if="row.agentDiscountPercent != null">{{ row.agentDiscountPercent }}%</small>
            </td>
            <td v-if="showDiscount" class="align-right">{{ money(row.stationAmount) }}</td>
            <td v-if="showCustomerPayment">{{ formatDate(row.customerPaidAt) }}<small>{{ row.customerInvoice }}</small></td>
            <td v-if="showStationPayment">{{ formatDate(row.stationPaidAt) }}<small>{{ row.stationInvoice }}</small></td>
            <td>{{ formatDate(row.closedAt) }}</td>
          </tr>
          <tr v-if="!filtered.length">
            <td :colspan="12" class="muted">{{ loading ? 'Загружаем…' : 'Нет заявок по выбранным условиям' }}</td>
          </tr>
        </tbody>
        <tfoot v-if="filtered.length">
          <tr>
            <th :colspan="showCustomer ? 6 : 5">Итого: {{ filtered.length }} ЗР</th>
            <th class="align-right">{{ money(totals.amount) }}</th>
            <th v-if="showDiscount" class="align-right">{{ money(totals.discount) }}</th>
            <th v-if="showDiscount" class="align-right">{{ money(totals.station) }}</th>
            <th v-if="showCustomerPayment"></th>
            <th v-if="showStationPayment"></th>
            <th></th>
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
  background: #fbfefc;
}
.report-filters label { display: grid; gap: 4px; font-size: 12px; color: var(--muted); font-weight: 600; }
.report-filters input, .report-filters select { min-height: 34px; padding: 6px 8px; font-size: 13px; }
.report-filter-actions { display: flex; align-items: flex-end; }
.report-table tfoot th { background: #f4fbf7; color: var(--brand-navy); font-size: 13px; }
.report-hint { margin: 0; font-size: 12px; }
</style>
