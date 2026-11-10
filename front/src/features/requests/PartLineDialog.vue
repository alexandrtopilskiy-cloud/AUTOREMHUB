<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue';
import WorkTypePicker from './WorkTypePicker.vue';
import { money } from '../../data/permissions.js';

/**
 * Карточка строки ЗЧ: открывается двойным нажатием по строке таблицы
 * «Запчасти» (как карточка работы) и показывает всё, что не помещается в
 * таблицу: поставщика, статус и действия с ЗЧ, наличие на складе,
 * комментарии. Правки применяются к той же строке ЗР.
 */

const props = defineProps({
  part: { type: Object, required: true },
  position: { type: Number, default: 0 },
  options: { type: Array, default: () => [] },
  suppliers: { type: Array, default: () => [] },
  editable: { type: Boolean, default: false },
  canCreate: { type: Boolean, default: false },
  canEditComment: { type: Boolean, default: false },
  customerView: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  statusLabel: { type: String, default: '' },
  actions: { type: Array, default: () => [] },
  stock: { type: Object, default: null },
  freeQty: { type: Number, default: null },
  agentPercent: { type: Number, default: null },
  agentDiscount: { type: Number, default: null },
  showChangeNotes: { type: Boolean, default: false },
  canChangeInWork: { type: Boolean, default: false },
  change: { type: String, default: '' },
});

const emit = defineEmits(['close', 'select', 'edit', 'create', 'touch', 'action']);

const CHANGE_LABELS = {
  added: 'Добавлена в ходе ремонта',
  changed: 'Изменена в ходе ремонта',
  removed: 'Удалена в ходе ремонта',
};

const amount = computed(() => Math.round(Number(props.part.qty || 0) * Number(props.part.price || 0) * 100) / 100);
const stationAmount = computed(() => Math.round((amount.value - (props.agentDiscount ?? 0)) * 100) / 100);
const supplierName = computed(() => props.suppliers.find((s) => s.id === props.part.supplierId)?.name || '—');

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
      class="work-line-dialog part-line-dialog"
      role="dialog"
      aria-modal="true"
      :aria-label="`Запчасть № ${position}`"
    >
      <header class="work-line-dialog__head">
        <div>
          <span class="eyebrow">Запчасть № {{ position }}</span>
          <h2>{{ part.name || 'Новая запчасть' }}</h2>
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

      <div class="work-line-dialog__section">
        <h3>Запчасть</h3>
        <div class="work-line-dialog__grid">
          <label class="work-line-dialog__wide">
            <span>Наименование</span>
            <WorkTypePicker
              v-if="editable"
              v-model="part.catalogPartId"
              :name="part.name"
              :options="options"
              placeholder="Начните вводить запчасть"
              :disabled="busy"
              :can-create="canCreate"
              create-label="Добавить запчасть"
              @select="emit('select', $event)"
              @edit="emit('edit', $event)"
              @create="emit('create', $event)"
            />
            <output v-else>{{ part.name || '—' }}</output>
          </label>
          <div>
            <span>Артикул</span>
            <output>{{ value(part.article) }}</output>
          </div>
          <div>
            <span>Производитель</span>
            <output>{{ value(part.manufacturer) }}</output>
          </div>
          <div>
            <span>Единица измерения</span>
            <output>{{ value(part.unit) }}</output>
          </div>
        </div>
      </div>

      <div class="work-line-dialog__section">
        <h3>Количество и стоимость</h3>
        <div class="work-line-dialog__grid">
          <label>
            <span>Количество</span>
            <input
              v-if="editable"
              v-model.number="part.qty"
              type="number"
              min="0.1"
              step="0.1"
              :disabled="busy"
              @input="emit('touch')"
            />
            <output v-else>{{ value(part.qty) }}</output>
          </label>
          <label>
            <span>Цена, ₽</span>
            <input
              v-if="editable"
              v-model.number="part.price"
              type="number"
              min="0"
              step="0.01"
              :disabled="busy"
              @input="emit('touch')"
            />
            <output v-else>{{ money(part.price) }}</output>
          </label>
          <div>
            <span>Сумма, ₽</span>
            <output>{{ money(amount) }}</output>
          </div>
          <template v-if="!customerView">
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
              <output>{{ money(stationAmount) }}</output>
            </div>
          </template>
        </div>
      </div>

      <div v-if="!customerView" class="work-line-dialog__section">
        <h3>Поставка и склад</h3>
        <div class="work-line-dialog__grid">
          <label>
            <span>Поставщик</span>
            <select
              v-if="editable"
              v-model="part.supplierId"
              :disabled="busy"
              @change="emit('touch')"
            >
              <option value="">По партии склада</option>
              <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <output v-else>{{ supplierName }}</output>
          </label>
          <div>
            <span>Статус</span>
            <output>{{ statusLabel }}</output>
          </div>
          <div>
            <span>Склад</span>
            <output>
              <span v-if="stock" class="part-stock-badge" :class="stock.kind">{{ stock.label }}</span>
              <template v-else>—</template>
            </output>
          </div>
          <div v-if="freeQty != null">
            <span>Свободно на складе</span>
            <output>{{ freeQty.toLocaleString('ru-RU', { maximumFractionDigits: 3 }) }} {{ part.unit }}</output>
          </div>
        </div>
        <div v-if="actions.length" class="part-line-dialog__actions">
          <button
            v-for="item in actions"
            :key="item.kind"
            type="button"
            class="ghost-btn"
            :disabled="busy"
            @click="emit('action', item.kind)"
          >{{ item.label }}</button>
        </div>
      </div>

      <div class="work-line-dialog__section">
        <h3>Комментарии</h3>
        <label class="work-line-dialog__wide">
          <span>Комментарий к запчасти (только для этой заявки)</span>
          <textarea
            v-if="canEditComment"
            v-model="part.comment"
            maxlength="2000"
            placeholder="Комментарий"
            :disabled="busy"
            @input="emit('touch')"
          />
          <output v-else class="work-line-dialog__text">{{ part.comment || '—' }}</output>
        </label>
        <label v-if="showChangeNotes" class="work-line-dialog__wide">
          <span>Примечание к изменению</span>
          <textarea
            v-if="canChangeInWork && canEditComment"
            v-model="part.changeNote"
            maxlength="2000"
            :class="{ 'missing-note': change && !String(part.changeNote || '').trim() }"
            placeholder="Что и почему изменено"
            :disabled="busy"
            @input="emit('touch')"
          />
          <output v-else class="work-line-dialog__text">{{ part.changeNote || '—' }}</output>
        </label>
      </div>

      <footer class="work-line-dialog__foot">
        <small class="muted">
          {{ editable || canEditComment ? 'Изменения сохраняются вместе с ЗР.' : 'Только просмотр.' }}
        </small>
        <button type="button" class="primary-btn" @click="emit('close')">Готово</button>
      </footer>
    </section>
  </div>
</template>
