<template>
  <aside class="area menu-area" :class="{ collapsed: !panel.menu }">
    <header class="area-head">
      <h2 v-if="panel.menu">Меню</h2>
      <button
        class="collapse-btn"
        :aria-expanded="panel.menu"
        @click="$emit('collapse', 'menu')"
      >
        {{ panel.menu ? "‹" : "›" }}
      </button>
    </header>

    <div v-if="panel.menu" class="area-scroll menu-scroll">
      <span class="nav-caption">Документы</span>
      <button
        class="nav-item"
        :class="{ active: active?.key === 'requests' }"
        @click="$emit('open-list', 'requests')"
      >
        ▤ Заявки на ремонт <b>{{ requests.length }}</b>
      </button>
      <button
        v-if="user.role === 'customer'"
        class="nav-item"
        :class="{
          active: active?.kind === 'recommendations' && !active.vehicleId,
        }"
        @click="$emit('open-recommendations')"
      >
        ☰ Рекомендации
      </button>
      <button
        class="nav-item"
        :class="{ active: active?.key === 'action' }"
        @click="$emit('open-list', 'action')"
      >
        ◷ Ожидают действия <b>{{ awaiting }}</b>
      </button>
      <button
        class="nav-item"
        :class="{ active: active?.key === 'archive' }"
        @click="$emit('open-list', 'archive')"
      >
        ▣ Архив
      </button>
      <button
        class="nav-item"
        :class="{ active: active?.kind === 'report' }"
        @click="$emit('open-report')"
      >
        ▦ Отчёт
      </button>

      <!-- Справочники: один выпадающий пункт, набор зависит от ЛК -->
      <template v-if="catalogKeys.length">
        <button
          class="nav-item nav-group"
          :class="{ active: catalogActive && !catalogsOpen }"
          :aria-expanded="catalogsOpen"
          @click="toggleCatalogs"
        >
          ☷ Справочники
          <span class="nav-chevron" aria-hidden="true">{{ catalogsOpen ? "▾" : "▸" }}</span>
        </button>
        <div v-if="catalogsOpen" class="nav-submenu">
          <button
            v-for="key in catalogKeys"
            :key="key"
            class="nav-item"
            :class="{ active: active?.key === key }"
            @click="$emit('open-list', key)"
          >
            {{ catalogNames[key] }}
          </button>
        </div>
      </template>

      <!-- Уведомления -->
      <span class="nav-caption notification-caption">
        Уведомления
        <b v-if="unreadNotifications">{{ unreadNotifications }}</b>
      </span>
      <div class="notification-list">
        <button
          v-for="item in notifications.slice(0, 5)"
          :key="item.id"
          class="notification-item"
          :class="{ unread: !item.readAt }"
          @click="$emit('open-notification', item)"
        >
          <strong>{{ item.title }}</strong>
          <small>{{ item.message }}</small>
          <time>{{ formatDateTime(item.createdAt) }}</time>
        </button>
        <p v-if="!notifications.length" class="muted">Новых уведомлений нет.</p>
      </div>

      <!-- Чат -->
      <span class="nav-caption notification-caption">
        Сообщения по заявкам
        <b v-if="chatUnreadTotal">{{ chatUnreadTotal }}</b>
      </span>
      <div v-if="unreadChatSummaries.length" class="notification-list">
        <button
          v-for="chat in unreadChatSummaries.slice(0, 5)"
          :key="chat.requestId"
          class="notification-item unread"
          @click="$emit('open-unread-chat', chat)"
        >
          <strong>{{ chat.requestNumber }} · чат</strong>
          <small>Непрочитанных сообщений: {{ chat.unreadCount }}</small>
        </button>
      </div>

      <div class="menu-info">
        <strong>{{ user.organization }}</strong>
        <p>{{ user.label }}</p>
      </div>
    </div>

    <button v-else class="vertical-label" @click="$emit('collapse', 'menu')">
      Меню
    </button>
  </aside>
</template>

<script>
import { formatDateTime, catalogNames } from "../data/permissions";

const CATALOGS_OPEN_KEY = "autoremhub.menu.catalogsOpen";

function readCatalogsOpen() {
  try {
    return localStorage.getItem(CATALOGS_OPEN_KEY) === "1";
  } catch {
    return false;
  }
}

export default {
  name: "SidebarMenu",
  props: {
    user: { type: Object, required: true },
    active: { type: Object, default: null },
    panel: { type: Object, required: true },
    requests: { type: Array, default: () => [] },
    awaiting: { type: Number, default: 0 },
    notifications: { type: Array, default: () => [] },
    unreadNotifications: { type: Number, default: 0 },
    unreadChatSummaries: { type: Array, default: () => [] },
    chatUnreadTotal: { type: Number, default: 0 },
    catalogKeys: { type: Array, default: () => [] },
  },
  emits: [
    "open-list",
    "open-recommendations",
    "open-report",
    "open-notification",
    "open-unread-chat",
    "collapse",
  ],
  data() {
    return {
      catalogNames,
      catalogsOpen: readCatalogsOpen() || this.catalogKeys.includes(this.active?.key),
    };
  },
  computed: {
    catalogActive() {
      return this.catalogKeys.includes(this.active?.key);
    },
  },
  watch: {
    catalogActive(value) {
      if (value) this.catalogsOpen = true;
    },
  },
  methods: {
    formatDateTime,
    toggleCatalogs() {
      this.catalogsOpen = !this.catalogsOpen;
      try {
        localStorage.setItem(CATALOGS_OPEN_KEY, this.catalogsOpen ? "1" : "0");
      } catch {
        /* хранилище недоступно — состояние только в памяти */
      }
    },
  },
};
</script>

<style scoped>
.nav-group {
  display: flex;
  align-items: center;
}

.nav-chevron {
  margin-left: auto;
  font-size: 12px;
  color: var(--muted);
}

.nav-submenu {
  display: grid;
  margin: 0 0 4px 14px;
  padding-left: 8px;
  border-left: 2px solid var(--line);
}
</style>
