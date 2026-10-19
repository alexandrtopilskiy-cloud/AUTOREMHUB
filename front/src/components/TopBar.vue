<script setup>
import { roles } from '../data/workflow'

defineProps({ role: { type: String, required: true } })
defineEmits(['update:role'])
</script>

<template>
  <header class="topbar">
    <div class="brand brand-topbar">
      <img
        class="brand-logo"
        src="/autoremhub-logo.png"
        width="1000"
        height="188"
        alt="AUTOREMHUB"
      >
      <div class="brand-context"><small>Единый контур ремонта</small></div>
    </div>
    <nav class="role-switch" aria-label="Выбор личного кабинета">
      <button v-for="(item, key, index) in roles" :key="key" class="role-btn" :class="{ active: role === key }" @click="$emit('update:role', key)">
        <span>0{{ index + 1 }}</span> {{ key === 'customer' ? 'Заказчик' : key === 'manager' ? 'Управляющая компания' : 'СТО-партнёр' }}
      </button>
    </nav>
    <div class="user-chip">
      <span class="avatar">{{ roles[role].name.split(' ').map(word => word[0]).join('').slice(0, 2) }}</span>
      <span><strong>{{ roles[role].name }}</strong><small>{{ roles[role].org }}</small></span>
    </div>
  </header>
</template>
