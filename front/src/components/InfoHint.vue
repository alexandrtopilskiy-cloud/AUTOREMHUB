<script>
export default {
    name: "InfoHint",
    props: {
        text: {
            type: String,
            required: true,
        },
    },
};
</script>

<template>
    <span class="info-hint" tabindex="0" :aria-label="text" :title="text">
        i
        <span class="info-hint-popover" role="tooltip">{{ text }}</span>
    </span>
</template>
