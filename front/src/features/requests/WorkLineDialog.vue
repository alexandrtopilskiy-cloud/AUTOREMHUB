<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue';
import WorkTypePicker from './WorkTypePicker.vue';
import InfoHint from '../../components/InfoHint.vue';
import { money } from '../../data/permissions.js';

/**
 * Карточка строки работы: открывается двойным нажатием по строке таблицы
 * «Работы» и показывает все параметры, включая комментарии и применимость
 * нормы. Правки применяются к той же строке ЗР (сохраняются вместе с ЗР).
 */

const props = defineProps({
  work: { type: Object, required: true },
  position: { type: Number, default: 0 },
  workTypes: { type: Array, default: () => [] },
  editable: { type: Boolean, default: false },
  customerDraft: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  hourlyRate: { type: Number, default: null },
  amount: { type: Number, default: null },
  showAgentDiscount: { type: Boolean, default: false },
  agentPercent: { type: Number, default: null },
  agentDiscount: { type: Number, default: null },
  showChangeNotes: { type: Boolean, default: false },
  canChangeInWork: { type: Boolean, default: false },
  change: { type: String, default: '' },
  scopeText: { type: String, default: '' },
});

const emit = defineEmits([
  'close',
  'select',
  'edit',
  'create',
  'norm',
  'qty',
  'touch',
]);

const CHANGE_LABELS = {
  added: 'Добавлена в ходе ремонта',
  changed: 'Изменена в ходе ремонта',
  removed: 'Удалена в ходе ремонта',
};

const applicability = computed(() => {
  if (props.work.normIsManual) {
    return 'Ручная норма для этой заявки';
  }

  return props.work.normApplicabilityLabel
    || props.work._normApplicabilityLabel
    || (props.work.workTypeId || props.work.normId ? 'Применимость не определена' : '—');
});

const totalHours = computed(() => {
  const hours = Number(props.work.normHours);
  const qty = Number(props.work.qty);

  return Number.isFinite(hours) && Number.isFinite(qty) && props.work.normHours != null
    ? Math.round(hours * qty * 100) / 100
    : null;
});

const stationAmount = computed(() => (
  props.amount == null
    ? null
    : Math.round((props.amount - (props.agentDiscount ?? 0)) * 100) / 100
));

const normMessageIsError = computed(() => /^(Конфликт|Подходящая|Для выбранной)/.test(
  props.work._normMessage || '',
));

function value(item, suffix = '') {
  return item == null || item === '' ? '—' : `${item}${suffix}`;
}

function onKey(event) {
  if (event.key === 'Escape') {
    emit('close');
  }
}

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
  <div class="work-line-overlay" @click.self="emit('close')">
    <section
      class="work-line-dialog"
      role="dialog"
      aria-modal="true"
      :aria-label="`Работа № ${position}`"
    >
      <header class="work-line-dialog__head">
        <div>
          <span class="eyebrow">Работа № {{ position }}</span>
          <h2>{{ work.name || 'Новая работа' }}</h2>
          <span v-if="change" class="work-line-dialog__change" :class="'line-' + change">
            {{ CHANGE_LABELS[change] || change }}
          </span>
        </div>
        <button
          type="button"
          class="ghost-btn work-line-dialog__close"
          aria-label="Закрыть"
          title="Закрыть"
          @click="emit('close')"
        >×</button>
      </header>

      <!-- Наименование -->
      <div class="work-line-dialog__section">
        <label class="work-line-dialog__wide">
          <span>Наименование работы</span>
          <WorkTypePicker
            v-if="editable"
            v-model="work.workTypeId"
            :name="work.name"
            :options="workTypes"
            :disabled="busy"
            :create-label="customerDraft ? '' : 'Добавить работу'"
            @select="emit('select', $event)"
            @edit="emit('edit', $event)"
            @create="emit('create', $event)"
          />
          <output v-else>{{ work.name || '—' }}</output>
        </label>
        <div v-if="!customerDraft && work.workCode">
          <span>Код работы</span>
          <output>{{ work.workCode }}</output>
        </div>
      </div>

      <!-- Норма времени и её применимость -->
      <div v-if="!customerDraft" class="work-line-dialog__section">
        <h3>Норма времени</h3>
        <div class="work-line-dialog__grid">
          <label>
            <span>Норма времени, ч</span>
            <div v-if="editable" class="manual-norm-field">
              <input
                v-model.number="work.normHours"
                type="number"
                min="0.1"
                step="0.1"
                placeholder="Ввести вручную"
                aria-label="Норма времени для этой заявки"
                :disabled="busy || !work.workTypeId || work._normLoading"
                @input="emit('norm')"
              />
              <InfoHint text="Ручное значение сохраняется только в этой заявке." />
            </div>
            <output v-else>{{ value(work.normHours) }}</output>
          </label>
          <div>
            <span>Нормо-часов с учётом кратности</span>
            <output>{{ value(totalHours) }}</output>
          </div>
          <div class="work-line-dialog__wide">
            <span>Применимость нормы</span>
            <output>{{ applicability }}</output>
          </div>
          <div>
            <span>Источник нормы</span>
            <output>{{ work.normIsManual ? 'Ручная корректировка' : (scopeText || '—') }}</output>
          </div>
          <div>
            <span>Идентификатор нормы</span>
            <output>{{ value(work.normId) }}</output>
          </div>
          <label v-if="work.normIsManual" class="work-line-dialog__wide">
            <span>Причина ручной корректировки нормы</span>
            <input
              v-if="editable"
              v-model.trim="work.normOverrideReason"
              type="text"
              maxlength="2000"
              required
              :class="{ 'missing-note': !String(work.normOverrideReason || '').trim() }"
              placeholder="Причина ручной корректировки"
              :disabled="busy"
              @input="emit('touch')"
            />
            <output v-else>{{ value(work.normOverrideReason) }}</output>
          </label>
          <div v-if="work.actualHours != null">
            <span>Фактически затрачено, ч</span>
            <output>{{ work.actualHours }}</output>
          </div>
        </div>
        <small v-if="work._normLoading" class="muted">Подбираем норму…</small>
        <small
          v-else-if="work._normMessage"
          :class="normMessageIsError ? 'norm-selection-error' : 'muted'"
        >{{ work._normMessage }}</small>
      </div>

      <!-- Стоимость -->
      <div class="work-line-dialog__section">
        <h3>{{ customerDraft ? 'Количество' : 'Стоимость' }}</h3>
        <div class="work-line-dialog__grid">
          <div v-if="!customerDraft">
            <span>Стоимость нормо/часа, ₽</span>
            <output>{{ hourlyRate == null ? '—' : money(hourlyRate) }}</output>
          </div>
          <label>
            <span>Кратность</span>
            <input
              v-if="editable"
              v-model.number="work.qty"
              type="number"
              min="0.1"
              step="0.1"
              :disabled="busy || !work.workTypeId"
              @input="emit('qty')"
            />
            <output v-else>{{ value(work.qty) }}</output>
          </label>
          <div v-if="!customerDraft">
            <span>Стоимость, ₽</span>
            <output>{{ amount == null ? 'Не рассчитано' : money(amount) }}</output>
          </div>
          <template v-if="showAgentDiscount && !customerDraft">
            <div>
              <span>Агентская скидка, %</span>
              <output title="Подставляется из реквизитов СТО">{{ value(agentPercent) }}</output>
            </div>
            <div>
              <span>Агентская скидка, ₽</span>
              <output>{{ agentDiscount == null ? '—' : money(agentDiscount) }}</output>
            </div>
            <div>
              <span>К оплате СТО, ₽</span>
              <output>{{ stationAmount == null ? '—' : money(stationAmount) }}</output>
            </div>
          </template>
        </div>
      </div>

      <!-- Комментарии -->
      <div class="work-line-dialog__section">
        <h3>Комментарии</h3>
        <label class="work-line-dialog__wide">
          <span>Комментарий к работе (только для этой заявки)</span>
          <textarea
            v-if="editable"
            v-model="work.comment"
            maxlength="2000"
            placeholder="Комментарий"
            :disabled="busy"
            @input="emit('touch')"
          />
          <output v-else class="work-line-dialog__text">{{ work.comment || '—' }}</output>
        </label>
        <label v-if="showChangeNotes" class="work-line-dialog__wide">
          <span>Примечание к изменению</span>
          <textarea
            v-if="canChangeInWork"
            v-model="work.changeNote"
            maxlength="2000"
            :class="{ 'missing-note': change && !String(work.changeNote || '').trim() }"
            placeholder="Что и почему изменено"
            :disabled="busy"
            @input="emit('touch')"
          />
          <output v-else class="work-line-dialog__text">{{ work.changeNote || '—' }}</output>
        </label>
      </div>

      <footer class="work-line-dialog__foot">
        <small class="muted">
          {{ editable ? 'Изменения сохраняются вместе с ЗР.' : 'Только просмотр.' }}
        </small>
        <button type="button" class="primary-btn" @click="emit('close')">Готово</button>
      </footer>
    </section>
  </div>
</template>
