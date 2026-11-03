<script>
/** Маленькая ссылка «формат» рядом с полем: по клику показывает правила заполнения. */
export default {
    name: "FormatHint",
    props: {
        text: { type: String, required: true },
        label: { type: String, default: "формат" },
    },
    data() {
        return { open: false };
    },
    beforeUnmount() {
        this.detach();
    },
    methods: {
        toggle() {
            this.open = !this.open;
            if (this.open) {
                document.addEventListener("click", this.outside, true);
                document.addEventListener("keydown", this.keydown);
            } else {
                this.detach();
            }
        },
        close() {
            this.open = false;
            this.detach();
        },
        detach() {
            document.removeEventListener("click", this.outside, true);
            document.removeEventListener("keydown", this.keydown);
        },
        outside(event) {
            if (!this.$el.contains(event.target)) this.close();
        },
        keydown(event) {
            if (event.key === "Escape") this.close();
        },
    },
};
</script>

<template>
    <span class="format-hint">
        <button
            type="button"
            class="format-hint-link"
            :aria-expanded="open ? 'true' : 'false'"
            @click.prevent.stop="toggle"
        >
            {{ label }}
        </button>
        <span v-if="open" class="format-hint-popover" role="tooltip">{{ text }}</span>
    </span>
</template>
