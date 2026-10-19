<script setup>
import { computed, onMounted, ref } from 'vue';
import ConfirmedDateTimeInput from '../../components/ConfirmedDateTimeInput.vue';
import { formatDateTime } from '../../data/permissions.js';
import { VISIT_TIME_END, VISIT_TIME_START } from '../../data/schedule.js';

const DECISIONS = {
  confirmed: {
    title: 'Подтвердить время визита',
    button: 'Подтвердить время',
  },
  rescheduled: {
    title: 'Предложить другое время',
    button: 'Отправить Заказчику',
  },
  declined: {
    title: 'Отказаться от выполнения заявки',
    button: 'Отправить отказ в УК',
  },
};

const props = defineProps({
  request: {
    type: Object,
    required: true,
  },
  decision: {
    type: String,
    required: true,
    validator: (value) => [
      'confirmed',
      'rescheduled',
      'declined',
    ].includes(value),
  },
  busy: {
    type: Boolean,
    default: false,
  },
  perform: {
    type: Function,
    required: true,
  },
});

const emit = defineEmits(['close']);

const dialog = ref(null);
const comment = ref('');
const proposedSlot = ref('');
const error = ref('');

const reviewingManagerChanges = computed(
  () => props.request.reviewContext === 'manager_changes',
);
const estimateChanged = computed(
  () => props.request.estimateVersion !== props.request.reviewBaselineVersion,
);
const copy = computed(() => {
  if (props.decision !== 'confirmed') {
    return DECISIONS[props.decision];
  }

  if (estimateChanged.value) {
    return {
      title: 'Передать изменения на согласование УК',
      button: 'Отправить в УК',
    };
  }

  return reviewingManagerChanges.value
    ? {
        title: 'Принять изменения УК',
        button: 'Принять и направить Заказчику',
      }
    : DECISIONS.confirmed;
});
const canSubmit = computed(
  () =>
    !props.busy
    && (props.decision !== 'rescheduled' || proposedSlot.value !== '')
    && (props.decision !== 'declined' || comment.value.trim() !== ''),
);

function close() {
  emit('close');
}

async function submit() {
  error.value = '';

  try {
    await props.perform('stationDecision', props.decision, {
      comment: comment.value,
      ...(props.decision === 'rescheduled'
        ? { stationSlot: proposedSlot.value }
        : {}),
    });

    emit('close');
  } catch (exception) {
    error.value = exception.message;
  }
}

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
      <h2>{{ copy.title }}</h2>
      <p>{{ request.number }}</p>

      <p v-if="decision === 'rescheduled'" class="requested-visit-time">
        Запрошенная дата и время визита: {{ formatDateTime(request.slot) || 'Не указаны' }}
      </p>
      <ConfirmedDateTimeInput
        v-if="decision === 'rescheduled'"
        v-model="proposedSlot"
        title="Новое время визита"
        :disabled="busy"
        :min-time="VISIT_TIME_START"
        :max-time="VISIT_TIME_END"
      />

      <label>
        {{ decision === 'declined' ? 'Причина отказа (обязательно)' : 'Комментарий к решению (необязательно)' }}
        <textarea
          v-model="comment"
          :required="decision === 'declined'"
          :disabled="busy"
        />
      </label>

      <p v-if="error" class="error-message">{{ error }}</p>

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
          {{ copy.button }}
        </button>
      </div>
    </form>
  </dialog>
</template>
