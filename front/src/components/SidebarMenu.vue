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
        :class="{ active: active?.kind === 'edo' }"
        @click="$emit('open-edo')"
      >
        ⇄ Документы ЭДО
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
        :class="{ active: active?.kind === 'report' }"
        @click="$emit('open-report')"
      >
        ▦ Отчёт
      </button>

      <!-- Справочники: по клику — всплывающий список, набор зависит от ЛК -->
      <template v-if="catalogKeys.length">
        <button
          ref="catalogButton"
          class="nav-item nav-group"
          :class="{ active: catalogActive, open: catalogsOpen }"
          aria-haspopup="menu"
          :aria-expanded="catalogsOpen"
          @click="toggleCatalogs"
        >
          ☷ Справочники
          <span class="nav-chevron" aria-hidden="true">▸</span>
        </button>
        <Teleport to="body">
          <div
            v-if="catalogsOpen"
            ref="catalogPopup"
            class="catalog-popup"
            role="menu"
            aria-label="Справочники"
            :style="popupStyle"
            @keydown.esc.stop="closeCatalogs(true)"
          >
            <span class="catalog-popup-caption">Справочники</span>
            <button
              v-for="key in catalogKeys"
              :key="key"
              class="catalog-popup-item"
              :class="{ active: active?.key === key }"
              role="menuitem"
              @click="selectCatalog(key)"
            >
              {{ catalogNames[key] }}
            </button>
          </div>
        </Teleport>
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

const POPUP_GAP = 6;
const VIEWPORT_PAD = 8;
const POPUP_WIDTH = 260;

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
    "open-edo",
    "open-report",
    "open-notification",
    "open-unread-chat",
    "collapse",
  ],
  data() {
    return {
      catalogNames,
      catalogsOpen: false,
      popupStyle: {},
    };
  },
  computed: {
    catalogActive() {
      return this.catalogKeys.includes(this.active?.key);
    },
  },
  watch: {
    "panel.menu"(value) {
      if (!value) this.closeCatalogs();
    },
  },
  beforeUnmount() {
    this.unbindPopupListeners();
  },
  methods: {
    formatDateTime,
    toggleCatalogs() {
      if (this.catalogsOpen) this.closeCatalogs();
      else this.openCatalogs();
    },
    openCatalogs() {
      this.catalogsOpen = true;
      this.placePopup();
      this.$nextTick(() => {
        this.placePopup();
        this.$refs.catalogPopup?.querySelector("button")?.focus({ preventScroll: true });
      });
      this.bindPopupListeners();
    },
    closeCatalogs(returnFocus = false) {
      if (!this.catalogsOpen) return;
      this.catalogsOpen = false;
      this.unbindPopupListeners();
      if (returnFocus) this.$refs.catalogButton?.focus();
    },
    selectCatalog(key) {
      this.closeCatalogs();
      this.$emit("open-list", key);
    },
    /* Справа от пункта меню; если места справа нет (мобильный вид) — под пунктом. */
    placePopup() {
      const button = this.$refs.catalogButton;
      if (!button) return;
      const rect = button.getBoundingClientRect();
      const vw = window.innerWidth;
      const vh = window.innerHeight;
      const popupHeight = this.$refs.catalogPopup?.offsetHeight || 0;
      let left = rect.right + POPUP_GAP;
      let top = rect.top;
      let width = POPUP_WIDTH;
      if (left + width > vw - VIEWPORT_PAD) {
        left = Math.max(VIEWPORT_PAD, rect.left);
        width = Math.min(Math.max(rect.width, POPUP_WIDTH), vw - left - VIEWPORT_PAD);
        top = rect.bottom + POPUP_GAP;
        if (popupHeight && top + popupHeight > vh - VIEWPORT_PAD) {
          top = Math.max(VIEWPORT_PAD, rect.top - POPUP_GAP - popupHeight);
        }
      } else if (popupHeight && top + popupHeight > vh - VIEWPORT_PAD) {
        top = Math.max(VIEWPORT_PAD, vh - VIEWPORT_PAD - popupHeight);
      }
      this.popupStyle = {
        top: `${Math.round(top)}px`,
        left: `${Math.round(left)}px`,
        width: `${Math.round(width)}px`,
        maxHeight: `${Math.round(vh - VIEWPORT_PAD * 2)}px`,
      };
    },
    onDocumentPointer(event) {
      const target = event.target;
      if (this.$refs.catalogPopup?.contains(target)) return;
      if (this.$refs.catalogButton?.contains(target)) return;
      this.closeCatalogs();
    },
    onDocumentKey(event) {
      if (event.key === "Escape") this.closeCatalogs(true);
    },
    onViewportChange(event) {
      if (event?.target && this.$refs.catalogPopup?.contains(event.target)) return;
      this.closeCatalogs();
    },
    bindPopupListeners() {
      document.addEventListener("pointerdown", this.onDocumentPointer, true);
      document.addEventListener("keydown", this.onDocumentKey);
      window.addEventListener("resize", this.onViewportChange);
      window.addEventListener("scroll", this.onViewportChange, true);
    },
    unbindPopupListeners() {
      document.removeEventListener("pointerdown", this.onDocumentPointer, true);
      document.removeEventListener("keydown", this.onDocumentKey);
      window.removeEventListener("resize", this.onViewportChange);
      window.removeEventListener("scroll", this.onViewportChange, true);
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

.nav-group.open {
  background: #dbe7f7;
}

.catalog-popup {
  position: fixed;
  z-index: 1000;
  display: grid;
  align-content: start;
  gap: 2px;
  padding: 6px;
  overflow-y: auto;
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 8px;
  box-shadow: 0 10px 28px rgba(18, 34, 56, 0.18);
}

.catalog-popup-caption {
  padding: 6px 10px 4px;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: #6e7c8e;
}

.catalog-popup-item {
  width: 100%;
  text-align: left;
  background: transparent;
  border: 0;
  border-radius: 6px;
  padding: 10px;
  color: #32455d;
  font-size: 14px;
  line-height: 1.4;
  cursor: pointer;
}

.catalog-popup-item:hover,
.catalog-popup-item:focus-visible {
  background: #dbe7f7;
  outline: none;
}

.catalog-popup-item.active {
  background: var(--brand-teal-soft);
  color: var(--brand-navy);
  font-weight: 650;
  box-shadow: inset 3px 0 var(--brand-teal);
}
</style>
