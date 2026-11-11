<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import FormAlert from "../../components/FormAlert.vue";
import { marketplaceApi } from '../../api/marketplace.js';
import { money } from '../../data/permissions.js';

/*
 * Отчёт «Движение денежных средств» (ДДС) организации пользователя.
 * Источники: оплаты во «Взаиморасчётах» ЗР (оплата Заказчика, оплата СТО)
 * и оплаты по Приходам / Реализациям склада. Сервер отдаёт все движения
 * в рамках ЛК, фронт применяет фильтры и считает сальдо:
 *   сальдо на начало = поступления − расход по движениям до начала периода
 *   (с теми же фильтрами), сальдо на конец = начало + поступления − расход.
 */

const props = defineProps({
  user: { type: Object, required: true },
});

const emit = defineEmits(['open']);

const rows = ref([]);
const articles = ref([]);
const loading = ref(false);
const error = ref('');

const pad = (number) => String(number).padStart(2, '0');
const isoDate = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

function monthRange(offset = 0) {
  const now = new Date();
  const start = new Date(now.getFullYear(), now.getMonth() + offset, 1);
  const end = new Date(now.getFullYear(), now.getMonth() + offset + 1, 0);

  return { from: isoDate(start), to: isoDate(end) };
}

const PERIODS = {
  month: () => monthRange(0),
  prevMonth: () => monthRange(-1),
  quarter: () => {
    const now = new Date();
    const first = Math.floor(now.getMonth() / 3) * 3;

    return { from: isoDate(new Date(now.getFullYear(), first, 1)), to: isoDate(new Date(now.getFullYear(), first + 3, 0)) };
  },
  year: () => {
    const year = new Date().getFullYear();

    return { from: `${year}-01-01`, to: `${year}-12-31` };
  },
  all: () => ({ from: '', to: '' }),
};

const PERIOD_LABELS = {
  month: 'Текущий месяц',
  prevMonth: 'Прошлый месяц',
  quarter: 'Квартал',
  year: 'Год',
  all: 'Весь период',
};

const emptyFilters = () => ({
  ...PERIODS.month(),
  customer: '',
  station: '',
  counterparty: '',
  article: '',
  direction: '',
  search: '',
});
const filters = reactive(emptyFilters());

const role = computed(() => props.user.role);
const isManager = computed(() => role.value === 'manager');
const isStation = computed(() => role.value === 'station');

const options = computed(() => {
  const unique = (key) => [...new Set(rows.value.map((row) => row[key]).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'ru'));

  return {
    customers: unique('customer'),
    stations: unique('station'),
    counterparties: unique('counterparty'),
  };
});

/* Заказчик — в ЛК УК и СТО (покупатели Реализаций), СТО — в ЛК УК и Заказчика. */
const showCustomer = computed(() => !['customer'].includes(role.value) && options.value.customers.length > 0);
const showStation = computed(() => !isStation.value && options.value.stations.length > 0);

/* Все фильтры, кроме периода. */
function matches(row) {
  const search = filters.search.toLowerCase().trim();
  const haystack = `${row.document} ${row.requestNumber || ''} ${row.vehicle} ${row.comment}`.toLowerCase();

  return (!filters.customer || row.customer === filters.customer)
    && (!filters.station || row.station === filters.station)
    && (!filters.counterparty || row.counterparty === filters.counterparty)
    && (!filters.article || row.article === filters.article)
    && (!filters.direction || row.direction === filters.direction)
    && (!search || haystack.includes(search));
}

const signed = (row) => (row.direction === 'in' ? 1 : -1) * Number(row.amount || 0);
const round = (value) => Math.round(value * 100) / 100;

const scoped = computed(() => rows.value.filter(matches));

const opening = computed(() => round(scoped.value
  .filter((row) => filters.from && row.date < filters.from)
  .reduce((sum, row) => sum + signed(row), 0)));

const period = computed(() => {
  let balance = opening.value;

  return scoped.value
    .filter((row) => (!filters.from || row.date >= filters.from) && (!filters.to || row.date <= filters.to))
    .map((row) => {
      balance = round(balance + signed(row));

      return { ...row, balance };
    });
});

const totals = computed(() => period.value.reduce((sum, row) => ({
  income: round(sum.income + (row.direction === 'in' ? Number(row.amount) : 0)),
  expense: round(sum.expense + (row.direction === 'out' ? Number(row.amount) : 0)),
}), { income: 0, expense: 0 }));

const closing = computed(() => round(opening.value + totals.value.income - totals.value.expense));

/* Свод по статьям за период. */
const byArticle = computed(() => articles.value
  .map((article) => {
    const items = period.value.filter((row) => row.article === article.value);

    return {
      ...article,
      count: items.length,
      income: round(items.filter((row) => row.direction === 'in').reduce((sum, row) => sum + Number(row.amount), 0)),
      expense: round(items.filter((row) => row.direction === 'out').reduce((sum, row) => sum + Number(row.amount), 0)),
    };
  })
  .filter((article) => article.count > 0));

const activePeriod = computed(() => Object.keys(PERIODS).find((key) => {
  const range = PERIODS[key]();

  return range.from === filters.from && range.to === filters.to;
}) || '');

const filtersApplied = computed(() => {
  const initial = emptyFilters();

  return Object.keys(initial).some((key) => filters[key] !== initial[key]);
});

function setPeriod(key) {
  Object.assign(filters, PERIODS[key]());
}

function reset() {
  Object.assign(filters, emptyFilters());
}

function formatDate(value) {
  if (!value) return '—';
  const [year, month, day] = String(value).slice(0, 10).split('-');

  return `${day}.${month}.${year}`;
}

const periodLabel = computed(() => {
  if (!filters.from && !filters.to) return 'за весь период';
  if (!filters.from) return `по ${formatDate(filters.to)}`;
  if (!filters.to) return `с ${formatDate(filters.from)}`;

  return `с ${formatDate(filters.from)} по ${formatDate(filters.to)}`;
});

const canOpen = (row) => Boolean(row.requestId || (row.stockDocumentId && (isManager.value || isStation.value)));

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const data = await marketplaceApi.reportCashFlow();
    rows.value = data.rows || [];
    articles.value = data.articles || [];
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <section class="report cash-flow">
    <header class="report-head">
      <div>
        <h2>Движение денежных средств (ДДС)</h2>
        <p class="muted">
          Оплаты из «Взаиморасчётов» заявок на ремонт<template v-if="isManager || isStation"> и оплаты по Приходам и Реализациям склада</template><template v-else> и оплаты по Реализациям ЗЧ</template>
          {{ periodLabel }}.
        </p>
      </div>
      <button type="button" class="ghost-btn" :disabled="loading" @click="load">Обновить</button>
    </header>
    <FormAlert v-if="error" class="error-message">{{ error }}</FormAlert>

    <form class="report-filters" @submit.prevent>
      <div class="period-presets" role="group" aria-label="Период">
        <button
          v-for="(label, key) in PERIOD_LABELS"
          :key="key"
          type="button"
          class="preset-btn"
          :class="{ active: activePeriod === key }"
          @click="setPeriod(key)"
        >{{ label }}</button>
      </div>
      <label>Период с<input v-model="filters.from" type="date" /></label>
      <label>по<input v-model="filters.to" type="date" /></label>
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
      <label>Контрагент
        <select v-model="filters.counterparty">
          <option value="">Все</option>
          <option v-for="item in options.counterparties" :key="item" :value="item">{{ item }}</option>
        </select>
      </label>
      <label>Статья
        <select v-model="filters.article">
          <option value="">Все</option>
          <option v-for="item in articles" :key="item.value" :value="item.value">{{ item.label }}</option>
        </select>
      </label>
      <label>Движение
        <select v-model="filters.direction">
          <option value="">Все</option>
          <option value="in">Поступление</option>
          <option value="out">Расход</option>
        </select>
      </label>
      <label>Документ, ЗР, ТС<input v-model="filters.search" placeholder="SCH-, PRH-, ZR-, госномер" /></label>
      <div class="report-filter-actions">
        <button type="button" class="ghost-btn" :disabled="!filtersApplied" @click="reset">Сбросить фильтры</button>
      </div>
    </form>


    <div class="cash-summary">
      <div class="cash-card">
        <span>Сальдо на начало</span>
        <strong>{{ money(opening) }} ₽</strong>
        <small>{{ filters.from ? 'на ' + formatDate(filters.from) : 'начало учёта' }}</small>
      </div>
      <div class="cash-card income">
        <span>Поступление</span>
        <strong>+ {{ money(totals.income) }} ₽</strong>
        <small>{{ period.filter((row) => row.direction === 'in').length }} опер.</small>
      </div>
      <div class="cash-card expense">
        <span>Расход</span>
        <strong>− {{ money(totals.expense) }} ₽</strong>
        <small>{{ period.filter((row) => row.direction === 'out').length }} опер.</small>
      </div>
      <div class="cash-card closing" :class="{ negative: closing < 0 }">
        <span>Сальдо на конец</span>
        <strong>{{ money(closing) }} ₽</strong>
        <small>{{ filters.to ? 'на ' + formatDate(filters.to) : 'на сегодня' }}</small>
      </div>
    </div>

    <div v-if="byArticle.length" class="registry-wrap">
      <table class="registry cash-articles">
        <thead>
          <tr>
            <th>Статья</th>
            <th class="align-right">Операций</th>
            <th class="align-right">Поступление, ₽</th>
            <th class="align-right">Расход, ₽</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="article in byArticle" :key="article.value">
            <td>{{ article.label }}</td>
            <td class="align-right">{{ article.count }}</td>
            <td class="align-right income-cell">{{ article.income ? money(article.income) : '—' }}</td>
            <td class="align-right expense-cell">{{ article.expense ? money(article.expense) : '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="registry-wrap">
      <table class="registry report-table cash-table">
        <thead>
          <tr>
            <th>Дата</th>
            <th>Статья</th>
            <th>Документ</th>
            <th>Контрагент</th>
            <th v-if="showCustomer">Заказчик</th>
            <th v-if="showStation">СТО</th>
            <th>Способ</th>
            <th class="align-right">Поступление, ₽</th>
            <th class="align-right">Расход, ₽</th>
            <th class="align-right">Остаток, ₽</th>
          </tr>
        </thead>
        <tbody>
          <tr class="saldo-row">
            <td :colspan="5 + (showCustomer ? 1 : 0) + (showStation ? 1 : 0)">Сальдо на начало периода</td>
            <td></td>
            <td></td>
            <td class="align-right">{{ money(opening) }}</td>
          </tr>
          <tr
            v-for="row in period"
            :key="row.id"
            :class="{ clickable: canOpen(row) }"
            @dblclick="canOpen(row) && emit('open', row)"
          >
            <td>{{ formatDate(row.date) }}</td>
            <td>{{ row.articleLabel }}</td>
            <td><strong>{{ row.document }}</strong><small v-if="row.requestNumber">{{ row.requestNumber }}</small><small v-else-if="row.vehicle">{{ row.vehicle }}</small></td>
            <td>{{ row.counterparty || '—' }}<small v-if="row.comment">{{ row.comment }}</small></td>
            <td v-if="showCustomer">{{ row.customer || '—' }}</td>
            <td v-if="showStation">{{ row.station || '—' }}</td>
            <td>{{ row.method || '—' }}</td>
            <td class="align-right income-cell">{{ row.direction === 'in' ? money(row.amount) : '' }}</td>
            <td class="align-right expense-cell">{{ row.direction === 'out' ? money(row.amount) : '' }}</td>
            <td class="align-right" :class="{ 'negative-cell': row.balance < 0 }">{{ money(row.balance) }}</td>
          </tr>
          <tr v-if="!period.length">
            <td :colspan="8 + (showCustomer ? 1 : 0) + (showStation ? 1 : 0)" class="muted">{{ loading ? 'Загружаем…' : 'Нет движений денежных средств за выбранный период' }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <th :colspan="5 + (showCustomer ? 1 : 0) + (showStation ? 1 : 0)">Итого за период: {{ period.length }} опер. · сальдо на конец</th>
            <th class="align-right">{{ money(totals.income) }}</th>
            <th class="align-right">{{ money(totals.expense) }}</th>
            <th class="align-right">{{ money(closing) }}</th>
          </tr>
        </tfoot>
      </table>
    </div>
    <p class="muted report-hint">
      Сальдо на начало считается по всем оплатам в системе до начала периода с учётом выбранных фильтров.
      Двойной щелчок по строке открывает заявку<template v-if="isManager || isStation"> или складской документ</template>.
    </p>
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
.period-presets { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 6px; }
.preset-btn {
  border: 1px solid var(--line);
  background: #fff;
  color: #32455d;
  border-radius: 999px;
  padding: 4px 12px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}
.preset-btn:hover { border-color: var(--brand-teal-dark); }
.preset-btn.active { background: var(--brand-navy); border-color: var(--brand-navy); color: #fff; }
.cash-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px; }
.cash-card {
  display: grid;
  gap: 2px;
  padding: 12px 14px;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: #fff;
  box-shadow: inset 4px 0 var(--brand-navy);
}
.cash-card span { font-size: 12px; font-weight: 650; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; }
.cash-card strong { font-size: 20px; color: var(--brand-navy); font-variant-numeric: tabular-nums; }
.cash-card small { font-size: 12px; color: var(--muted); }
.cash-card.income { box-shadow: inset 4px 0 #1f8a4c; }
.cash-card.income strong { color: #1f7a45; }
.cash-card.expense { box-shadow: inset 4px 0 var(--brand-orange); }
.cash-card.expense strong { color: var(--brand-orange-dark); }
.cash-card.closing { background: var(--brand-teal-soft); }
.cash-card.negative strong { color: #c0392b; }
.income-cell { color: #1f7a45; font-variant-numeric: tabular-nums; }
.expense-cell { color: var(--brand-orange-dark); font-variant-numeric: tabular-nums; }
.negative-cell { color: #c0392b; }
.saldo-row td { background: #f4f7fb; font-weight: 650; color: var(--brand-navy); }
.cash-articles { max-width: 720px; }
.cash-table tr.clickable { cursor: pointer; }
.report-table tfoot th { background: #f4f7fb; color: var(--brand-navy); font-size: 13px; }
.report-hint { margin: 0; font-size: 12px; }
</style>
