<script setup>
import { computed, onMounted, ref } from 'vue';
import FormAlert from "../../components/FormAlert.vue";
import { workflow } from '../../data/workflow.js';

/* ─── Константы ─── */

const ACTION_RETURN = 'return';

const PURPOSE_EVIDENCE = 'evidence';
const PERFORM_TRANSITION = 'transition';

/* ─── Props и emits ─── */

const props = defineProps({
  request: {
    type: Object,
    required: true,
  },
  action: {
    type: String,
    required: true,
  },
  busy: {
    type: Boolean,
    default: false,
  },
  perform: {
    type: Function,
    required: true,
  },
  optionalDecision: {
    type: Boolean,
    default: false,
  },
  hideAttachments: {
    type: Boolean,
    default: false,
  },
});

/* ЛК СТО: нехватка ЗЧ на складе при переходе с этапов 4 и 5 (с этапа 5 сервер переход не пустит). */
const stockShortage = computed(() =>
  props.action === 'next' && [4, 5].includes(props.request.stage) ? props.request.stockShortage || [] : [],
);

const emit = defineEmits(['close']);

/* ─── Состояние ─── */

const dialog = ref(null);
const comment = ref(props.request.routeComment || '');
const attachmentIds = ref([]);
const error = ref('');

/* ─── Вычисляемые ─── */

const title = computed(() => {
  if (props.action === ACTION_RETURN && props.request.stage === 4) {
    return 'Вернуть заявку Заказчику на согласование';
  }
  if (props.action === ACTION_RETURN) {
    return 'Вернуть заявку на предыдущий этап';
  }

  return workflow[props.request.stage].next;
});

const initialSubmission = computed(
  () => props.action !== ACTION_RETURN && props.request.stage === 0,
);

const isOptional = computed(
  () => props.optionalDecision || initialSubmission.value,
);

const files = computed(() =>
  (props.request.attachments || []).filter(
    (file) =>
      file.purpose === PURPOSE_EVIDENCE
      && file.periodId === props.request.currentPeriodId
      && !file.linked,
  ),
);

const canSubmit = computed(
  () =>
    !props.busy
    && (isOptional.value || comment.value.trim() !== ''),
);

/* ─── Действия ─── */

function close() {
  emit('close');
}

async function submit() {
  error.value = '';

  try {
    await props.perform(PERFORM_TRANSITION, props.action, {
      comment: comment.value,
      attachmentIds: attachmentIds.value,
    });

    emit('close');
  } catch (e) {
    error.value = e.message;
  }
}

/* ─── Жизненный цикл ─── */

onMounted(() => {
  dialog.value?.showModal();
});
</script>

<template>
  <dialog
    ref="dialog"
    class="action-dialog"
    @cancel.prevent="close"
  >
    <form @submit.prevent="submit">
      <h2>{{ title }}</h2>
      <FormAlert v-if="error" class="error-message">{{ error }}</FormAlert>
      <p>{{ request.number }}</p>
      <div v-if="stockShortage.length" class="stock-shortage-banner" role="alert">
        <strong>Недостаточно ЗЧ на складе СТО:</strong>
        <ul>
          <li v-for="line in stockShortage" :key="line">{{ line }}</li>
        </ul>
        <span v-if="request.stage === 4">Переход на этап «В работе» не блокируется, но до выдачи ТС эти ЗЧ нужно оприходовать.</span>
        <span v-else>Перевести ЗР на этап «ТС готово к выдаче Заказчику» можно только после оприходования этих ЗЧ.</span>
      </div>

      <label>
        Комментарий к решению{{ isOptional ? ' (необязательно)' : '' }}
        <textarea
          v-model="comment"
          :required="!isOptional"
          :disabled="busy"
        />
      </label>

      <fieldset v-if="!hideAttachments">
        <legend>
          Фото или видео текущего этапа (необязательно)
        </legend>

        <p v-if="!files.length" class="muted">
          Вложения можно не добавлять при переходе на следующий этап.
        </p>

        <label
          v-for="file in files"
          :key="file.id"
          class="checkbox-label"
        >
          <input
            v-model="attachmentIds"
            type="checkbox"
            :value="file.id"
            :disabled="busy"
          />
          {{ file.name }}
        </label>
      </fieldset>


      <div class="action-buttons">
        <button
          type="button"
          class="ghost-btn"
          :disabled="busy"
          @click="close"
        >
          Отмена
        </button>

        <button class="primary-btn" :disabled="!canSubmit">
          Подтвердить
        </button>
      </div>
    </form>
  </dialog>
</template>
