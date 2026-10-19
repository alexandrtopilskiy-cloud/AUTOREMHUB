<script setup>
import { routeStages, visualStage } from '../data/workflow'
defineProps({ request: Object, step: Object })
</script>

<template>
  <aside class="route-panel">
    <div class="panel-head"><div><p class="eyebrow">Маршрут документа</p><h2>Текущий процесс</h2></div><span class="live-dot">активен</span></div>
    <div class="owner-card"><span class="owner-icon">{{ step.owner === 'Управляющая компания' ? 'УК' : step.owner === 'СТО-партнёр' ? 'СТО' : 'З' }}</span><div><small>Сейчас у участника</small><strong>{{ step.owner }}</strong></div></div>
    <div class="timeline">
      <div v-for="(stage, index) in routeStages" :key="stage.title" class="timeline-item" :class="{ done: index < visualStage(request.stage), active: index === visualStage(request.stage) }">
        <i class="timeline-dot"></i><strong>{{ stage.title }}</strong><small>{{ stage.owner }} · {{ stage.note }}</small>
      </div>
    </div>
    <div class="rules-card"><h3>Правила контура</h3><ul><li>Между кабинетами движется одна заявка</li><li>Цена и разрешение на выдачу — через УК</li><li>Причина отказа или возврата обязательна</li><li>Каждое действие фиксируется в журнале</li></ul></div>
  </aside>
</template>
