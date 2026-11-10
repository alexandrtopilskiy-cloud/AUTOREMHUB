<script setup>
/**
 * Сервисное сообщение формы (ошибка проверки, ответ сервера).
 * Ставится вверху формы сразу под шапкой; при появлении и смене текста
 * прокручивает форму так, чтобы сообщение было видно.
 */
import { onMounted, onUpdated, ref } from "vue";

const el = ref(null);

function reveal() {
    el.value?.scrollIntoView?.({ block: "nearest", behavior: "smooth" });
}

onMounted(reveal);
onUpdated(reveal);
</script>

<template>
    <p ref="el" class="form-top-alert" role="alert"><slot /></p>
</template>
