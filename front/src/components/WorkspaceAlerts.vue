<script>
export default {
    name: "WorkspaceAlerts",
    props: {
        fatal: String,
        saveError: String,
        conflict: Boolean,
        missingLaborHourRate: Boolean,
        user: Object,
        current: Object,
    },
    emits: ["refresh", "resolve"],
};
</script>

<template>
    <div v-if="fatal || saveError" role="alert" class="error-banner">
        {{ fatal || saveError }}
        <button @click="$emit('refresh')">Повторить</button>
    </div>
    <div v-if="conflict" class="conflict-banner">
        <strong>Заявка изменилась в другом кабинете</strong>
        <button @click="$emit('resolve', 'mine')">Сохранить мои поля</button>
        <button @click="$emit('resolve', 'server')">
            Принять серверную версию
        </button>
    </div>
    <div v-if="missingLaborHourRate" class="warning-banner">
        <strong>Стоимость нормо/часа не указана.</strong>
        <template v-if="user?.role === 'manager'">
            Откройте «Справочник ТС Заказчиков», заполните стоимость для VIN
            {{ current?.vin }} и сохраните запись.
        </template>
        <template v-else>
            Обратитесь в УК: стоимость для VIN {{ current?.vin }} заполняется в
            справочнике ТС.
        </template>
    </div>
</template>
