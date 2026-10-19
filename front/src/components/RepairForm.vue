<script setup>
import { computed } from 'vue';
import { formatDate } from '../composables/useRequests';
import { routeStages, statusClass, visualStage } from '../data/workflow';

/* ─── Props и emits ─── */

const props = defineProps({
  request: {
    type: Object,
    required: true,
  },
  step: {
    type: Object,
    required: true,
  },
  editable: {
    type: Boolean,
    default: false,
  },
  canAct: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits([
  'touch',
  'add-work',
  'remove-work',
  'next',
  'return',
  'history',
]);

/* ─── Вычисляемые ─── */

const total = computed(() =>
  (props.request.works ?? []).reduce(
    (sum, row) => sum + (Number(row.qty) || 0) * (Number(row.price) || 0),
    0,
  ),
);

const currentStage = computed(() => visualStage(props.request.stage));
const stageClass = computed(() => statusClass(props.request.stage));

/* ─── Хелперы ─── */

function money(value) {
  const number = Number(value ?? 0);

  if (!Number.isFinite(number)) {
    return '0';
  }

  return number.toLocaleString('ru-RU');
}

function isStepDone(index) {
  return index < currentStage.value;
}

function isStepActive(index) {
  return index === currentStage.value;
}

/* ─── Действия ─── */

function touch() {
  emit('touch');
}

function openHistory() {
  emit('history');
}

function addWork() {
  emit('add-work');
}

function removeWork(index) {
  emit('remove-work', index);
}

function goNext() {
  emit('next');
}

function goReturn() {
  emit('return');
}
</script>

<template>
  <section class="document-panel">
    <!-- Заголовок -->
    <div class="document-head">
      <div>
        <div class="title-row">
          <p class="eyebrow">Заявка на ремонт</p>
          <span class="status" :class="stageClass">
            {{ step.status }}
          </span>
        </div>
        <h2>
          {{ request.id }}
          <span class="muted">· создана {{ formatDate(request.createdAt) }}</span>
        </h2>
      </div>

      <button class="ghost-btn" @click="openHistory">
        История
      </button>
    </div>

    <!-- Stepper -->
    <ol class="stepper" aria-label="Этапы заявки">
      <li
        v-for="(_, index) in routeStages"
        :key="index"
        :class="{
          done: isStepDone(index),
          active: isStepActive(index),
        }"
      />
    </ol>

    <!-- Форма -->
    <form
      :class="{ readonly: !editable }"
      @input="touch"
      @change="touch"
      @submit.prevent
    >
      <!-- 01. Автомобиль и обращение -->
      <div class="section-title">
        <span>01</span>
        <div>
          <h3>Автомобиль и обращение</h3>
          <p>Данные, переданные Заказчиком</p>
        </div>
      </div>

      <div class="form-grid vehicle-grid">
        <label>
          <span>Организация</span>
          <input
            v-model="request.company"
            placeholder="Наименование организации"
          />
        </label>

        <label>
          <span>Автомобиль</span>
          <input
            v-model="request.vehicle"
            placeholder="Марка, модель, год"
          />
        </label>

        <label>
          <span>VIN</span>
          <input
            v-model="request.vin"
            placeholder="17 символов"
          />
        </label>

        <label>
          <span>Госномер</span>
          <input
            v-model="request.plate"
            placeholder="А000АА 178"
          />
        </label>

        <label>
          <span>Пробег, км</span>
          <input
            v-model="request.mileage"
            inputmode="numeric"
            placeholder="0"
          />
        </label>

        <label>
          <span>Контактное лицо</span>
          <input
            v-model="request.contact"
            placeholder="ФИО и телефон"
          />
        </label>
      </div>

      <!-- 02. Причина обращения -->
      <div class="section-title">
        <span>02</span>
        <div>
          <h3>Причина обращения</h3>
          <p>Состав работ можно уточнить вручную</p>
        </div>
      </div>

      <div class="form-grid">
        <label>
          <span>Категория</span>
          <select v-model="request.reason">
            <option value="">Выберите категорию</option>
            <option>Техническое обслуживание</option>
            <option>Явная неисправность</option>
            <option>Диагностика</option>
            <option>Шиномонтаж</option>
          </select>
        </label>

        <label>
          <span>Вид диагностики</span>
          <select v-model="request.diagnostics">
            <option value="">Не выбрано</option>
            <option>Диагностика ходовой части</option>
            <option>Диагностика тормозной системы</option>
            <option>Диагностика электрики</option>
            <option>Компьютерная диагностика</option>
          </select>
        </label>

        <label class="full">
          <span>Описание Заказчика</span>
          <textarea
            v-model="request.description"
            placeholder="Опишите неисправность или необходимые работы"
          />
        </label>
      </div>

      <!-- 03. Работы, материалы и стоимость -->
      <div class="section-title">
        <span>03</span>
        <div>
          <h3>Работы, материалы и стоимость</h3>
          <p>Заполняются и проверяются Управляющей компанией вручную</p>
        </div>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Наименование</th>
              <th>Тип</th>
              <th>Кол-во</th>
              <th>Цена, ₽</th>
              <th>Сумма, ₽</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(row, index) in request.works"
              :key="index"
            >
              <td>
                <input v-model="row.name" placeholder="Наименование" />
              </td>

              <td>
                <select v-model="row.type">
                  <option>Работа</option>
                  <option>Материал</option>
                </select>
              </td>

              <td>
                <input
                  v-model.number="row.qty"
                  class="qty"
                  type="number"
                  min="0"
                  step="0.1"
                />
              </td>

              <td>
                <input
                  v-model.number="row.price"
                  class="price"
                  type="number"
                  min="0"
                  step="1"
                />
              </td>

              <td class="row-total">
                {{ money(row.qty * row.price) }}
              </td>

              <td>
                <button
                  type="button"
                  class="delete-row"
                  aria-label="Удалить строку"
                  @click="removeWork(index)"
                >
                  ×
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="table-actions">
        <button
          type="button"
          class="add-row"
          @click="addWork"
        >
          ＋ Добавить позицию
        </button>

        <div class="total">
          <span>Предварительная стоимость</span>
          <strong>{{ money(total) }} ₽</strong>
        </div>
      </div>

      <!-- 04. СТО и время визита -->
      <div class="section-title">
        <span>04</span>
        <div>
          <h3>СТО и время визита</h3>
          <p>Маршрутизация и подтверждение слота</p>
        </div>
      </div>

      <div class="form-grid">
        <label>
          <span>СТО-партнёр</span>
          <select v-model="request.station">
            <option value="">Не назначено</option>
            <option>A78-Сервис · Коломяжский пр., 10Щ</option>
            <option>СТО «Север» · Планерная ул., 15</option>
          </select>
        </label>

        <label>
          <span>Желаемый визит</span>
          <input v-model="request.slot" type="datetime-local" />
        </label>

        <label>
          <span>Предложенный слот СТО</span>
          <input v-model="request.stationSlot" type="datetime-local" />
        </label>

        <label>
          <span>Срок выполнения</span>
          <input v-model="request.deadline" placeholder="Дата и время" />
        </label>

        <label class="full">
          <span>Комментарий текущего участника</span>
          <textarea
            v-model="request.routeComment"
            placeholder="Укажите решение, уточнение или причину возврата"
          />
        </label>
      </div>
    </form>

    <!-- Панель действий -->
    <div class="route-action">
      <div>
        <span class="action-label">Следующий этап</span>
        <strong>{{ step.next }}</strong>
        <small>{{ step.hint }}</small>
      </div>

      <div class="action-buttons">
        <button
          v-if="request.stage > 0 && request.stage < 11"
          class="ghost-btn"
          :disabled="!canAct"
          @click="goReturn"
        >
          Вернуть на уточнение
        </button>

        <button
          class="primary-btn"
          :disabled="!canAct || request.stage === 11"
          @click="goNext"
        >
          Далее по маршруту <span>→</span>
        </button>
      </div>
    </div>
  </section>
</template>
