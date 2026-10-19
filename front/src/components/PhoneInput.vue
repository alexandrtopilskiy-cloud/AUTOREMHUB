<script>
function formatRussianPhone(value) {
    let digits = String(value || "").replace(/\D/g, "");

    if (digits.startsWith("8")) {
        digits = "7" + digits.slice(1);
    }

    if (!digits.startsWith("7")) {
        digits = "7" + digits;
    }

    const local = digits.slice(1, 11);
    let result = "+7";

    if (local.length > 0) result += ` (${local.slice(0, 3)}`;
    if (local.length >= 3) result += ")";
    if (local.length > 3) result += ` ${local.slice(3, 6)}`;
    if (local.length > 6) result += ` ${local.slice(6, 8)}`;
    if (local.length > 8) result += ` ${local.slice(8, 10)}`;

    return result;
}

export default {
    name: "PhoneInput",
    props: {
        modelValue: {
            type: String,
            default: "",
        },
        disabled: {
            type: Boolean,
            default: false,
        },
        required: {
            type: Boolean,
            default: false,
        },
    },
    emits: ["update:modelValue", "input"],
    methods: {
        input(event) {
            const value = formatRussianPhone(event.target.value);
            event.target.value = value;
            this.$emit("update:modelValue", value);
            this.$emit("input", value);
        },
    },
};
</script>

<template>
    <input
        :value="modelValue"
        type="tel"
        inputmode="tel"
        autocomplete="tel"
        placeholder="+7 (ххх) ххх хх хх"
        pattern="\+7 \([0-9]{3}\) [0-9]{3} [0-9]{2} [0-9]{2}"
        maxlength="18"
        :disabled="disabled"
        :required="required"
        @input="input"
    />
</template>
