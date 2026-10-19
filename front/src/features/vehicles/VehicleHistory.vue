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
  <section class="document-panel">
    <h1>История автомобиля</h1>

    <p v-if="loading">Загрузка…</p>
    <p v-if="error" class="error-message">{{ error }}</p>

    <template v-if="result">
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
        Выполненных работ пока нет.
      </p>
    </template>
  </section>
</template>
