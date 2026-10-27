<script>
import {
  money,
  total,
  formatDateTime,
  catalogFields,
} from "../data/permissions.js";
import { workflow, statusClass } from "../data/workflow.js";

const WEEKDAY_LABELS = ["", "Пн.", "Вт.", "Ср.", "Чт.", "Пт.", "Сб.", "Вск."];

const REQUEST_COLUMNS = {
  document: "Документ / дата",
  vehicle: "Автомобиль / заказчик",
  status: "Статус",
  amount: "Сумма, ₽",
};

const REQUEST_FILTER_PLACEHOLDERS = {
  document: "Номер или дата",
  vehicle: "ТС, госномер, заказчик",
  status: "Статус или кабинет",
  amount: "Сумма",
};

export default {
  name: "RegistryTable",
  // 1. Явно объявляем события, чтобы Vue не ругался
  emits: [
    "select",
    "open-listed",
    "toggle-all-drafts",
    "toggle-draft-selection",
  ],
  props: {
    active: { type: Object, required: true },
    listed: { type: Array, required: true },
    isRequestList: { type: Boolean, default: false },
    user: { type: Object, required: true },
    store: { type: Object, required: true },
    selectableDrafts: { type: Array, default: () => [] },
    selectedDraftIds: { type: Array, default: () => [] },
    allVisibleDraftsSelected: { type: Boolean, default: false },
    activeFiltersApplied: { type: Boolean, default: false },
  },
  data() {
    return {
      requestColumns: REQUEST_COLUMNS,
    };
  },
  methods: {
    money,
    total,
    formatDateTime,
    statusClass,

    step(stage) {
      return workflow[stage];
    },

    columns(key) {
      const fields = { ...(catalogFields[key] || {}) };
      if (key === "vehicles" && this.user?.role === "customer") {
        delete fields.company;
        delete fields.laborHourRate;
        delete fields.laborHourRateOfficial;
        delete fields.laborHourRateOther;
      }
      return fields;
    },

    requestFilterPlaceholder(key) {
      return REQUEST_FILTER_PLACEHOLDERS[key] || "Фильтр";
    },

    cell(item, key) {
      if (item.kind === "partManufacturers" && key === "status") {
        return (
          {
            approved: "Согласован",
            pending: "Ожидает согласования УК",
            rejected: "Отклонён",
          }[item.status] || item.status
        );
      }

      const value = item[key];

      if (
        item.kind === "workTypes" &&
        key === "laborHourRate" &&
        (value === null || value === undefined || value === "")
      ) {
        return "Не указана";
      }

      if (key.endsWith("At")) {
        return formatDateTime(value);
      }
      if (
        ["parentId", "workTypeId", "supplierId", "manufacturerId"].includes(key)
      ) {
        const rows = [
          ...this.store.records,
          ...Object.values(this.store.references).flat(),
        ];
        return rows.find((r) => r.id === value)?.name || "—";
      }
      if (typeof value === "boolean") return value ? "Да" : "Нет";
      if (Array.isArray(value)) {
        if (key === "workingDays") {
          return value
            .map((day) => WEEKDAY_LABELS[Number(day)] || day)
            .join(", ");
        }
        if (key === "accessRights") {
          const labels = Object.fromEntries(
            (this.store.references.accessRights || []).map((right) => [
              right.value,
              right.label,
            ]),
          );
          return value.map((entry) => labels[entry] || entry).join(", ");
        }
        return value
          .map((entry) => entry.fullName || entry.name || entry)
          .join(", ");
      }
      return value === 0 ? 0 : value || "—";
    },
  },
};
</script>

<template>
  <div>
    <div class="registry-wrap">
      <table class="registry">
        <thead>
          <tr v-if="isRequestList">
            <th v-if="active.key === 'requests'" class="selection-col">
              <input
                type="checkbox"
                aria-label="Выбрать все доступные черновики"
                :checked="allVisibleDraftsSelected"
                :disabled="!selectableDrafts.length"
                @change="$emit('toggle-all-drafts')"
              />
            </th>
            <th v-for="(name, key) in requestColumns" :key="key">
              {{ name }}
            </th>
            <th />
          </tr>
          <tr v-else>
            <th v-for="(name, key) in columns(active.key)" :key="key">
              {{ name }}
            </th>
            <th />
          </tr>
          <tr v-if="isRequestList" class="column-filter-row request-filter-row">
            <th v-if="active.key === 'requests'" class="selection-col" />
            <th v-for="(name, key) in requestColumns" :key="key">
              <input
                v-model="active.filters[key]"
                :aria-label="`Фильтр: ${name}`"
                :placeholder="requestFilterPlaceholder(key)"
              />
            </th>
            <th />
          </tr>
          <tr v-else class="column-filter-row">
            <th v-for="(name, key) in columns(active.key)" :key="key">
              <input
                v-model="active.filters[key]"
                :aria-label="`Фильтр: ${name}`"
                placeholder="Фильтр"
              />
            </th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="item in listed"
            :key="item.id"
            tabindex="0"
            :class="{ selected: active.selection === item.id }"
            @click="$emit('select', item)"
            @dblclick="$emit('open-listed', item)"
            @keydown.enter="$emit('open-listed', item)"
          >
            <template v-if="isRequestList">
              <td v-if="active.key === 'requests'" class="selection-col">
                <input
                  v-if="item.permissions?.canDeleteDraft"
                  type="checkbox"
                  aria-label="Выбрать черновик для удаления"
                  :checked="selectedDraftIds.includes(item.id)"
                  @click.stop
                  @change="$emit('toggle-draft-selection', item.id)"
                />
              </td>
              <td>
                <strong>{{ item.number }}</strong
                ><small>{{ formatDateTime(item.createdAt) }}</small>
              </td>
              <td>
                <strong>{{ item.vehicle || "Автомобиль не выбран" }}</strong>
                <small>
                  {{ item.plate || "Пустой черновик" }}
                  <template v-if="item.company && user.role !== 'station'">
                    ·{{ item.company }}
                  </template>
                </small>
              </td>
              <td>
                <span
                  class="status"
                  :class="statusClass(item.stage, item.permissions)"
                >
                  {{ item.statusLabel || step(item.stage).status }}
                </span>
                <small>{{ step(item.stage).owner }}</small>
                <small v-if="item.overdueSeconds" class="overdue">
                  Превышен плановый срок
                </small>
              </td>
              <td>
                {{ money(total(item)) }}
              </td>
            </template>
            <template v-else>
              <td v-for="(label, key) in columns(active.key)" :key="key">
                {{ cell(item, key) }}
              </td>
            </template>
            <td>
              <button
                v-if="active.key !== 'changeRegistry'"
                class="open-row"
                :aria-label="
                  'Открыть ' + (item.number || item.vehicle || item.name)
                "
                @click.stop="$emit('open-listed', item)"
              >
                ↗
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="!listed.length" class="empty-state">
      <h3>{{ store.loading ? "Загружаем данные…" : "Записей пока нет" }}</h3>
      <p>
        {{
          activeFiltersApplied
            ? "Измените параметры фильтрации."
            : isRequestList
              ? "Новая заявка откроется с пустыми полями."
              : "Справочник ещё не заполнен."
        }}
      </p>
    </div>
  </div>
</template>
