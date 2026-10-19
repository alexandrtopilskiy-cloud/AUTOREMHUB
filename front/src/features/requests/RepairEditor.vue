<script setup>
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { marketplaceApi } from "../../api/marketplace.js";
import {
  applyVehicleSelection,
  resetReasonSubtype} from "./formState.js";
import VehiclePicker from './VehiclePicker.vue';
import WorkTypePicker from './WorkTypePicker.vue';
import PartsPanel from './PartsPanel.vue';
import PaymentPanel from './PaymentPanel.vue';
import AttachmentPanel from './AttachmentPanel.vue';
import TransitionDialog from './TransitionDialog.vue';
import StationDecisionDialog from './StationDecisionDialog.vue';
import CustomerSlotDecisionDialog from './CustomerSlotDecisionDialog.vue';
import AcceptanceActPanel from './AcceptanceActPanel.vue';
import ConfirmedDateTimeInput from '../../components/ConfirmedDateTimeInput.vue';
import InfoHint from '../../components/InfoHint.vue';
import { VISIT_TIME_END, VISIT_TIME_START } from '../../data/schedule.js';
import { workflow, statusClass } from '../../data/workflow.js';
import { money, total, formatDateTime, duration ,
} from '../../data/permissions.js';
import { startColumnResize } from '../../utils/columnResize.js';
import { calculatePlannedCompletion } from '../../utils/plannedCompletion.js';

/* ─── Константы ─── */

const PURPOSE_DIAGNOSTIC = 'diagnostic';
const PERFORM_DIAGNOSIS = 'diagnosis';
const KIND_STATION = 'stations';

const TRANSITION_NEXT = 'next';
const TRANSITION_RETURN = 'return';

const STATION_CONFIRM = 'confirmed';
const STATION_RESCHEDULE = 'rescheduled';
const STATION_DECLINE = 'declined';

const REVIEW_STATION_CHANGES = 'station_changes';
const REVIEW_MANAGER_CHANGES = 'manager_changes';

/* ─── Props и emits ─── */

const props = defineProps({
  request: {
    type: Object,
    required: true,
  },
  user: {
    type: Object,
    required: true,
  },
  records: {
    type: Array,
    default: () => [],
  },
  references: {
    type: Object,
    required: true,
  },
  busy: {
    type: Boolean,
    default: false,
  },
  dirty: {
    type: Boolean,
    default: false,
  },
  chatCreated: {
    type: Boolean,
    default: false,
  },
  chatUnread: {
    type: Number,
    default: 0,
  },
  chatBusy: {
    type: Boolean,
    default: false,
  },
  perform: {
    type: Function,
    required: true,
  },
});

const emit = defineEmits([
  'touch',
  'save',
  'save-and-exit',
  'chat',
  'vehicle-history',
  'create-work',
  'create-part',
  'defer-recommendations',
  'recommendations',
]);

/* ─── Состояние ─── */

const selectedWorkIds = ref([]);
const selectedPartIds = ref([]);
const canDefer = computed(() => props.request.permissions?.canDeferRecommendations === true,
);
const showRepairBlock = computed(
  () =>
    !isCustomer.value ||
    (props.request.permissions?.canViewRepairEstimate ??
      (props.request.stage >= 3 ||
        props.request.statusPeriods?.some((row) => Number(row.stage) >= 3))),
);
const selectedRecommendationCount = computed(() => selectedWorkIds.value.length + selectedPartIds.value.length,
);
function deferSelected(kind) { emit('defer-recommendations', { workIds: kind === "work" ? selectedWorkIds.value: [], partIds: kind === "part" ? selectedPartIds.value : [],
  }); }
watch(() => props.request.revision, () => { selectedWorkIds.value = []; selectedPartIds.value = []; },
);

const transitionAction = ref('');
const stationDecision = ref('');
const customerSlotDialog = ref(false);
const conclusion = ref('');
const diagnosisError = ref('');
const contacts = ref([]);
const contactsLoading = ref(false);
const contactsError = ref('');
const drivers = ref([]);
const driversLoading = ref(false);
const driversError = ref('');

let emptyWorkSequence = 0;

function emptyWork() {
  emptyWorkSequence += 1;

  return {
    _clientId: `empty-work-${emptyWorkSequence}`,
    name: '',
    type: 'Работа',
    qty: 1,
    price: null,
    lineAmount: null,
    hourlyRate: props.request.laborHourRate,
    workTypeId: '',
    workCode: '',
    timeNormId: '',
    normId: '',
    normSelectionScope: '',
    normIsManual: false,
    normOverrideReason: '',
    normHours: null,
    actualHours: null,
  };
}

const placeholderWork = ref(emptyWork());

let contactsSequence = 0;
let driversSequence = 0;

/* ─── Вычисляемые ─── */

const step = computed(() => ({ ...workflow[props.request.stage], status: props.request.statusLabel || workflow[props.request.stage]?.status ,
}));
const declinedStationDialog = ref(false);

const isCustomer = computed(() => props.user.role === 'customer');
const isManager = computed(() => props.user.role === 'manager');
const isStation = computed(() => props.user.role === 'station');

const selectedVehicle = computed(() => ({
  vehicle: props.request.vehicle,
  vin: props.request.vin,
  plate: props.request.plate,
}));

const roots = computed(() =>
  (props.references.reasons || []).filter(
    (reason) =>
      !reason.parentId
      && (!isCustomer.value
        || ['maintenance', 'diagnostics'].includes(reason.code)),
  ),
);

const subtypes = computed(() =>
  (props.references.reasons || []).filter(
    (reason) => reason.parentId === props.request.reasonId,
  ),
);

const stations = computed(() =>
  props.records.filter(
    (record) => record.kind === KIND_STATION && record.active !== false,
  ),
);

const selectedStation = computed(() =>
  stations.value.find((station) => station.id === props.request.stationId)
  || null,
);

const calculatedCompletionAt = computed(() => {
  if (!props.dirty && props.request.plannedCompletionAt) {
    return props.request.plannedCompletionAt;
  }

  return (
    calculatePlannedCompletion(props.request, selectedStation.value)
    || props.request.plannedCompletionAt
    || '');
});

const canWork = computed(
  () => props.user.role !== 'customer' && isAllowed('works'),
);

const displayedWorks = computed(() => {
  if (props.request.works.length) {
    return props.request.works;
  }

  return canWork.value ? [placeholderWork.value] : [];
});

const canAddWork = computed(() => {
  const last = displayedWorks.value.at(-1);

  return Boolean(last?.workTypeId || String(last?.name || '').trim());
});

const draftRepairBlock = computed(
  () => isCustomer.value && props.request.stage === 0,
);

const canDiagnose = computed(
  () => props.request.permissions?.canDiagnose === true,
);

const canStationDecide = computed(
  () => isStation.value && props.request.permissions?.canStationDecide === true,
);

const canCustomerSlotDecide = computed(
  () => isCustomer.value
    && props.request.permissions?.canCustomerSlotDecide === true,
);

const estimateChangedSinceReview = computed(
  () => props.request.estimateVersion !== props.request.reviewBaselineVersion,
);

const managerReviewingStationChanges = computed(
  () => isManager.value
    && props.request.stage === 1
    && props.request.reviewContext === REVIEW_STATION_CHANGES,
);

const stationReviewingManagerChanges = computed(
  () => isStation.value
    && props.request.stage === 2
    && props.request.reviewContext === REVIEW_MANAGER_CHANGES,
);

const stationDecisionPrompt = computed(() => {
  if (estimateChangedSinceReview.value) {
    return 'Отправьте изменения работ и запчастей на согласование в УК';
  }

  return stationReviewingManagerChanges.value
    ? 'Примите корректировки УК и направьте финальную заявку Заказчику'
    : 'Подтвердите время, предложите другое или откажитесь от заявки';
});

const stationConfirmLabel = computed(() => {
  if (estimateChangedSinceReview.value) {
    return 'Отправить изменения в УК';
  }

  return stationReviewingManagerChanges.value
    ? 'Принять и направить Заказчику'
    : 'Подтвердить время';
});

const routeActionTitle = computed(() => {
  if (
    isManager.value
    && props.request.stage === 1
    && props.request.reviewContext === REVIEW_STATION_CHANGES
  ) {
    return 'Согласовать и направить Заказчику';
  }

  return step.value.next;
});

const managerRoutesToStation = computed(
  () => isManager.value
    && props.request.stage === 1
    && !(
      props.request.reviewContext === REVIEW_STATION_CHANGES

    ),
);

/* ─── Права на редактирование ─── */

function isAllowed(key) {
  if (props.busy) {
    return false;
  }

  const fields = props.request.permissions?.editableFields || [];

  return fields.includes(key);
}

/* ─── События ─── */

function touch() {
  emit('touch');
}

/* ─── Автомобиль ─── */

function selectVehicle(vehicle) {
  applyVehicleSelection(props.request, vehicle);

  touch();
}

/* ─── Причина обращения ─── */

function reasonChanged() {
  resetReasonSubtype(props.request, isAllowed('reasonSubtypeId'));

  touch();
}

/* ─── Контактные лица ─── */

async function loadContacts() {
  const vehicleId = props.request.vehicleId;
  const version = ++contactsSequence;

  contacts.value = [];
  contactsError.value = '';

  if (!vehicleId) {
    contactsLoading.value = false;

    return;
  }

  contactsLoading.value = true;

  try {
    const rows = await marketplaceApi.vehicleContacts(vehicleId);

    if (version === contactsSequence) {
      contacts.value = rows;
    }
  } catch (e) {
    if (version === contactsSequence) {
      contactsError.value = e.message;
    }
  } finally {
    if (version === contactsSequence) {
      contactsLoading.value = false;
    }
  }
}

function contactLabel(contact) {
  return `${contact.fullName} · ${contact.phone}`;
}

async function loadDrivers() {
  const vehicleId = props.request.vehicleId;
  const version = ++driversSequence;

  drivers.value = [];
  driversError.value = '';

  if (!vehicleId) {
    driversLoading.value = false;

    return;
  }

  driversLoading.value = true;

  try {
    const rows = await marketplaceApi.vehicleDrivers(vehicleId);

    if (version === driversSequence) drivers.value = rows;
  } catch (e) {
    if (version === driversSequence) driversError.value = e.message;
  } finally {
    if (version === driversSequence) driversLoading.value = false;
  }
}

/* ─── Работы ─── */

async function addWork() {
  if (!canWork.value || !canAddWork.value) {
    return;
  }

  props.request.works.push(emptyWork());
  touch();
  await nextTick();
  document.querySelector('.works-grid tbody tr:last-child .work-type-picker input')?.focus();
}

function removeWork(index) {
  props.request.works.splice(index, 1);
  touch();
}

async function workTypeChanged(row, workType) {
  if (row === placeholderWork.value) {
    props.request.works.push(row);
    placeholderWork.value = emptyWork();
  }

  row.name = workType?.name || '';
  row.workCode = workType?.code || '';
  row.timeNormId = '';
  row.normId = '';
  row.normSelectionScope = '';
  row.normApplicabilityLabel = "";
  row._normApplicabilityLabel = "";
  row.normIsManual = false;
  row.normOverrideReason = '';
  row.normHours = null;
  row._normMessage = '';
  recalculateWork(row);
  touch();

  if (!workType?.id || !props.request.vehicleId) {
    row._normMessage = props.request.vehicleId
      ? ''
      : 'Сначала выберите автомобиль';

    return;
  }

  const selectedWorkTypeId = workType.id;
  row._normLoading = true;

  try {
    const result = await marketplaceApi.resolveTimeNorm(
      selectedWorkTypeId,
      props.request.vehicleId,
      row.qty || 1,
      props.request.laborRateType || "general",
    );

    if (row.workTypeId !== selectedWorkTypeId) {
      return;
    }

    const rateType = props.request.laborRateType || "general";
    const rate = Object.hasOwn(props.request.laborRates || {}, rateType)
      ? props.request.laborRates[rateType] : result.laborHourRate;
    row.hourlyRate = rate == null ? null : Number(rate);
    row.normSelectionScope = result.scope || '';

    row._normApplicabilityLabel = result.norm?.applicabilityLabel || "";
    row.normApplicabilityLabel = row._normApplicabilityLabel;

    if (result.status === 'selected') {
      row.timeNormId = result.norm?.id || '';
      row.normId = result.norm?.normId || '';
      row.normHours = result.norm?.hours ?? null;
      row.price = result.unitPrice == null ? null : Number(result.unitPrice);
      row.lineAmount = result.amount == null ? null : Number(result.amount);
      row._normMessage = row.normHours == null
        ? 'Для выбранной нормы часы не заполнены. Укажите ручное значение и причину.'
        : "";
    } else if (result.status === 'conflict') {
      const identifiers = (result.conflicts || [])
        .map((item) => item.normId)
        .filter(Boolean)
        .join(', ');
      row._normMessage = `Конфликт норм${identifiers ? `: ${identifiers}` : ""}. Исправьте применимость в справочнике.`;
    } else {
      row._normMessage = 'Подходящая норма не найдена. Укажите ручное значение и причину.';
    }

    recalculateWork(row);
    touch();
  } catch (error) {
    if (row.workTypeId === selectedWorkTypeId) {
      row._normMessage = error.message;
    }
  } finally {
    if (row.workTypeId === selectedWorkTypeId) {
      row._normLoading = false;
    }
  }
}

function workNameEdited(row, value) {
  if (row === placeholderWork.value && String(value || '').trim()) {
    props.request.works.push(row);
    placeholderWork.value = emptyWork();
  }

  row.name = value;
  row.workTypeId = '';
  row.workCode = '';
  row.timeNormId = '';
  row.normId = '';
  row.normSelectionScope = '';
  row.normIsManual = false;
  row.normOverrideReason = '';
  row.normHours = null;
  row._normMessage = '';
  recalculateWork(row);
  touch();
}

function manualNormChanged(row) {
  row.timeNormId = '';
  row.normIsManual = true;
  row.normSelectionScope = 'manual';
  recalculateWork(row);
  touch();
}

function manualNormReasonChanged(row) {
  touch();
}

function scopeLabel(scope) {
  return (
    {
    vehicle: 'конкретное ТС',
    generation_modification: 'поколение и модификация',
    generation: 'поколение',
    make_model: 'марка и модель',
    make: 'марка',
    general: 'общая',
  }[scope] || 'норма справочника');
}

function createWork(row, name) {
  emit('create-work', {
    name,
    rowId: row.id || '',
    clientId: row._clientId || '',
    index: props.request.works.indexOf(row),
  });
}

function laborRateChanged() {
  const rate = props.request.laborRates?.[props.request.laborRateType || "general"] ?? null;
  props.request.laborHourRate = rate == null ? null : Number(rate);
  for (const work of props.request.works || []) {
    work.hourlyRate = props.request.laborHourRate;
    recalculateWork(work);
  }
  touch();
}

function recalculateWork(work) {
  const rate = Number(work.hourlyRate ?? props.request.laborHourRate);
  const hours = Number(work.normHours);
  const quantity = Number(work.qty);
  const hasRate = work.hourlyRate != null || props.request.laborHourRate != null;
  const hasHours = work.normHours !== null && work.normHours !== '';
  const hasQuantity = Number.isFinite(quantity) && quantity > 0;

  work.hourlyRate = hasRate && Number.isFinite(rate) ? rate : null;
  work.price = hasRate && hasHours && Number.isFinite(rate) && Number.isFinite(hours)
    ? Number((rate * hours).toFixed(2))
    : null;
  work.lineAmount = work.price !== null && hasQuantity
    ? Number((work.price * quantity).toFixed(2))
    : null;
}

function workQuantityChanged(work) {
  recalculateWork(work);
  touch();
}

function workAmount(work) {
  return work.lineAmount == null ? null : Number(work.lineAmount);
}

/* ─── Диагностика ─── */

function diagnosticAttachmentIds() {
  return (props.request.attachments || [])
    .filter(
      (attachment) =>
        attachment.purpose === PURPOSE_DIAGNOSTIC
        && attachment.periodId === props.request.currentPeriodId,
    )
    .map((attachment) => attachment.id);
}

async function saveDiagnosis() {
  diagnosisError.value = '';

  try {
    await props.perform(PERFORM_DIAGNOSIS, {
      conclusion: conclusion.value,
      attachmentIds: diagnosticAttachmentIds(),
    });

    conclusion.value = '';
  } catch (e) {
    diagnosisError.value = e.message;
  }
}

/* ─── Переходы ─── */

async function openNext(confirmDeclinedStation = false) {
  if (props.dirty) {
    return;
  }

  if (isCustomer.value && props.request.stage === 0) {
    const attachmentIds = (props.request.attachments || [])
      .filter(
        (file) =>
          file.purpose === 'evidence'
          && file.periodId === props.request.currentPeriodId
          && !file.linked,
      )
      .map((file) => file.id);

    try {
      await props.perform('transition', TRANSITION_NEXT, {
        comment: props.request.routeComment || '',
        attachmentIds,
      });
    } catch {
      // Родительский компонент уже выводит сообщение API.
    }

    return;
  }

  if (managerRoutesToStation.value || managerReviewingStationChanges.value) {
    if (managerRoutesToStation.value && confirmDeclinedStation !== true && (props.request.declinedStationIds || []).includes(props.request.stationId)) {
      declinedStationDialog.value = true;
      return;
    }
    try {
      await props.perform('transition', TRANSITION_NEXT, {
        comment: '',
        attachmentIds: [],
        confirmDeclinedStation: confirmDeclinedStation === true,
      });
    } catch {
      // Родительский компонент уже выводит сообщение API.
    }

    return;
  }

  declinedStationDialog.value = false;
  transitionAction.value = TRANSITION_NEXT;
}

function openReturn() {
  if (props.dirty) {
    return;
  }

  transitionAction.value = TRANSITION_RETURN;
}

function closeTransition() {
  transitionAction.value = '';
}

async function openStationDecision(decision) {
  if (props.dirty) {
    return;
  }

  if (decision === STATION_CONFIRM) {
    try {
      await props.perform('stationDecision', decision, { comment: props.request.routeComment || '' ,
      });
    } catch { /* Сообщение API отображает родитель. */ }
    return;
  }
  stationDecision.value = decision;
}

function closeStationDecision() {
  stationDecision.value = '';
}

async function acceptStationSlot() {
  if (props.dirty) {
    return;
  }

  try {
    await props.perform('customerSlotDecision', 'accepted', {
      comment: '',
    });
  } catch {
    // Родительский компонент уже выводит сообщение API.
  }
}

watch(() => props.request.vehicleId, () => {
  loadContacts();
  loadDrivers();
},
);
onMounted(() => {
  loadContacts();
  loadDrivers();
});
</script>

<template>
  <section class="document-panel">
    <!-- Шапка -->
    <div class="document-head">
      <div>
        <span class="eyebrow">Заявка на ремонт</span>
        <h1>{{ request.number }}</h1>
        <span class="status" :class="statusClass(request.stage, request.permissions)">
          {{ step.status }}
        </span>
      </div>
      <div>
        <small>Создана {{ formatDateTime(request.createdAt) }}</small>
        <p v-if="request.overdueSeconds" class="overdue">
          Превышение планового срока: {{ duration(request.overdueSeconds) }}
        </p>
      </div>
    </div>

    <!-- 01. Автомобиль -->
    <div class="section-title">
      <span>01</span>
      <h3>Автомобиль</h3>
    </div>

    <VehiclePicker
      :request-id="request.id"
      :vehicle-id="request.vehicleId"
      :vehicle="selectedVehicle"
      :disabled="busy || !isCustomer || !isAllowed('vehicleId')"
      @select="selectVehicle"
      @history="$emit('vehicle-history', $event)"
    />

    <div class="form-grid vehicle-grid">
      <label>
        Текущий пробег, км
        <input
          v-model="request.mileage"
          :disabled="!isAllowed('mileage')"
          inputmode="numeric"
          placeholder="Введите пробег"
          @input="touch"
        />
      </label>

      <div class="previous-mileage-info full">
        <span>Предыдущий пробег и дата заполнения</span>
        <strong v-if="request.previousServiceMileage != null">
          {{ Number(request.previousServiceMileage).toLocaleString('ru-RU') }} км
        </strong>
        <strong v-else>Нет данных из предыдущих заявок</strong>
        <small v-if="request.previousServiceAt">
          {{ formatDateTime(request.previousServiceAt) }}
        </small>
      </div>
    </div>

    <!-- 02. Причина обращения -->
    <div class="section-title">
      <span>02</span>
      <h3>Причина обращения</h3>
    </div>

    <div class="form-grid">
      <label>
        Причина обращения
        <select
          v-model="request.reasonId"
          :disabled="!isAllowed('reasonId')"
          @change="reasonChanged"
        >
          <option value="">Выберите причину</option>
          <option v-for="reason in roots" :key="reason.id" :value="reason.id">
            {{ reason.name }}
          </option>
        </select>
      </label>

      <label v-if="!isCustomer">
        Подвид обращения
        <select
          v-model="request.reasonSubtypeId"
          :disabled="!isAllowed('reasonSubtypeId') || !subtypes.length"
          @change="touch"
        >
          <option value="">
            {{ subtypes.length ? 'Выберите подвид' : 'Без подвида' }}
          </option>
          <option
            v-for="subtype in subtypes"
            :key="subtype.id"
            :value="subtype.id"
          >
            {{ subtype.name }}
          </option>
        </select>
      </label>

      <label class="full">
        Дополнительная информация (необязательно)
        <textarea
          v-model="request.description"
          :disabled="!isAllowed('description')"
          placeholder="Укажите дополнительную информацию при необходимости"
          @input="touch"
        />
      </label>
    </div>

    <!-- 03. Контакт и дата визита -->
    <div class="section-title">
      <span>03</span>
      <h3>Контактное лицо, дата и время визита</h3>
    </div>

    <div class="form-grid">
      <label>
        Контактное лицо
        <select
          v-model="request.contactId"
          :disabled="
            !isAllowed('contactId') || !request.vehicleId || contactsLoading
          "
          @change="touch"
        >
          <option value="">
            {{
              !request.vehicleId
                ? 'Сначала выберите ТС'
                : contactsLoading
                  ? 'Загружаем контакты…'
                  : 'Выберите контактное лицо'
            }}
          </option>
          <option
            v-if="
              request.contactId &&
              !contacts.some((item) => item.id === request.contactId)
            "
            :value="request.contactId"
          >
            {{ request.contact || 'Выбранное контактное лицо' }}
          </option>
          <option
            v-for="contact in contacts"
            :key="contact.id"
            :value="contact.id"
          >
            {{ contactLabel(contact) }}
          </option>
        </select>
        <small v-if="contactsError" class="error-message">
          {{ contactsError }}
        </small>
      </label>

      <label>
        Водитель
        <select
          v-model="request.driverId"
          :disabled="
            !isAllowed('driverId') || !request.vehicleId || driversLoading
          "
          @change="touch"
        >
          <option value="">
            {{
              !request.vehicleId
                ? 'Сначала выберите ТС'
                : driversLoading
                  ? 'Загружаем водителей…'
                  : 'Выберите водителя'
            }}
          </option>
          <option
            v-if="
              request.driverId &&
              !drivers.some((item) => item.id === request.driverId)
            "
            :value="request.driverId"
          >
            {{ request.driver || 'Выбранный водитель' }}
          </option>
          <option
            v-for="driver in drivers"
            :key="driver.id"
            :value="driver.id"
          >
            {{ contactLabel(driver) }}
          </option>
        </select>
        <small v-if="driversError" class="error-message">
          {{ driversError }}
        </small>
      </label>

      <label v-if="!isCustomer">
        СТО-партнёр
        <select
          v-model="request.stationId"
          :disabled="!isAllowed('stationId')"
          @change="touch"
        >
          <option value="">Не выбрано</option>
          <option
            v-for="station in stations"
            :key="station.id"
            :value="station.id"
          >
            {{ station.name }}
          </option>
          <option
            v-if="
              request.stationId &&
              !stations.some((station) => station.id === request.stationId)
            "
            :value="request.stationId"
          >
            {{ request.station }}
          </option>
        </select>
      </label>

      <ConfirmedDateTimeInput
        v-model="request.slot"
        title="Желаемая дата и время визита"
        :disabled="!isAllowed('slot')"
        :min-time="VISIT_TIME_START"
        :max-time="VISIT_TIME_END"
        @confirm="touch"
      />

      <ConfirmedDateTimeInput
        v-model="request.stationSlot"
        title="Дата и время визита, подтверждённые СТО"
        :disabled="isCustomer || isManager || !isAllowed('stationSlot')"
        :min-time="VISIT_TIME_START"
        :max-time="VISIT_TIME_END"
        @confirm="touch"
      />

      <div class="calculated-completion-info">
        <span>Расчётное время завершения ремонта</span>
        <output>
          {{
            calculatedCompletionAt
              ? formatDateTime(calculatedCompletionAt)
              : 'Не рассчитано'
          }}
        </output>
      </div>
    </div>

    <!-- 04. Работы, запчасти и ремонт -->
    <section
      v-if="showRepairBlock"
      class="repair-calculation-section"
      :class="{ 'draft-disabled-section': draftRepairBlock }"
    >
      <div class="section-title">
        <span>04</span>
        <div>
          <h3>Работы, запчасти и ремонт</h3>
          <p v-if="draftRepairBlock">
            Блок станет активным после обработки заявки Управляющей компанией.
          </p>
        </div>
      </div>

      <p v-if="managerReviewingStationChanges" class="info-banner repair-consensus-banner">
        <template v-if="estimateChangedSinceReview">
          УК скорректировала работы или запчасти. Согласуйте итоговую редакцию
          для Заказчика либо верните в СТО с обязательным комментарием.
        </template>
        <template v-else>
          СТО изменила работы или запчасти. Если УК согласна с текущей редакцией,
          финальная заявка будет направлена Заказчику, а СТО получит уведомление.
        </template>
      </p>

      <label v-if="user.role === 'manager'" class="labor-rate-selector">Тариф нормо/час
        <select v-model="request.laborRateType" :disabled="
            !request.permissions?.editableFields?.includes('laborRateType') ||
            busy
          "
          @change="laborRateChanged">
          <option value="general">Общий</option><option value="official">Официальный</option><option value="other">Прочий</option>
        </select>
      </label>

      <p v-if="stationReviewingManagerChanges" class="info-banner repair-consensus-banner">
        <template v-if="estimateChangedSinceReview">
          СТО повторно изменила работы или запчасти. Эта редакция будет повторно
          направлена на согласование в УК.
        </template>
        <template v-else>
          УК скорректировала работы или запчасти. Примите изменения либо внесите
          новую редакцию для повторного согласования.
        </template>
      </p>

      <p
        v-if="isCustomer && request.stage === 3"
        class="info-banner repair-consensus-banner"
      >
        Работы, запчасти и время визита согласованы УК и СТО. Подтвердите
        финальную заявку, чтобы СТО ожидала автомобиль.
      </p>

      <div class="table-wrap excel-grid-wrap">
        <table class="excel-grid works-grid">
          <thead>
            <tr>
              <th v-if="canDefer" class="selection-col" />
              <th class="resizable-column">
                Наименование работы
                <span class="column-resize-handle" @pointerdown="startColumnResize" />
              </th>
              <th class="resizable-column">
                Норма времени
                <span class="column-resize-handle" @pointerdown="startColumnResize" />
              </th>
              <th class="resizable-column">
                Кол-во
                <span class="column-resize-handle" @pointerdown="startColumnResize" />
              </th>
              <th class="resizable-column">
                Стоимость нормо/часа, ₽
                <span class="column-resize-handle" @pointerdown="startColumnResize" />
              </th>
              <th class="resizable-column">
                Стоимость, ₽
                <span class="column-resize-handle" @pointerdown="startColumnResize" />
              </th>
              <th class="row-command-column" />
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(work, index) in displayedWorks"
              :key="work.id || work._clientId || index"
            >
              <td v-if="canDefer"><input v-if="work.id" v-model="selectedWorkIds" type="checkbox" :value="work.id" class="selection-checkbox"
                  :disabled="busy" aria-label="Выбрать работу для рекомендаций" /></td>
              <td :title="work.name">
                <WorkTypePicker
                  v-if="canWork"
                  v-model="work.workTypeId"
                  :name="work.name"
                  :options="references.workTypes"
                  :disabled="busy"
                  @select="workTypeChanged(work, $event)"
                  @edit="workNameEdited(work, $event)"
                  @create="createWork(work, $event)"
                />
                <span v-else>{{ work.name || '—' }}</span>
                <small
                  v-if="work.workTypeId || work.normId"
                  class="norm-applicability"
                  :title="
                    work.normApplicabilityLabel || work._normApplicabilityLabel
                  "
                  >{{
                    work.normIsManual
                      ? "Ручная норма для этой заявки"
                      : work.normApplicabilityLabel ||
                        work._normApplicabilityLabel ||
                        "Применимость не определена"
                  }}</small
                >
              </td>

              <td>
                <div v-if="canWork" class="norm-time-control">
                  <div class="manual-norm-field">
                    <input
                      v-model.number="work.normHours"
                      :title="String(work.normHours ?? '')"
                      type="number"
                      min="0.001"
                      step="0.001"
                      placeholder="Ввести вручную"
                      aria-label="Норма времени для этой заявки"
                      :disabled="busy || !work.workTypeId || work._normLoading"
                      @input="manualNormChanged(work)"
                    />
                    <InfoHint
                      text="Ручное значение сохраняется только в этой заявке."
                    />
                  </div>
                  <input
                    v-if="work.normIsManual"
                    v-model.trim="work.normOverrideReason"
                    :title="work.normOverrideReason"
                    class="norm-override-reason"
                    :disabled="busy"
                    type="text"
                    maxlength="2000"
                    required
                    placeholder="Причина ручной корректировки"
                    aria-label="Причина ручной корректировки нормы"
                    @input="manualNormReasonChanged(work)"
                  />
                  <small v-if="work._normLoading">Подбираем норму…</small>
                  <small
                    v-else-if="work._normMessage"
                    :title="work._normMessage"
                    :class="{
                      'norm-selection-error':
                        work._normMessage.startsWith('Конфликт') ||
                        work._normMessage.startsWith('Подходящая') ||
                        work._normMessage.startsWith('Для выбранной'),
                    }"
                  >
                    {{ work._normMessage }}
                  </small>
                </div>
                <span v-else>{{ work.normHours ?? '—' }}</span>
              </td>

              <td>
                <input
                  v-model.number="work.qty"
                  :title="String(work.qty ?? '')"
                  type="number"
                  min="0.001"
                  step="0.001"
                  :disabled="busy || !canWork || !work.workTypeId"
                  @input="workQuantityChanged(work)"
                />
              </td>

              <td>
                <input
                  :value="work.hourlyRate ?? request.laborHourRate ?? ''"
                  :title="
                    String(work.hourlyRate ?? request.laborHourRate ?? '')
                  "
                  type="number"
                  readonly
                  disabled
                />
              </td>

              <td>
                {{ workAmount(work) == null ? 'Не рассчитано' : money(workAmount(work)) }}
              </td>

              <td>
                <button
                  v-if="canWork && work !== placeholderWork"
                  type="button"
                  class="delete-row"
                  aria-label="Удалить работу"
                  :disabled="busy"
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
          v-if="canWork"
          type="button"
          class="grid-add-row"
          :disabled="busy || request.laborHourRate == null || !canAddWork"
          aria-label="Добавить строку работы"
          title="Добавить строку"
          @click="addWork"
        >
          ＋
        </button>

        <div class="total">
          <span>Работы и запчасти</span>
          <strong>{{ money(total(request)) }} ₽</strong>
        </div>
      </div>

      <div
        v-if="canDefer"
        class="recommendation-actions work-recommendation-actions"
      >
        <button
          class="ghost-btn"
          :disabled="busy || dirty || !selectedWorkIds.length"
          @click="deferSelected('work')"
        >
          Добавить работы в рекомендации ({{ selectedWorkIds.length }})
        </button>
      </div>
      <PartsPanel
        :request="request"
        :customer-view="isCustomer"
        :suppliers="references.suppliers"
        :catalog-parts="references.parts"
        :part-units="references.partUnits"
        :busy="busy"
        :perform="perform"
        :can-create-catalog-part="
          (isManager || isStation) && request.permissions.canEditParts
        "
        @create-part="$emit('create-part', $event)"
        :selectable="canDefer"
        v-model:selected-ids="selectedPartIds"
        @touch="touch"
      />
      <div class="recommendation-actions">
        <button v-if="canDefer" class="ghost-btn" :disabled="busy || dirty || !selectedPartIds.length"
          @click="deferSelected('part')"
        >Добавить ЗЧ в рекомендации({{ selectedPartIds.length }})</button>
        <button v-if="request.recommendations?.length" class="ghost-btn" @click="$emit('recommendations')">Рекомендации ТС ({{ request.recommendations.length }})</button>
      </div>
    </section>

    <!-- Диагностика -->
    <section
      v-if="showRepairBlock"
      class="feature-card"
      :class="{ 'draft-disabled-section': isCustomer && request.stage === 0 }"
    >
      <h3>Заключение диагностики СТО</h3>

      <article
        v-for="report in request.diagnosticReports"
        :key="report.id"
        class="diagnostic-entry"
      >
        <small>
          {{ formatDateTime(report.createdAt) }} · {{ report.mileage }} км
        </small>
        <p>{{ report.conclusion }}</p>
      </article>

      <p v-if="!request.diagnosticReports.length" class="muted">
        Заключение пока не сохранено.
      </p>

      <form v-if="canDiagnose" @submit.prevent="saveDiagnosis">
        <label>
          Результат диагностики
          <textarea v-model="conclusion" required :disabled="busy" />
        </label>

        <button class="primary-btn" :disabled="busy">
          Сохранить заключение
        </button>

        <p v-if="diagnosisError" class="error-message">
          {{ diagnosisError }}
        </p>
      </form>
    </section>

    <!-- Оплата -->
    <section v-if="showRepairBlock"
      :class="{ 'draft-disabled-section': isCustomer && request.stage === 0 }">
      <PaymentPanel
        :request="request"
        :busy="busy"
        :perform="perform"
      />
    </section>

    <!-- 05. Вложения -->
    <div class="section-title">
      <span>05</span>
      <h3>Фото, видео и документы</h3>
    </div>
    <AttachmentPanel
      :request="request"
      :busy="busy"
      :perform="perform"
      optional
      hide-title
    />

    <label v-if="isStation" class="feature-card route-comment-field">
      <span>Комментарий (необязательно)
      </span>
      <textarea v-model="request.routeComment" :disabled="
          busy || !request.permissions.editableFields.includes('routeComment')
        "
        @input="touch" />
    </label>
    <!-- 06. Приём ТС -->
    <AcceptanceActPanel
      :request="request"
      :busy="busy"
      :perform="perform"
    />

    <!-- Переход -->
    <div
      class="route-action"
      :class="{ 'route-action-compact': isManager || isCustomer }"
    >
      <div class="route-save-state">
        <div class="route-save-message">
          <strong v-if="dirty" class="unsaved-label">
            Изменения не сохранены
          </strong>
          <span v-else class="saved-label">Изменения сохранены</span>
        </div>
        <div class="route-save-buttons">
          <button
            type="button"
            class="office-action ghost-btn"
            :disabled="busy || !dirty"
            @click="$emit('save')"
           title="Сохранить" aria-label="Сохранить"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 3h12l4 4v14H3V3h2z M7 3v7h10V3 M7 21v-7h10v7" /></svg></button>
          <button
            type="button"
            class="office-action ghost-btn"
            :disabled="busy"
            @click="$emit('save-and-exit')"
           title="Сохранить и выйти" aria-label="Сохранить и выйти"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 3h11v6H7V3 M3 3v18h11 M15 12h7m-3-3 3 3-3 3" /></svg></button>
          <button
            type="button"
            class="office-action ghost-btn request-chat-button"
            :disabled="chatBusy"
            @click="$emit('chat')"
           :title="chatCreated ? 'Открыть чат' : 'Создать чат'" :aria-label="chatCreated ? 'Открыть чат' : 'Создать чат'"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h18v13H9l-6 4V4 M7 8h10 M7 12h7" /></svg><b v-if="chatUnread" class="request-chat-count">{{ chatUnread }}</b></button>
<button
            v-if="isStation"
            class="office-action ghost-btn danger-btn"
            :disabled="busy || dirty || !canStationDecide"
            @click="openStationDecision(STATION_DECLINE)"
           title="Отказаться от выполнения" aria-label="Отказаться от выполнения"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 1 0 0-18 M6 6l12 12" /></svg></button>
<button
            v-if="isStation"
            class="office-action ghost-btn"
            :disabled="busy || dirty || !canStationDecide"
            @click="openStationDecision(STATION_RESCHEDULE)"
           title="Предложить другое время" aria-label="Предложить другое время"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 1 0 0-18 M12 7v5l4 2" /></svg></button>
        </div>
      </div>

      <div>
        <span class="action-label">
          {{
            canStationDecide
              ? estimateChangedSinceReview || stationReviewingManagerChanges
                ? 'Согласование работ и запчастей'
                : 'Решение по времени': canCustomerSlotDecide ? 'Финальное согласование' : 'Следующий этап'}}
        </span>
        <strong v-if="canStationDecide">
          {{ stationDecisionPrompt }}
        </strong>
        <strong v-else-if="canCustomerSlotDecide">
          Подтвердите финальную заявку или предложите другую дату
        </strong>
        <strong v-else>{{ routeActionTitle }}</strong>
        <small v-if="dirty">Сначала сохраните внесённые изменения</small>
        <small v-else-if="canStationDecide">
          Комментарий к решению необязателен
        </small>
        <small v-else-if="canCustomerSlotDecide">
          При согласии заявка перейдёт в «Ожидание ТС на СТО»; иное время вернётся в УК
        </small>
        <small v-else-if="isStation">
          Комментарий обязателен; фото и видео можно приложить отдельно
        </small>
        <small v-else-if="!isCustomer && !isManager">
          Комментарий и фото/видео сохраняются вместе с решением
        </small>
      </div>

      <div class="action-buttons">
        <template v-if="canStationDecide">
          <button
            class="office-action primary-btn"
            :title="stationConfirmLabel" :aria-label="stationConfirmLabel"
            :disabled="busy || dirty"
            @click="openStationDecision(STATION_CONFIRM)"
          >
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-6-6 6 6-6 6" /></svg>
          </button>
        </template>

        <template v-else-if="canCustomerSlotDecide">
          <button
            class="office-action ghost-btn"
            title="Выбрать иную дату и время" aria-label="Выбрать иную дату и время"
            :disabled="busy || dirty"
            @click="customerSlotDialog = true"
          >
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16v16H4V5 M8 3v4 M16 3v4 M4 10h16 M8 14h3 M14 14h2 M8 18h3" /></svg>
          </button>

          <button
            class="office-action primary-btn"
            title="Подтвердить финальную заявку" aria-label="Подтвердить финальную заявку"
            :disabled="busy || dirty"
            @click="acceptStationSlot"
          >
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-6-6 6 6-6 6" /></svg>
          </button>
        </template>

        <button
          v-if="
            !canStationDecide &&
            !canCustomerSlotDecide &&
            request.permissions.canReturn
          "
          class="office-action ghost-btn"
          :title="
            managerReviewingStationChanges
              ? 'Вернуть в СТО для корректировки'
              : 'Вернуть'
          "
          :aria-label="
            managerReviewingStationChanges
              ? 'Вернуть в СТО для корректировки'
              : 'Вернуть'
          "
          :disabled="busy || dirty"
          @click="openReturn"
        >
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12H4m6-6-6 6 6 6" /></svg>
        </button>

        <button
          v-if="
            !canStationDecide &&
            !canCustomerSlotDecide &&
            request.permissions.canAdvance
          "
          class="office-action primary-btn"
          :title="
            managerReviewingStationChanges
              ? routeActionTitle
              : 'Далее по маршруту'
          "
          :aria-label="
            managerReviewingStationChanges
              ? routeActionTitle
              : 'Далее по маршруту'
          "
          :disabled="busy || dirty"
          @click="openNext"
        >
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-6-6 6 6-6 6" /></svg>
        </button>
      </div>
    </div>

    <dialog v-if="declinedStationDialog" open class="action-dialog repeat-station-dialog">
      <p>Выбранное СТО ранее отказалось от выполнения этой заявки. Направить заявку повторно?</p>
      <div class="action-buttons">
        <button type="button" class="primary-btn" :disabled="busy" @click="
            openNext(true);
            declinedStationDialog = false;
          "
        >Продолжить</button>
        <button type="button" class="ghost-btn" @click="declinedStationDialog = false">Вернуть</button>
      </div>
    </dialog>
    <!-- Диалог перехода -->
    <TransitionDialog
      v-if="transitionAction"
      :request="request"
      :action="transitionAction"
      :busy="busy"
      :perform="perform"
      :optional-decision="
        (isCustomer && request.stage === 0) ||
        (isManager &&
          !(
            managerReviewingStationChanges &&
            transitionAction === TRANSITION_RETURN
          ))
      "
      :hide-attachments="isManager || isStation"
      @close="closeTransition"
    />

    <StationDecisionDialog
      v-if="stationDecision"
      :request="request"
      :decision="stationDecision"
      :busy="busy"
      :perform="perform"
      @close="closeStationDecision"
    />

    <CustomerSlotDecisionDialog
      v-if="customerSlotDialog"
      :request="request"
      :busy="busy"
      :perform="perform"
      @close="customerSlotDialog = false"
    />
  </section>
</template>
