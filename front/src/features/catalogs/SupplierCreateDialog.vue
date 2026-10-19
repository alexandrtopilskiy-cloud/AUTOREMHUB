<script>
import SupplierEditor from "./SupplierEditor.vue";
export default {
    name: "SupplierCreateDialog",
    components: { SupplierEditor },
    props: {
        initialName: { type: String, default: "" },
        references: { type: Object, required: true },
        saveRecord: { type: Function, required: true },
    },
    emits: ["created", "close"],
    data() {
        return {
            record: {
                id: "new-supplier",
                kind: "suppliers",
                isNew: true,
                name: this.initialName,
                permissions: { canEdit: true },
            },
        };
    },
    mounted() {
        this.$refs.dialog.showModal();
    },
};
</script>
<template>
    <dialog
        ref="dialog"
        class="action-dialog supplier-create-dialog"
        @cancel.prevent="$emit('close')"
    >
        <SupplierEditor
            :record="record"
            :references="references"
            :save-record="saveRecord"
            embedded
            @saved="$emit('created', $event)"
            @close="$emit('close')"
        />
    </dialog>
</template>
