<script setup>
import { computed, ref, watch } from "vue";
import FormAlert from "../../components/FormAlert.vue";
import { formatDateTime } from "../../data/permissions.js";

/**
 * Изменение работ и ЗЧ на стадии «В работе»: СТО отправляет изменения на
 * согласование (УК → Заказчик), согласующие принимают решение, при отказе
 * СТО получает обязательный комментарий.
 */
const props = defineProps({
  request: { type: Object, required: true },
  busy: Boolean,
  dirty: Boolean,
  perform: { type: Function, required: true },
});

const removedNotes = ref({});
const comment = ref("");
const rejecting = ref(false);
const error = ref("");

const change = computed(() => props.request.workChange || null);
const permissions = computed(() => props.request.permissions || {});
const items = computed(() => change.value?.items || []);
const removedItems = computed(() => items.value.filter((item) => item.action === "removed"));
const canSubmit = computed(() => permissions.value.canSubmitWorkChange === true);
const canDecide = computed(() => permissions.value.canDecideWorkChange === true);

watch(
  () => props.request.revision,
  () => {
    removedNotes.value = Object.fromEntries(
      removedItems.value.map((item) => [item.id, item.note || ""]),
    );
    comment.value = "";
    rejecting.value = false;
    error.value = "";
  },
  { immediate: true },
);

function kindLabel(item) {
  return item.kind === "work" ? "Работа" : "Запчасть";
}

function actionLabel(item) {
  return { added: "добавлена", removed: "удалена", changed: "изменена" }[item.action] || item.action;
}

function missingNote(item) {
  if (item.action === "removed") {
    return !String(removedNotes.value[item.id] || "").trim();
  }

  return !String(item.note || "").trim();
}

const missingNotes = computed(() => items.value.filter((item) => missingNote(item)));

async function run(method, ...args) {
  error.value = "";
  try {
    await props.perform(method, ...args);
  } catch (exception) {
    error.value = exception.message;
  }
}

function submit() {
  const notes = Object.fromEntries(
    Object.entries(removedNotes.value)
      .map(([id, note]) => [id, String(note || "").trim()])
      .filter(([, note]) => note !== ""),
  );
  return run("workChangeSubmit", notes);
}

function discard() {
  if (!window.confirm("Отменить изменения и вернуть согласованный перечень работ и запчастей?")) {
    return;
  }
  return run("workChangeDiscard");
}

function approve() {
  return run("workChangeDecision", "approved", comment.value.trim());
}

function reject() {
  if (!rejecting.value) {
    rejecting.value = true;
    return;
  }
  if (!comment.value.trim()) {
    error.value = "Укажите, почему изменения не согласованы";
    return;
  }
  return run("workChangeDecision", "rejected", comment.value.trim());
}
</script>

<template>
  <div v-if="change" class="work-change-panel info-banner repair-consensus-banner">
    <div class="work-change-head">
      <strong>Изменение работ / з/ч в ходе ремонта</strong>
      <span class="work-change-status">{{ change.statusLabel }}</span>
    </div>
    <FormAlert v-if="error" class="error-message">{{ error }}</FormAlert>

    <p v-if="change.rejection" class="work-change-rejection">
      Не согласовано ({{ change.rejection.by }}<template v-if="change.rejection.actor">, {{ change.rejection.actor }}</template><template v-if="change.rejection.at">, {{ formatDateTime(change.rejection.at) }}</template>):
      <strong>{{ change.rejection.comment }}</strong>
    </p>

    <p v-if="canSubmit">
      Внесите примечание к каждой добавленной, изменённой или удалённой позиции
      и отправьте изменения на согласование: сначала УК, затем Заказчику.
      Несохранённые правки сохранятся автоматически, после отправки ЗР закроется.
      Кнопка «Далее» неактивна, пока изменения не согласованы УК и Заказчиком
      (или не отменены).
    </p>
    <p v-else-if="canDecide">
      СТО изменила работы или запчасти в ходе ремонта. Согласуйте изменения
      либо верните их в СТО с обязательным комментарием.
    </p>
    <p v-else-if="change.status === 'manager' || change.status === 'customer'">
      Изменения на согласовании: {{ change.owner }}.
    </p>

    <ul v-if="items.length" class="work-change-list">
      <li
        v-for="item in items"
        :key="item.kind + item.id"
        :class="['work-change-item', 'line-' + item.action]"
      >
        <span>
          {{ kindLabel(item) }} {{ actionLabel(item) }}: «{{ item.name }}»
          <template v-if="item.fields?.length">
            —
            <span v-for="(field, index) in item.fields" :key="field.field">
              {{ field.label }} {{ field.before }} → {{ field.after }}<template v-if="index < item.fields.length - 1">, </template>
            </span>
          </template>
        </span>
        <label v-if="canSubmit && item.action === 'removed'" class="work-change-note">
          Примечание
          <input
            v-model="removedNotes[item.id]"
            type="text"
            maxlength="2000"
            :disabled="busy"
            placeholder="Почему позиция удалена"
          />
        </label>
        <small v-else-if="item.note" class="work-change-note-text">Примечание: {{ item.note }}</small>
        <small v-else-if="canSubmit" class="error-message">Нет примечания — заполните столбец «Примечание к изменению»</small>
      </li>
    </ul>
    <p v-else-if="canSubmit" class="muted">
      Сохраните изменения в таблицах работ и запчастей, чтобы они появились здесь.
    </p>

    <div v-if="canSubmit" class="action-buttons">
      <button
        type="button"
        class="primary-btn"
        :disabled="busy || (!dirty && (!items.length || missingNotes.length > 0))"
        :title="dirty ? 'Изменения будут сохранены, ЗР отправлена на согласование и закрыта' : missingNotes.length ? 'Заполните примечания ко всем изменениям' : ''"
        @click="submit"
      >
        Отправить изменения на согласование
      </button>
      <button type="button" class="ghost-btn" :disabled="busy || dirty" @click="discard">
        Отменить изменения
      </button>
    </div>

    <div v-if="canDecide" class="work-change-decision">
      <label class="full">
        Комментарий<template v-if="rejecting"> (обязательно: почему не согласовано)</template>
        <textarea v-model="comment" maxlength="2000" :disabled="busy" />
      </label>
      <div class="action-buttons">
        <button type="button" class="primary-btn" :disabled="busy || dirty" @click="approve">
          Согласовать изменения
        </button>
        <button type="button" class="ghost-btn" :disabled="busy || dirty" @click="reject">
          {{ rejecting ? 'Вернуть в СТО' : 'Не согласовать' }}
        </button>
      </div>
    </div>

  </div>
</template>
