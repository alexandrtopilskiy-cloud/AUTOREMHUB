<script>
import { formatDateTime } from "../../data/permissions.js";

const ACTION_LABELS = {
    create: "Добавление ТС",
    update: "Изменение ТС",
    delete: "Удаление ТС",
};

export default {
    name: "VehicleApprovalReview",
    props: {
        record: {
            type: Object,
            required: true,
        },
        review: {
            type: Function,
            required: true,
        },
    },
    emits: ["reviewed"],
    data() {
        return {
            comment: "",
            busy: false,
            error: "",
        };
    },
    computed: {
        editable() {
            return this.record.permissions?.canReview !== false;
        },
    },
    methods: {
        formatDateTime,
        actionLabel(action) {
            return ACTION_LABELS[action] || action;
        },
        personName(person) {
            return [person.lastName, person.firstName, person.middleName]
                .filter(Boolean)
                .join(" ");
        },
        async decide(decision) {
            if (!this.editable) {
                return;
            }

            this.busy = true;
            this.error = "";

            try {
                const record = await this.review(
                    this.record,
                    decision,
                    this.comment,
                );
                this.$emit("reviewed", record);
            } catch (error) {
                this.error = error.message;
            } finally {
                this.busy = false;
            }
        },
    },
};
</script>

<template>
    <section class="document-panel">
        <span class="eyebrow">Согласование справочника ТС</span>
        <h1>{{ actionLabel(record.action) }}</h1>
        <p>{{ record.company }} · {{ record.requestedBy }}</p>

        <dl class="approval-details">
            <dt>Марка и модель</dt>
            <dd>{{ record.payload.vehicle || record.vehicle || "—" }}</dd>
            <dt>VIN</dt>
            <dd>{{ record.payload.vin || record.vin || "—" }}</dd>
            <dt>Госномер</dt>
            <dd>{{ record.payload.plate || record.plate || "—" }}</dd>
            <dt>Год выпуска</dt>
            <dd>{{ record.payload.year || "—" }}</dd>
            <dt>Дата запроса</dt>
            <dd>{{ formatDateTime(record.createdAt) }}</dd>
        </dl>

        <section class="catalog-related">
            <h3>Запрошенные изменения</h3>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Поле</th>
                            <th>Было</th>
                            <th>Станет</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="change in record.changes || []" :key="change.field">
                            <td>{{ change.label }}</td>
                            <td>{{ change.before }}</td>
                            <td>{{ change.after }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!(record.changes || []).length" class="muted">
                Изменяемые поля не определены.
            </p>
        </section>

        <section
            v-for="group in [
                { key: 'contacts', title: 'Контактные лица' },
                { key: 'drivers', title: 'Водители' },
            ]"
            :key="group.key"
            class="catalog-related"
        >
            <h3>{{ group.title }}</h3>
            <ul>
                <li
                    v-for="(person, index) in record.payload[group.key] || []"
                    :key="person.id || index"
                >
                    {{ personName(person) }} · {{ person.phone }}
                </li>
            </ul>
            <p v-if="!(record.payload[group.key] || []).length" class="muted">
                Нет записей.
            </p>
        </section>

        <label>
            Комментарий УК
            <textarea
                v-model="comment"
                placeholder="Комментарий к решению (необязательно)"
                :disabled="busy || !editable"
            />
        </label>

        <div class="action-buttons">
            <button
                class="danger-btn"
                :disabled="busy || !editable"
                @click="decide('rejected')"
            >
                Отклонить
            </button>
            <button
                class="primary-btn"
                :disabled="busy || !editable"
                @click="decide('approved')"
            >
                Согласовать
            </button>
        </div>

        <p v-if="error" class="error-message">{{ error }}</p>
    </section>
</template>
