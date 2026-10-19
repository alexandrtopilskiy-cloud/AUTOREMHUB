<script setup>
import { computed, ref, watch } from 'vue';
import { workflow, statusClass } from '../../data/workflow.js';
import {
  money,
  total,
  formatDateTime,
  duration,
  catalogFields,
} from '../../data/permissions.js';

/* ─── Константы ─── */

const KIND_REQUEST = 'request';

const SECTION_DATA = 'data';
const SECTION_HISTORY = 'history';
const SECTION_TIME = 'time';

const SECTIONS = [
  [SECTION_DATA, 'Данные'],
  [SECTION_HISTORY, 'История'],
  [SECTION_TIME, 'Сроки'],
];

const REQUEST_DETAILS = {
  company: 'Заказчик',
  vehicle: 'Автомобиль',
  vin: 'VIN',
  plate: 'Госномер',
  mileage: 'Пробег, км',
  previousServiceMileage: 'Предыдущий пробег, км',
  contact: 'Контактное лицо',
  reason: 'Причина',
  diagnostics: 'Подвид',
  station: 'СТО',
  slot: 'Желаемый визит',
  stationSlot: 'Визит от СТО',
  plannedCompletionAt: 'Расчётное время завершения ремонта',
};

/* ─── Props ─── */

const props = defineProps({
  item: {
    type: Object,
    default: null,
  },
  kind: {
    type: String,
    required: true,
  },
});

/* ─── Состояние ─── */

const section = ref(SECTION_DATA);

watch(
  () => props.item?.id,
  () => {
    section.value = SECTION_DATA;
  },
);

/* ─── Вычисляемые ─── */

const details = computed(() => {
  if (props.kind === KIND_REQUEST) {
    return REQUEST_DETAILS;
  }

  return catalogFields[props.item?.kind] || {};
});

/* ─── Хелперы ─── */

function stageLabel(stage) {
  return workflow[stage]?.status || stage;
}

function displayValue(item, key) {
  const value = item[key];

  if (value === 0) {
    return 0;
  }

  return value || '—';
}

function periodExitedAt(period) {
  return period.exitedAt ? formatDateTime(period.exitedAt) : 'сейчас';
}
</script>

<template>
  <!-- Пустое состояние -->
  <div v-if="!item" class="empty-state">
    <span class="empty-symbol">▤</span>
    <h3>Документ не выбран</h3>
    <p>Нажмите на строку для предпросмотра.</p>
  </div>

  <!-- Предпросмотр -->
  <div v-else class="preview-content">
    <span class="eyebrow">Только просмотр</span>
    <h2>{{ item.number || item.vehicle || item.name }}</h2>

    <template v-if="kind === KIND_REQUEST">
      <span class="status" :class="statusClass(item.stage, item.permissions)">
        {{ item.statusLabel || stageLabel(item.stage) }}
      </span>

      <div class="preview-total">
        <small>Работы и запчасти</small>
        <strong>{{ money(total(item)) }} ₽</strong>
      </div>

      <p v-if="item.overdueSeconds" class="overdue">
        Превышение срока: {{ duration(item.overdueSeconds) }}
      </p>

      <nav class="subtabs">
        <button
          v-for="[key, title] in SECTIONS"
          :key="key"
          :class="{ active: section === key }"
          @click="section = key"
        >
          {{ title }}
        </button>
      </nav>
    </template>

    <!-- Данные -->
    <dl v-if="section === SECTION_DATA" class="detail-list">
      <template v-for="(label, key) in details" :key="key">
        <dt>{{ label }}</dt>
        <dd>{{ displayValue(item, key) }}</dd>
      </template>
    </dl>

    <!-- История -->
    <div v-if="section === SECTION_HISTORY">
      <article
        v-for="event in item.history"
        :key="event.id"
        class="history-entry"
      >
        <small>{{ event.time }} · {{ event.who }}</small>
        <p>{{ event.text }}</p>
      </article>

      <h3>Согласования</h3>

      <article
        v-for="approval in item.approvals"
        :key="approval.id"
        class="history-entry"
      >
        <strong>{{ stageLabel(approval.stage) }}</strong>
        <p>{{ approval.comment }}</p>
        <small>Версия сметы {{ approval.estimateVersion }}</small>
      </article>
    </div>

    <!-- Сроки -->
    <div v-if="section === SECTION_TIME">
      <article
        v-for="period in item.statusPeriods"
        :key="period.id"
        class="history-entry"
      >
        <strong>{{ stageLabel(period.stage) }}</strong>
        <p>{{ duration(period.seconds) }}</p>
        <small>
          {{ formatDateTime(period.enteredAt) }} — {{ periodExitedAt(period) }}
        </small>
      </article>
    </div>

    <p class="muted">ID в системе: {{ item.systemId }}</p>
  </div>
</template>
