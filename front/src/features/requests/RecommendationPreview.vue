<script setup>
import { computed } from 'vue';
import { formatDateTime } from '../../data/permissions.js';

// Панель «Состояние документа» для документа Рекомендаций ТС.
const props = defineProps({
  doc: { type: Object, default: null },
});

const vehicleTitle = computed(() => {
  const doc = props.doc || {};
  return [doc.make, doc.model].filter(Boolean).join(' ') || 'ТС';
});

// Хронология: добавление строк из ЗР, перенос в ЗР и удаление, новые сверху.
const events = computed(() => {
  const rows = [];
  for (const item of props.doc?.history || []) {
    const name = item.name || '—';
    rows.push({
      id: `${item.id}:add`,
      at: item.createdAt,
      text: `Добавлено${item.sourceRequestNumber ? ' из ' + item.sourceRequestNumber : ''}: ${name}`,
      who: item.actor,
    });
    if (item.movedAt) {
      rows.push({
        id: `${item.id}:move`,
        at: item.movedAt,
        text: `Перенесено в ${item.targetRequestNumber || 'заявку'}: ${name}`,
        who: '',
      });
    }
    if (item.deletedAt) {
      rows.push({
        id: `${item.id}:delete`,
        at: item.deletedAt,
        text: `Удалено: ${name}`,
        who: item.deletedBy || '',
      });
    }
  }
  return rows.sort((a, b) => String(b.at).localeCompare(String(a.at)));
});
</script>

<template>
  <div v-if="!doc" class="empty-state">
    <span class="empty-symbol">☰</span>
    <h3>Документ не выбран</h3>
    <p>Нажмите на строку для предпросмотра.</p>
  </div>

  <div v-else class="preview-content recommendation-preview">
    <span class="eyebrow">Рекомендации ТС · только просмотр</span>
    <h2>{{ doc.number }}</h2>
    <span class="status" :class="doc.openCount ? 'amber' : 'green'">
      {{ doc.openCount ? 'Есть рекомендации' : 'Все позиции перенесены в заявки' }}
    </span>

    <div class="preview-total">
      <small>Рекомендовано позиций</small>
      <strong>{{ doc.openCount }}</strong>
    </div>

    <dl class="detail-list">
      <dt>Автомобиль</dt>
      <dd>{{ vehicleTitle }}</dd>
      <dt>Госномер</dt>
      <dd>{{ doc.plate || '—' }}</dd>
      <dt>VIN</dt>
      <dd>{{ doc.vin || '—' }}</dd>
      <template v-if="doc.company">
        <dt>Заказчик</dt>
        <dd>{{ doc.company }}</dd>
      </template>
      <dt>Работы / ЗЧ к выполнению</dt>
      <dd>{{ doc.openWorks }} / {{ doc.openParts }}</dd>
      <dt>Перенесено в заявки</dt>
      <dd>{{ doc.movedCount }}</dd>
      <dt>Удалено</dt>
      <dd>{{ doc.deletedCount || 0 }}</dd>
      <dt>Из заявок</dt>
      <dd>{{ (doc.sourceRequests || []).join(', ') || '—' }}</dd>
      <dt>Создан</dt>
      <dd>{{ formatDateTime(doc.createdAt) }}</dd>
      <dt>Последнее добавление</dt>
      <dd>{{ formatDateTime(doc.lastAddedAt) }}</dd>
    </dl>

    <h3>История</h3>
    <article v-for="event in events" :key="event.id" class="history-entry">
      <small>{{ formatDateTime(event.at) }}<template v-if="event.who"> · {{ event.who }}</template></small>
      <p>{{ event.text }}</p>
    </article>

    <p class="muted">ID в системе: {{ doc.systemId }}</p>
  </div>
</template>
