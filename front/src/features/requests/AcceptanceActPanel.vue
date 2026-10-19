<script>
const DEFECTS = [
    ["scratch", "Царапина"],
    ["dent", "Вмятина"],
    ["chip", "Скол"],
    ["glass_crack", "Трещина на стекле"],
    ["corrosion", "Коррозия"],
    ["other", "Другое"],
];

const ZONES = [
    ["front", "Передняя часть"],
    ["rear", "Задняя часть"],
    ["left", "Левая сторона"],
    ["right", "Правая сторона"],
    ["roof", "Крыша"],
    ["windshield", "Лобовое стекло"],
    ["rear_glass", "Заднее стекло"],
];

export default {
    name: "AcceptanceActPanel",
    props: {
        request: {
            type: Object,
            required: true,
        },
        busy: {
            type: Boolean,
            default: false,
        },
        perform: {
            type: Function,
            required: true,
        },
    },
    data() {
        return {
            defects: DEFECTS,
            zones: ZONES,
            formOpen: false,
            mode: "electronic",
            mileage: this.request.mileage || "",
            fuelLevel: "",
            condition: "",
            defectTypes: [],
            diagramMarks: [],
            attachmentId: "",
            attachmentName: "",
            uploading: false,
            error: "",
        };
    },
    computed: {
        canAccept() {
            return this.request.permissions?.canAcceptVehicle === true;
        },
        canSubmit() {
            if (this.busy || this.uploading || !this.mileage) {
                return false;
            }

            return this.mode === "uploaded"
                ? Boolean(this.attachmentId)
                : Boolean(this.fuelLevel && this.condition.trim());
        },
    },
    methods: {
        person(snapshot) {
            if (!snapshot) {
                return "—";
            }

            return [snapshot.fullName, snapshot.phone].filter(Boolean).join(" · ") || "—";
        },
        defectLabel(code) {
            return this.defects.find(([key]) => key === code)?.[1] || code;
        },
        zoneLabel(code) {
            return this.zones.find(([key]) => key === code)?.[1] || code;
        },
        vehicleLabel(vehicle) {
            if (!vehicle) {
                return "—";
            }

            return [
                [vehicle.make, vehicle.model].filter(Boolean).join(" "),
                vehicle.vin ? `VIN ${vehicle.vin}` : "",
                vehicle.plate || "",
            ].filter(Boolean).join(" · ") || "—";
        },
        fuelLabel(code) {
            return {
                empty: "Пустой бак",
                quarter: "1/4",
                half: "1/2",
                three_quarters: "3/4",
                full: "Полный бак",
            }[code] || "—";
        },
        toggleZone(zone) {
            const index = this.diagramMarks.findIndex((mark) => mark.zone === zone);

            if (index >= 0) {
                this.diagramMarks.splice(index, 1);
            } else {
                this.diagramMarks.push({ zone, type: "scratch", comment: "" });
            }
        },
        mark(zone) {
            return this.diagramMarks.find((item) => item.zone === zone);
        },
        escape(value) {
            return String(value || "")
                .replaceAll("&", "&amp;")
                .replaceAll("<", "&lt;")
                .replaceAll(">", "&gt;")
                .replaceAll('"', "&quot;");
        },
        printBlankAct() {
            const title = `Акт приёма AP-${this.escape(this.request.number)}`;
            const vehicle = this.escape(
                `${this.request.vehicle} · VIN ${this.request.vin} · ${this.request.plate || "—"}`,
            );
            const html = `<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>${title}</title><style>body{font:14px Arial;margin:32px;color:#17253a}h1{font-size:20px}.line{border-bottom:1px solid #17253a;min-height:28px;margin:10px 0}.car{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin:24px 0}.zone{border:1px solid #17253a;height:90px;padding:8px}small{color:#526377}</style></head><body><h1>${title}</h1><p><strong>ТС:</strong> ${vehicle}</p><p><strong>Контактное лицо:</strong> ${this.escape(this.person(this.request.contactDetails))}</p><p><strong>Водитель:</strong> ${this.escape(this.person(this.request.driverDetails))}</p><p>Пробег:</p><div class="line"></div><p>Уровень топлива:</p><div class="line"></div><p>Состояние и комплектность:</p><div class="line"></div><div class="line"></div><h2>Внешние дефекты и развёртка</h2><div class="car">${this.zones.map(([, zone]) => `<div class="zone">${this.escape(zone)}</div>`).join("")}</div><p>Передал ТС:</p><div class="line"></div><p>Принял ТС:</p><div class="line"></div><small>После подписания загрузите скан или фотографию в заявку.</small></body></html>`;
            const preview = window.open("", "_blank", "width=1000,height=800");

            if (!preview) {
                this.error = "Браузер заблокировал окно печати. Разрешите всплывающие окна.";
                return;
            }

            preview.opener = null;
            preview.document.open();
            preview.document.write(html);
            preview.document.close();
            preview.focus();
            window.setTimeout(() => preview.print(), 100);
        },
        async upload(event) {
            const file = event.target.files?.[0];

            if (!file) {
                return;
            }

            this.error = "";
            this.uploading = true;

            try {
                const updated = await this.perform("upload", file, "acceptance_act");
                const attachment = [...(updated.attachments || [])]
                    .reverse()
                    .find((item) => item.purpose === "acceptance_act" && !item.linked);

                if (!attachment) {
                    throw new Error("Загруженный акт не найден в заявке");
                }

                this.attachmentId = attachment.id;
                this.attachmentName = attachment.name;
            } catch (error) {
                this.error = error.message;
            } finally {
                this.uploading = false;
                event.target.value = "";
            }
        },
        async submit() {
            if (!this.canSubmit) {
                return;
            }

            this.error = "";

            try {
                await this.perform("acceptVehicle", {
                    mode: this.mode,
                    mileage: Number(this.mileage),
                    ...(this.mode === "uploaded"
                        ? { attachmentId: this.attachmentId }
                        : {
                            fuelLevel: this.fuelLevel,
                            condition: this.condition,
                            defectTypes: this.defectTypes,
                            diagramMarks: this.diagramMarks,
                        }),
                });
            } catch (error) {
                this.error = error.message;
            }
        },
    },
};
</script>

<template>
    <section v-if="canAccept || request.acceptanceAct" class="feature-card acceptance-card">
        <div class="section-title">
            <span>06</span>
            <div>
                <h3>Приём и ремонт ТС</h3>
                <p>Акт после сохранения становится неизменяемой частью заявки.</p>
            </div>
        </div>

        <div v-if="request.acceptanceAct" class="acceptance-summary">
            <strong>Акт приёма {{ request.acceptanceAct.number }}</strong>
            <dl>
                <div><dt>ТС</dt><dd>{{ vehicleLabel(request.acceptanceAct.vehicle) }}</dd></div>
                <div><dt>Контактное лицо</dt><dd>{{ person(request.acceptanceAct.contact) }}</dd></div>
                <div><dt>Водитель</dt><dd>{{ person(request.acceptanceAct.driver) }}</dd></div>
                <div><dt>Формат</dt><dd>{{ request.acceptanceAct.mode === "uploaded" ? "Загруженный" : "Электронный" }}</dd></div>
                <div><dt>Пробег</dt><dd>{{ request.acceptanceAct.mileage }} км</dd></div>
                <div><dt>Уровень топлива</dt><dd>{{ fuelLabel(request.acceptanceAct.fuelLevel) }}</dd></div>
                <div><dt>Состояние</dt><dd>{{ request.acceptanceAct.condition || "См. вложенный акт" }}</dd></div>
            </dl>
            <p v-if="request.acceptanceAct.defectTypes?.length">
                Дефекты: {{ request.acceptanceAct.defectTypes.map(defectLabel).join(", ") }}
            </p>
            <ul v-if="request.acceptanceAct.diagramMarks?.length" class="acceptance-marks">
                <li v-for="markItem in request.acceptanceAct.diagramMarks" :key="`${markItem.zone}-${markItem.type}`">
                    {{ zoneLabel(markItem.zone) }} — {{ defectLabel(markItem.type) }}<template v-if="markItem.comment">: {{ markItem.comment }}</template>
                </li>
            </ul>
            <small>Документ зафиксирован и недоступен для корректировки.</small>
        </div>

        <div v-else-if="!formOpen" class="acceptance-start">
            <p>
                После прибытия автомобиля оформите электронный акт либо
                загрузите подписанный бумажный экземпляр.
            </p>
            <button
                type="button"
                class="primary-btn"
                :disabled="busy"
                @click="formOpen = true"
            >
                Приём ТС
            </button>
        </div>

        <form v-else @submit.prevent="submit">
            <div class="acceptance-context">
                <div><span>ТС</span><strong>{{ request.vehicle }} · VIN {{ request.vin }} · {{ request.plate || "—" }}</strong></div>
                <div><span>Контактное лицо</span><strong>{{ person(request.contactDetails) }}</strong></div>
                <div><span>Водитель</span><strong>{{ person(request.driverDetails) }}</strong></div>
            </div>

            <div class="acceptance-mode">
                <label><input v-model="mode" type="radio" value="electronic" /> Заполнить электронно</label>
                <label><input v-model="mode" type="radio" value="uploaded" /> Загрузить заполненный акт</label>
                <button type="button" class="ghost-btn" @click="printBlankAct">
                    Распечатать пустой акт
                </button>
            </div>

            <label>
                Пробег при приёме, км
                <input v-model="mileage" type="number" min="0" step="1" required />
            </label>

            <template v-if="mode === 'electronic'">
                <label>
                    Уровень топлива
                    <select v-model="fuelLevel" required>
                        <option value="">Выберите уровень</option>
                        <option value="empty">Пустой бак</option>
                        <option value="quarter">1/4</option>
                        <option value="half">1/2</option>
                        <option value="three_quarters">3/4</option>
                        <option value="full">Полный бак</option>
                    </select>
                </label>

                <label>
                    Общее состояние ТС
                    <textarea v-model="condition" required placeholder="Комплектность, состояние салона и кузова" />
                </label>

                <fieldset>
                    <legend>Наличие внешних дефектов</legend>
                    <label v-for="[code, title] in defects" :key="code" class="check-label">
                        <input v-model="defectTypes" type="checkbox" :value="code" /> {{ title }}
                    </label>
                </fieldset>

                <fieldset>
                    <legend>Развёртка автомобиля</legend>
                    <div class="vehicle-diagram">
                        <button
                            v-for="[zone, title] in zones"
                            :key="zone"
                            type="button"
                            :class="{ selected: mark(zone) }"
                            @click="toggleZone(zone)"
                        >
                            {{ title }}
                        </button>
                    </div>
                    <div v-for="item in diagramMarks" :key="item.zone" class="diagram-mark-row">
                        <strong>{{ zones.find(([zone]) => zone === item.zone)?.[1] }}</strong>
                        <select v-model="item.type">
                            <option v-for="[code, title] in defects" :key="code" :value="code">{{ title }}</option>
                        </select>
                        <input v-model="item.comment" placeholder="Комментарий" />
                    </div>
                </fieldset>
            </template>

            <label v-else class="upload-label">
                {{ uploading ? "Загружаем…" : attachmentName || "Выбрать PDF или изображение акта" }}
                <input type="file" accept="application/pdf,image/jpeg,image/png,image/webp" :disabled="uploading || busy" @change="upload" />
            </label>

            <p v-if="error" class="error-message">{{ error }}</p>
            <div class="action-buttons">
                <button
                    type="button"
                    class="ghost-btn"
                    :disabled="busy || uploading"
                    @click="formOpen = false"
                >
                    Отмена
                </button>
                <button class="primary-btn" :disabled="!canSubmit">
                    Сохранить акт и начать работы
                </button>
            </div>
        </form>
    </section>
</template>
