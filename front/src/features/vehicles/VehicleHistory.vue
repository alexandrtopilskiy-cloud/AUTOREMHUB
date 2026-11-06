<script setup>
import { ref, watch } from 'vue';
import { marketplaceApi } from '../../api/marketplace.js';
import { formatDateTime, money } from '../../data/permissions.js';

/* ─── Props ─── */

const props = defineProps({
  vehicleId: {
    type: String,
    required: true,
  },
  /** Встроено во вкладку ЗР «История ТО и работ»: без собственной панели. */
  embedded: {
    type: Boolean,
    default: false,
  },
});

/* ─── Состояние ─── */

const result = ref(null);
const error = ref('');
const loading = ref(false);

/* ─── Отмена устаревших запросов ─── */

let sequence = 0;

/* ─── Загрузка статистики ─── */

async function load() {
  const version = ++sequence;

  loading.value = true;
  error.value = '';

  try {
    const data = await marketplaceApi.vehicleStatistics(props.vehicleId);

    if (version === sequence) {
      result.value = data;
    }
  } catch (e) {
    if (version === sequence) {
      error.value = e.message;
    }
  } finally {
    if (version === sequence) {
      loading.value = false;
    }
  }
}

/* ─── Реакция на смену автомобиля ─── */

watch(
  () => props.vehicleId,
  () => {
    load();
  },
  { immediate: true },
);

/* ─── Хелперы ─── */

function previousMileage(value) {
  return value ?? 'нет данных';
}

function actualHours(value) {
  return value ?? '—';
}
</script>

<template>
  <section :class="embedded ? 'vehicle-history-embedded' : 'document-panel'">
    <h1 v-if="!embedded">История автомобиля</h1>
    <div v-else class="section-title">
      <h3>История ТО и работ</h3>
    </div>

    <p v-if="loading">Загрузка…</p>
    <p v-if="error" class="error-message">{{ error }}</p>

    <template v-if="result">
      <dl v-if="embedded" class="vehicle-history-summary">
        <div>
          <dt>Автомобиль</dt>
          <dd>{{ result.vehicle.vehicle }} · {{ result.vehicle.plate }}</dd>
        </div>
        <div>
          <dt>VIN</dt>
          <dd>{{ result.vehicle.vin }}</dd>
        </div>
        <div>
          <dt>Пробег на последнем ТО, км</dt>
          <dd>{{ previousMileage(result.vehicle.previousServiceMileage) }}</dd>
        </div>
        <div>
          <dt>Выполнено работ</dt>
          <dd>{{ result.statistics.completedWorks }}</dd>
        </div>
        <div>
          <dt>Посещений ТО</dt>
          <dd>{{ result.statistics.maintenanceVisits }}</dd>
        </div>
      </dl>

      <template v-else>
        <h2>{{ result.vehicle.vehicle }} · {{ result.vehicle.plate }}</h2>
        <p>{{ result.vehicle.vin }}</p>

        <p>
          Пробег на последнем ТО:
          <strong>{{ previousMileage(result.vehicle.previousServiceMileage) }}</strong>
        </p>

        <p>
          Работ: {{ result.statistics.completedWorks }}
          · Посещений ТО: {{ result.statistics.maintenanceVisits }}
        </p>
      </template>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Дата</th>
              <th>Пробег</th>
              <th>Работа</th>
              <th>Количество</th>
              <th>Цена</th>
              <th>Факт, ч</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in result.statistics.history"
              :key="row.id"
            >
              <td>{{ formatDateTime(row.performedAt) }}</td>
              <td>{{ row.mileage }}</td>
              <td>{{ row.work }}</td>
              <td>{{ row.qty }}</td>
              <td>{{ money(row.price) }}</td>
              <td>{{ actualHours(row.actualHours) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-if="!result.statistics.history.length" class="muted">
        Выполненных работ по закрытым заявкам пока нет.
      </p>
    </template>
  </section>
</template>
