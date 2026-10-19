<script setup>
import { matchesReference } from "../utils/referenceSearch.js"
import { computed, ref } from 'vue'
import { roles, statusClass, workflow } from '../data/workflow'
import { formatDateTime } from '../composables/useRequests'

const props = defineProps({ requests: Array, currentId: String, role: String })
defineEmits(['open', 'create'])
const search = ref('')
const visible = computed(() => {
  const query = search.value.toLowerCase().trim()
  return props.requests.filter(item => matchesReference([item.id, item.vehicle, item.plate, item.company].join(' '), query))
})
</script>

<template>
  <aside class="queue-panel">
    <div class="panel-head"><div><p class="eyebrow">Рабочая очередь</p><h1>{{ roles[role].queue }}</h1></div></div>
    <label class="search"><span>⌕</span><input v-model="search" type="search" placeholder="Номер, авто или госномер" /></label>
    <div class="queue-filters"><button class="filter active">В работе <b>{{ requests.length }}</b></button></div>
    <div class="request-list">
      <button v-for="item in visible" :key="item.id" class="request-card" :class="{ active: item.id === currentId }" @click="$emit('open', item.id)">
        <div class="card-line"><strong>{{ item.id }}</strong><span class="status" :class="statusClass(item.stage)">{{ workflow[item.stage].status }}</span></div>
        <h3>{{ item.vehicle || 'Автомобиль не указан' }}</h3>
        <p>{{ [item.plate, item.reason].filter(Boolean).join(' · ') || 'Новая незаполненная заявка' }}</p>
        <div class="card-foot"><span>{{ item.company || 'Организация не указана' }}</span><time>{{ formatDateTime(item.updatedAt) }}</time></div>
      </button>
    </div>
    <button class="new-request" @click="$emit('create')">＋ Новая заявка</button>
  </aside>
</template>
