<template>
  <header class="topbar">
    <div class="brand brand-topbar">
      <div class="brand-logo-stack">
        <img
          class="brand-logo"
          src="/autoremhub-logo.png"
          width="1000"
          height="188"
          alt="AUTOREMHUB"
        />
        <small class="app-version">{{ appVersion }}</small>
      </div>
      <div class="brand-context">
        <small>Личный кабинет</small>
      </div>
    </div>

    <div class="role-badge">{{ userRoleLabel }}</div>

    <!-- Кнопка ЭДО (если её пока нет, мы добавим её позже) -->
    <button class="topbar-edo-btn" @click="$emit('open-edo')">
      Документы ЭДО
    </button>

    <div class="user-chip">
      <span class="avatar">{{ userAvatarInitials }}</span>
      <div>
        <strong>{{ userDisplayName }}</strong>
        <small>{{ userOrganization }}</small>
      </div>
      <button
        class="logout-btn"
        :disabled="logoutBusy || busy"
        @click="$emit('logout')"
      >
        Выйти
      </button>
    </div>
  </header>
</template>

<script>
export default {
  name: "TopBar",
  props: {
    user: { type: Object, required: true },
    busy: { type: Boolean, default: false },
    logoutBusy: { type: Boolean, default: false },
    appVersion: { type: String, default: "" },
  },
  emits: ["logout", "open-edo"],
  computed: {
    userAvatarInitials() {
      if (!this.user?.login) return "";
      return this.user.login.slice(0, 2).toUpperCase();
    },
    userDisplayName() {
      if (!this.user) return "";
      return this.user.fullName || this.user.displayName || this.user.login;
    },
    userOrganization() {
      return this.user?.organization || "";
    },
    userRoleLabel() {
      return this.user?.label || "";
    },
  },
};
</script>

<style scoped>
/* 
   Сюда нужно перенести стили для .topbar, .brand-topbar, .role-badge, 
   .user-chip, .avatar, .logout-btn и т.д. из App.vue.
   Пока можно оставить пустым, если стили глобальные.
*/
.topbar-edo-btn {
  padding: 6px 16px;
  background: #e8f5e9;
  color: #2e7d32;
  border: 1px solid #c8e6c9;
  border-radius: 4px;
  font-weight: 600;
  font-size: 13px;
  cursor: pointer;
  margin-left: 12px;
  transition: background 0.2s;
}
.topbar-edo-btn:hover {
  background: #c8e6c9;
}
</style>
