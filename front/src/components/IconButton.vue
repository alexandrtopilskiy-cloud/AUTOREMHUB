<script>
/**
 * Кнопка-значок: вместо текста — иконка, назначение кнопки показывается
 * подсказкой при наведении (и при фокусе с клавиатуры).
 */
const ICONS = {
    // Дискета.
    save: '<path d="M5 3h11l3 3v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M7 3v5h8V3"/><rect x="7" y="13" width="10" height="6" rx="1"/>',
    // Дискета со стрелкой «выход».
    "save-exit": '<path d="M13 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9l3 3v4"/><path d="M7 3v4h6V3"/><path d="M15 17h7"/><path d="m19 14 3 3-3 3"/>',
    // Галочка в круге.
    post: '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.8 2.8L16.5 9.5"/>',
    // Галочка со стрелкой «выход».
    "post-close": '<path d="M20 12.5A8.5 8.5 0 1 1 12 3.5"/><path d="m7.5 12 2.8 2.8L16 9"/><path d="M15 3.5h6v6"/><path d="m21 3.5-5 5"/>',
    // Отмена (стрелка назад).
    undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>',
    // Корзина.
    trash: '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4h6v3"/>',
    // Крестик.
    close: '<path d="M6 6l12 12M18 6 6 18"/>',
};

export default {
    name: "IconButton",
    props: {
        icon: { type: String, required: true },
        label: { type: String, required: true },
        variant: { type: String, default: "ghost" },
        disabled: { type: Boolean, default: false },
        type: { type: String, default: "button" },
    },
    emits: ["click"],
    computed: {
        svg() {
            return ICONS[this.icon] || ICONS.close;
        },
    },
};
</script>

<template>
    <button
        :type="type"
        class="icon-btn"
        :class="`icon-btn--${variant}`"
        :disabled="disabled"
        :aria-label="label"
        :data-tip="label"
        @click="$emit('click', $event)"
    >
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" v-html="svg"></svg>
    </button>
</template>

<style scoped>
.icon-btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    padding: 0;
    border-radius: 8px;
    border: 1px solid #c8d2df;
    background: white;
    color: var(--brand-navy);
    cursor: pointer;
}
.icon-btn:hover:not(:disabled) {
    border-color: var(--brand-teal);
    background: #f2f6fc;
}
.icon-btn--primary {
    color: white;
    background: var(--brand-navy);
    border-color: var(--brand-navy);
}
.icon-btn--primary:hover:not(:disabled) {
    background: var(--brand-navy-dark);
    border-color: var(--brand-navy-dark);
}
.icon-btn--danger {
    color: var(--red-error);
    border-color: #e7b8b8;
}
.icon-btn--danger:hover:not(:disabled) {
    border-color: var(--red-error);
    background: #fdf0ef;
}
.icon-btn:disabled {
    opacity: 0.5;
    cursor: default;
}
/* Подсказка с назначением кнопки — над значком. */
.icon-btn::after {
    content: attr(data-tip);
    position: absolute;
    left: 50%;
    bottom: calc(100% + 8px);
    transform: translateX(-50%);
    padding: 5px 9px;
    border-radius: 6px;
    background: #1d2b3e;
    color: white;
    font-size: 12px;
    font-weight: 500;
    line-height: 1.2;
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.12s ease;
    z-index: 30;
}
.icon-btn:first-child::after {
    left: 0;
    transform: none;
}
.icon-btn:hover::after,
.icon-btn:focus-visible::after {
    opacity: 1;
}
</style>
