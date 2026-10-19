<script>
import PhoneInput from "../../components/PhoneInput.vue";

const PASSWORD_MASK = "••••••••••••";
const DEFAULT_RIGHTS = {
    customer: [
        "requests.view",
        "requests.create",
        "catalogs.vehicles",
        "catalogs.people",
    ],
    station: ["requests.view", "requests.station"],
    manager: [
        "requests.view",
        "requests.manage",
        "catalogs.manage",
        "users.manage",
    ],
};

export default {
    name: "UserCatalogEditor",
    components: { PhoneInput },
    props: {
        record: {
            type: Object,
            required: true,
        },
        references: {
            type: Object,
            required: true,
        },
        saveRecord: {
            type: Function,
            required: true,
        },
    },
    emits: ["saved", "saved-and-exit"],
    data() {
        return {
            form: this.initialForm(),
            busy: false,
            error: "",
            passwordMask: PASSWORD_MASK,
        };
    },
    computed: {
        isNew() {
            return this.record.isNew === true;
        },
        editable() {
            return this.record.permissions?.canEdit === true;
        },
        organizations() {
            return this.references.organizations || [];
        },
        rights() {
            return this.references.accessRights || [];
        },
        selectedOrganization() {
            return this.organizations.find(
                (organization) => organization.id === this.form.organizationId,
            );
        },
        accessState() {
            return this.form.active && !this.form.accessRevokedAt
                ? "Доступ разрешён"
                : "Доступ заблокирован";
        },
    },
    watch: {
        "record.id"() {
            this.form = this.initialForm();
            this.error = "";
        },
        "form.organizationId"(value, previous) {
            if (value === previous || !this.isNew) {
                return;
            }

            this.form.accessRights = [
                ...(DEFAULT_RIGHTS[this.selectedOrganization?.kind] || []),
            ];
        },
    },
    methods: {
        dateValue(value) {
            return value ? String(value).slice(0, 10) : "";
        },
        initialForm() {
            const organization = (this.references.organizations || []).find(
                (item) => item.id === this.record.organizationId,
            );
            const accessRights = this.record.accessRights?.length
                ? this.record.accessRights
                : this.record.isNew
                  ? DEFAULT_RIGHTS[organization?.kind] || []
                  : [];

            return {
                lastName: this.record.lastName || "",
                firstName: this.record.firstName || "",
                middleName: this.record.middleName || "",
                email: this.record.email || "",
                login: this.record.login || "",
                password: "",
                phone: this.record.phone || "+7",
                organizationId: this.record.organizationId || "",
                registeredAt:
                    this.dateValue(this.record.registeredAt)
                    || new Date().toISOString().slice(0, 10),
                accessRevokedAt: this.dateValue(this.record.accessRevokedAt),
                active: this.record.active ?? true,
                accessRights: [...accessRights],
            };
        },
        toggleRight(right) {
            const index = this.form.accessRights.indexOf(right);

            if (index >= 0) {
                this.form.accessRights.splice(index, 1);
            } else {
                this.form.accessRights.push(right);
            }
        },
        async save(exitAfter = false) {
            if (!this.editable) {
                return;
            }

            this.busy = true;
            this.error = "";

            try {
                const payload = {
                    ...this.form,
                    password: this.form.password || PASSWORD_MASK,
                    accessRevokedAt: this.form.accessRevokedAt || null,
                };

                if (this.isNew && !this.form.password) {
                    throw new Error("Укажите пароль длиной не менее 12 символов");
                }

                const saved = await this.saveRecord(this.record, payload);
                this.form.password = "";
                this.$emit(exitAfter ? "saved-and-exit" : "saved", saved);
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
    <section class="document-panel user-catalog-editor">
        <span class="eyebrow">Справочник пользователей</span>
        <h1>{{ isNew ? "Новый пользователь" : record.fullName }}</h1>
        <p>
            Учётная запись относится к выбранной организации. Логин и пароль
            больше не хранятся в справочниках Заказчиков, СТО и УК.
        </p>

        <form @submit.prevent="save()">
            <fieldset class="catalog-fieldset" :disabled="!editable || busy">
                <div class="form-grid">
                    <label>
                        Фамилия
                        <input v-model.trim="form.lastName" required maxlength="100" />
                    </label>
                    <label>
                        Имя
                        <input v-model.trim="form.firstName" required maxlength="100" />
                    </label>
                    <label>
                        Отчество
                        <input v-model.trim="form.middleName" maxlength="100" />
                    </label>
                    <label>
                        Электронная почта
                        <input v-model.trim="form.email" type="email" required maxlength="255" />
                    </label>
                    <label>
                        Логин
                        <input v-model.trim="form.login" required maxlength="100" autocomplete="off" />
                    </label>
                    <label>
                        {{ isNew ? "Пароль" : "Новый пароль (если требуется)" }}
                        <input
                            v-model="form.password"
                            type="password"
                            :required="isNew"
                            minlength="12"
                            maxlength="72"
                            autocomplete="new-password"
                            :placeholder="isNew ? '' : passwordMask"
                        />
                    </label>
                    <label>
                        Мобильный телефон
                        <PhoneInput v-model="form.phone" required />
                    </label>
                    <label>
                        Принадлежность к организации
                        <select v-model="form.organizationId" required>
                            <option value="">Выберите организацию</option>
                            <option
                                v-for="organization in organizations"
                                :key="organization.id"
                                :value="organization.id"
                            >
                                {{ organization.name }} ·
                                {{
                                    {
                                        customer: "Заказчик",
                                        station: "СТО",
                                        manager: "УК",
                                    }[organization.kind]
                                }}
                            </option>
                        </select>
                    </label>
                    <label>
                        Дата первичной регистрации
                        <input v-model="form.registeredAt" type="date" required />
                    </label>
                    <label>
                        Дата отзыва доступа
                        <input v-model="form.accessRevokedAt" type="date" />
                        <small class="field-help">
                            При заполненной дате пользователь не сможет войти.
                        </small>
                    </label>
                    <label class="checkbox-label">
                        <span>Действующий участник</span>
                        <input v-model="form.active" type="checkbox" />
                    </label>
                </div>

                <section class="full catalog-related">
                    <h3>Права доступа</h3>
                    <div class="rights-grid">
                        <label
                            v-for="right in rights"
                            :key="right.value"
                            class="checkbox-label"
                        >
                            <input
                                type="checkbox"
                                :checked="form.accessRights.includes(right.value)"
                                @change="toggleRight(right.value)"
                            />
                            <span>{{ right.label }}</span>
                        </label>
                    </div>
                </section>

                <p
                    class="status-pill"
                    :class="{ 'status-pill-danger': accessState !== 'Доступ разрешён' }"
                >
                    {{ accessState }}
                </p>

                <div class="catalog-actions">
                    <button class="office-action primary-btn" :disabled="busy" title="Сохранить" aria-label="Сохранить"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 3h12l4 4v14H3V3h2z M7 3v7h10V3 M7 21v-7h10v7" /></svg></button>
                    <button
                        type="button"
                        class="office-action ghost-btn"
                        :disabled="busy"
                        @click="save(true)"
                     title="Сохранить и выйти" aria-label="Сохранить и выйти"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 3h11v6H7V3 M3 3v18h11 M15 12h7m-3-3 3 3-3 3" /></svg></button>
                </div>
            </fieldset>

            <p v-if="error" class="error-message">{{ error }}</p>
        </form>
    </section>
</template>
