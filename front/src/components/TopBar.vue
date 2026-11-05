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
  emits: ["logout"],
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
</style>
