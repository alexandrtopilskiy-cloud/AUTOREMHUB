<script>
export default {
    name: "WorkspaceTabs",
    props: {
        tabs: { type: Array, required: true },
        activeId: { type: String, required: true },
        pending: { type: Object, default: () => ({}) },
    },
    emits: ["activate", "close"],
};
</script>

<template>
    <div class="virtual-tabs" role="tablist">
        <div
            v-for="t in tabs"
            :key="t.id"
            class="virtual-tab"
            :class="{ active: t.id === activeId }"
        >
            <button
                :title="t.title"
                role="tab"
                :aria-selected="t.id === activeId"
                @click="$emit('activate', t.id)"
            >
                {{ t.title }}{{ pending[t.recordId] ? " •" : "" }}
            </button>
            <button
                class="tab-close"
                :aria-label="'Закрыть ' + t.title"
                @click="$emit('close', t.id)"
            >
                ×
            </button>
        </div>
    </div>
</template>
