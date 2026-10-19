<script>
import { catalogNames, formatDateTime } from "../../data/permissions.js";
import { marketplaceApi } from "../../api/marketplace.js";
import SupplierCreateDialog from "./SupplierCreateDialog.vue";
import InfoHint from "../../components/InfoHint.vue";
import ReferencePicker from "../../components/ReferencePicker.vue";
import { startColumnResize } from "../../utils/columnResize.js";

const FIELDS = {
    vehicles: {
        vehicle: "Автомобиль",
        vin: "VIN",
        plate: "Госномер",
        year: "Год",
        baselineServiceMileage: "Исходный пробег последнего ТО",
        laborRateCoefficient: "Поправочный коэффициент (нормо/час)",
    },
    stations: {
        name: "Название",
        shortName: "Краткое название",
        inn: "ИНН",
        kpp: "КПП",
        ogrn: "ОГРН",
        address: "Адрес",
        legalAddress: "Юридический адрес",
        actualAddress: "Фактический адрес",
        postalAddress: "Почтовый адрес",
        directorName: "Руководитель",
        contact: "Контакт",
        phone: "Телефон",
        email: "E-mail",
        bankName: "Банк",
        bik: "БИК",
        settlementAccount: "Расчётный счёт",
        correspondentAccount: "Корреспондентский счёт",
        taxRegime: "Система налогообложения",
        vatRate: "Ставка НДС, %",
        mechanicalBays: "Механические посты",
        lifts: "Подъёмники",
        mechanicsCount: "Количество механиков",
        electriciansCount: "Количество электриков",
        electricianBays: "Посты автоэлектрики",
        alignmentBays: "Посты сход-развала",
        engineBays: "Моторные посты",
        paintBooths: "Покрасочные камеры",
        frameRacks: "Стапели",
        bodyworkBays: "Кузовные посты",
        engineRepair: "Ремонт двигателя",
        automaticTransmissionRepair: "Ремонт АКПП",
        manualTransmissionRepair: "Ремонт МКПП",
        steeringRackRepair: "Ремонт рулевых реек",
        bodyRepair: "Кузовной ремонт",
        brakeDiscSkimming: "Проточка тормозных дисков",
        wheelAlignment: "Сход-развал",
        tireService: "Шиномонтаж",
        tireStorage: "Хранение шин",
        evacuation: "Эвакуация",
        warrantyService: "Гарантийное обслуживание",
        reception: "Зона приёмки",
        waitingArea: "Зона ожидания",
        photoVideoFixation: "Фото- и видеофиксация",
        itAccounting: "ИТ-учёт работ",
        insuranceFleetExperience: "Опыт со страховыми и автопарками",
        trainingCertificates: "Сертификаты обучения",
        passengerCars: "Легковые автомобили",
        lcv: "Лёгкий коммерческий транспорт",
        trucks: "Грузовые автомобили",
        slaDescription: "SLA",
        vehicleRestrictions: "Ограничения по ТС",
        customerAmenities: "Удобства для заказчика",
        uniqueServices: "Уникальные услуги",
        yearsOperating: "Лет работы",
        active: "Активна",
    },
    customers: {
        name: "Название",
        shortName: "Краткое название",
        inn: "ИНН",
        kpp: "КПП",
        ogrn: "ОГРН",
        legalAddress: "Юридический адрес",
        actualAddress: "Фактический адрес",
        postalAddress: "Почтовый адрес",
        directorName: "Руководитель",
        contact: "Контакт",
        phone: "Телефон",
        email: "E-mail",
        bankName: "Банк",
        bik: "БИК",
        settlementAccount: "Расчётный счёт",
        correspondentAccount: "Корреспондентский счёт",
        taxRegime: "Система налогообложения",
        vatRate: "Ставка НДС, %",
    },
    managers: {
        name: "Название",
        shortName: "Краткое название",
        inn: "ИНН",
        kpp: "КПП",
        ogrn: "ОГРН",
        legalAddress: "Юридический адрес",
        actualAddress: "Фактический адрес",
        postalAddress: "Почтовый адрес",
        directorName: "Руководитель",
        contact: "Контакт",
        phone: "Телефон",
        email: "E-mail",
        bankName: "Банк",
        bik: "БИК",
        settlementAccount: "Расчётный счёт",
        correspondentAccount: "Корреспондентский счёт",
        taxRegime: "Система налогообложения",
        vatRate: "Ставка НДС, %",
    },
    workTypes: {
        name: "Название работы",
        group: "Группа",
        isMaintenance: "ТО",
        active: "Активен",
    },
    timeNorms: {
        workTypeId: "Вид работы",
        applicabilityId: "Применимость",
        vehicleMakeId: "Марка ТС",
        vehicleModelId: "Модель ТС",
        hours: "Норма, ч",
        source: "Источник",
    },
    normApplicabilities: {
        name: "Применимость",
        active: "Активна",
    },
    reasons: {
        name: "Название",
        code: "Уникальный код",
        parentId: "Основная причина",
        requiresDiagnosis: "Нужна диагностика",
        isMaintenance: "Это ТО",
        active: "Активна",
    },
    regulations: {
        name: "Название",
        vehicleModel: "Модель",
        intervalKm: "Интервал, км",
        intervalMonths: "Интервал, мес",
        source: "Источник",
        active: "Активен",
    },
    suppliers: {
        name: "Название",
        contact: "Контакт",
        taxId: "ИНН",
        active: "Активен",
    },
    partManufacturers: { name: "Наименование производителя", active: "Активен" ,
    },
    parts: {
        name: "Название запчасти",
        article: "Артикул",
        manufacturerId: "Производитель",
        supplierId: "Поставщик",
        purchasePrice: "Цена закупа, ₽",
        salePrice: "Цена реализации, ₽",
        unit: "Единица измерения",
        active: "Активна",
    },
};

const BOOLEAN_FIELDS = [
    "active",
    "isMaintenance",
    "requiresDiagnosis",
    "engineRepair",
    "automaticTransmissionRepair",
    "manualTransmissionRepair",
    "steeringRackRepair",
    "bodyRepair",
    "brakeDiscSkimming",
    "wheelAlignment",
    "tireService",
    "tireStorage",
    "evacuation",
    "warrantyService",
    "reception",
    "waitingArea",
    "photoVideoFixation",
    "itAccounting",
    "insuranceFleetExperience",
    "trainingCertificates",
    "passengerCars",
    "lcv",
    "trucks",
];
const NUMBER_FIELDS = [
    "year",
    "baselineServiceMileage",
    "laborRateCoefficient",
    "hours",
    "intervalKm",
    "intervalMonths",
    "vatRate",
    "mechanicalBays",
    "lifts",
    "mechanicsCount",
    "electriciansCount",
    "electricianBays",
    "alignmentBays",
    "engineBays",
    "paintBooths",
    "frameRacks",
    "bodyworkBays",
    "yearsOperating",
];
const REQUIRED_FIELDS = ["name", "vehicle", "vin", "code", "group"];
const SELECT_FIELDS = ["workTypeId", "parentId"];
const WEEKDAYS = [
    { value: 1, label: "Пн." },
    { value: 2, label: "Вт." },
    { value: 3, label: "Ср." },
    { value: 4, label: "Чт." },
    { value: 5, label: "Пт." },
    { value: 6, label: "Сб." },
    { value: 7, label: "Вск." },
];

const HISTORY_ACTIONS = {
    create: "Создание",
    update: "Изменение",
    delete: "Удаление",
    import_create: "Создание при импорте",
    import_update: "Обновление при импорте",
};

const HISTORY_FIELD_LABELS = {
    name: "Название",
    article: "Артикул",
    manufacturer: "Производитель",
    unit: "Единица",
    active: "Активность",
    hours: "Норма времени",
    source: "Источник",
    vehicle_model: "Модель автомобиля",
    vehicle_make_id: "Марка ТС",
    vehicle_model_id: "Модель ТС",
    applicability_id: "Применимость",
    group_name: "Группа",
    labor_rate_coefficient: "Коэффициент нормо-часа",
    baseline_service_mileage: "Исходный пробег",
    plate: "Госномер",
    vin: "VIN",
    workday_start: "Время начала работы",
    workday_end: "Время окончания работы",
    working_days: "Рабочие дни",
};

let normRowSequence = 0;

const WORK_HELP = {
    isMaintenance:
        "Помечает выполненную работу как ТО в заказ-наряде и истории автомобиля; используется для пробега прошлого ТО и статистики. Сам регламент ТО этот признак не создаёт.",
    active:
        "Активная работа доступна для выбора в новых заявках. Отключение не удаляет её из ранее созданных документов.",
};

export default {
    name: "CatalogEditor",
    components: { InfoHint, ReferencePicker , SupplierCreateDialog },
    props: {
        record: {
            type: Object,
            required: true,
        },
        records: {
            type: Array,
            default: () => [],
        },
        references: {
            type: Object,
            required: true,
        },
        saveRecord: {
            type: Function,
            required: true,
        },
        loadHistory: {
            type: Function,
            default: null,
        },
    },
    emits: ["saved", "saved-and-exit", "vehicle-history", "open-record"],
    data() {
        return {
            supplierDialogOpen: false,
            supplierInitialName: "",
            form: {},
            error: "",
            busy: false,
            history: [],
            historyLoading: false,
            weekdays: WEEKDAYS,
            applicabilityDialogOpen: false,
            applicabilityTarget: null,
            newApplicabilityName: "",
            applicabilityBusy: false,
            applicabilityError: "",
            deletedTimeNormIds: [],
        };
    },
    computed: {
        title() {
            return catalogNames[this.record.kind] || "Справочник";
        },
        schema() {
            return FIELDS[this.record.kind] || {};
        },
        editable() {
            return this.record.permissions?.canEdit === true;
        },
        isRegulations() {
            return this.record.kind === "regulations";
        },
        customerVehicles() {
            if (this.record.kind !== "customers") {
                return [];
            }

            return this.records.filter(
                (item) =>
                    item.kind === "vehicles" &&
                    item.organizationId === this.record.id,
            );
        },
        workTypeNorms() {
            if (this.record.kind !== "workTypes") {
                return [];
            }

            return this.records.filter(
                (item) =>
                    item.kind === "timeNorms" &&
                    item.workTypeId === this.record.id,
            );
        },
        applicabilityOptions() {
            return this.references.normApplicabilities || [];
        },
        defaultApplicability() {
            return (
                this.applicabilityOptions.find(
                (item) => item.code === "general",
            ) || null);
        },
        canAddTimeNorm() {
            const last = this.form.timeNorms?.at(-1);

            return Boolean(last && this.timeNormHasData(last));
        },
    },
    watch: {
        "record.id": {
            immediate: true,
            handler() {
                this.deletedTimeNormIds = [];
                this.form = this.buildInitialForm();
                this.fetchHistory();
            },
        },
    },
    methods: {
        openSupplier(name = "") {
            this.supplierInitialName = name;
            this.supplierDialogOpen = true;
        },
        supplierCreated(record) {
            const rows = (this.references.suppliers ||= []);
            const index = rows.findIndex((row) => row.id === record.id);
            if (index >= 0) rows.splice(index, 1, record);
            else rows.push(record);
            this.form.supplierId = record.id;
            this.supplierDialogOpen = false;
        },
        async createManufacturer(name) {
            this.error = "";
            try {
                const record = await marketplaceApi.createCatalog("partManufacturers", { name, active: true ,
                    },
                );
                if (record.status === "approved") {
                    (this.references.partManufacturers ||= []).push(record);
                    this.form.manufacturerId = record.id;
                } else {
                    this.error = "Производитель направлен на согласование УК. После согласования обновите справочник и выберите его.";
                }
            } catch (e) { this.error = e.message; }
        },
        async reviewManufacturer(decision) {
            this.busy = true;
            try {
                const record = await marketplaceApi.reviewManufacturer(this.record.id, decision, this.record.revision,
                );
                this.$emit("saved", record);
            } catch (e) { this.error = e.message; }
            finally { this.busy = false; }
        },
        formatDateTime,
        startColumnResize,
        buildInitialForm() {
            const result = {};

            for (const key of Object.keys(FIELDS[this.record.kind] || {})) {
                result[key] = this.initialValue(key);
            }

            if (this.record.kind === "regulations") {
                result.items = JSON.parse(
                    JSON.stringify(this.record.items || []),
                );
            }

            if (this.record.kind === "workTypes") {
                const rows = this.record.timeNorms?.length
                    ? this.record.timeNorms
                    : this.workTypeNorms;

                result.timeNorms = rows.length
                    ? rows.map((row) => this.normalizeTimeNorm(row))
                    : [this.blankTimeNorm()];
                result.group = this.record.group || "Прочее";
            }

            if (this.record.kind === "stations") {
                result.workdayStart = this.record.workdayStart || "10:00";
                result.workdayEnd = this.record.workdayEnd || "20:00";
                result.workingDays = Array.isArray(this.record.workingDays)
                    ? [...this.record.workingDays]
                    : WEEKDAYS.map((day) => day.value);
            }

            return result;
        },
        blankTimeNorm() {
            normRowSequence += 1;

            return {
                _clientId: `time-norm-${normRowSequence}`,
                id: "",
                applicabilityId: this.defaultApplicability?.id || "",
                applicability: this.defaultApplicability?.name || "Общая",
                applicabilityCode: this.defaultApplicability?.code || "general",
                vehicleMakeId: "",
                vehicleMake: "",
                vehicleModelId: "",
                vehicleModel: "",
                vehicleId: "",
                vehicleLabel: "",
                vin: "",
                vehicleGenerationId: "",
                vehicleGeneration: "",
                vehicleEngineId: "",
                vehicleEngine: "",
                vehicleTransmissionId: "",
                vehicleTransmission: "",
                vehicleDriveId: "",
                vehicleDrive: "",
                modificationCode: "",
                legacyVehicleModel: "",
                hours: null,
                source: "",
            };
        },
        normalizeTimeNorm(row) {
            return {
                ...this.blankTimeNorm(),
                ...JSON.parse(JSON.stringify(row)),
            };
        },
        initialValue(key) {
            const value = this.record[key];

            if (value !== undefined && value !== null) {
                return value;
            }

            if (BOOLEAN_FIELDS.includes(key)) {
                return key === "active";
            }

            if (NUMBER_FIELDS.includes(key)) {
                return null;
            }

            if (this.record.isNew && key === "unit") {
                return "шт.";
            }

            return "";
        },
        isBool(key) {
            return BOOLEAN_FIELDS.includes(key);
        },
        isNumber(key) {
            return NUMBER_FIELDS.includes(key);
        },
        isSelect(key) {
            return SELECT_FIELDS.includes(key);
        },
        isRequired(key) {
            return REQUIRED_FIELDS.includes(key);
        },
        fieldHelp(key) {
            return this.record.kind === "workTypes" ? WORK_HELP[key] || "" : "";
        },
        inputType(key) {
            if (key === "email") {
                return "email";
            }

            if (key === "phone") {
                return "tel";
            }

            return "text";
        },
        stepFor(key) {
            return ["hours", "laborRateCoefficient"].includes(key)
                ? "0.001"
                : ["vatRate"].includes(key)
                  ? "0.01"
                  : "1";
        },
        minFor(key) {
            return ["hours", "laborRateCoefficient"].includes(key)
                ? "0.001"
                : "0";
        },
        options(key) {
            if (key === "workTypeId") {
                return this.references.workTypes || [];
            }

            if (key === "parentId") {
                return (this.references.reasons || []).filter(
                    (reason) =>
                        !reason.parentId && reason.id !== this.record.id,
                );
            }

            return [];
        },
        workGroups() {
            return this.references.workGroups || [];
        },
        timeNormsForItem(item) {
            return (this.references.timeNorms || []).filter(
                (norm) => norm.workTypeId === item.workTypeId,
            );
        },
        async save(exitAfter = false) {
            this.error = "";

            if (
                this.record.kind === "stations" &&
                !this.validateStationSchedule()
            ) {
                return;
            }

            if (this.record.kind === "workTypes" && !this.validateWorkType()) {
                return;
            }

            if (this.record.kind === "parts") {
                for (const key of ["name", "article", "manufacturerId", "unit", "supplierId", "purchasePrice", "salePrice",
                ]) {
                    if (this.form[key] === "" || this.form[key] == null) { this.error = "Заполните все обязательные поля запчасти"; return; }
                }
            }
            this.busy = true;

            try {
                const payload = this.record.kind === "workTypes"
                    ? {
                        ...this.form,
                        deletedTimeNormIds: [...this.deletedTimeNormIds],
                        timeNorms: this.form.timeNorms
                            .filter((row) => this.timeNormHasData(row))
                            .map((row) => ({
                                ...(row.id && !String(row.id).startsWith("new-")
                                    ? { id: row.id }
                                    : {}),
                                applicabilityId: row.applicabilityId,
                                vehicleMakeId: row.vehicleMakeId || "",
                                vehicleModelId: row.vehicleModelId || "",
                                vehicleId: row.vehicleId || "",
                                vehicleGenerationId:
                                    row.vehicleGenerationId || "",
                                vehicleEngineId: row.vehicleEngineId || "",
                                vehicleTransmissionId:
                                    row.vehicleTransmissionId || "",
                                vehicleDriveId: row.vehicleDriveId || "",
                                modificationCode: String(
                                    row.modificationCode || "",
                                ).trim(),
                                legacyVehicleModel:
                                    row.applicabilityCode === "vehicle"
                                        ? ""
                                        : row.legacyVehicleModel
                                          || row.vehicleModel
                                          || "",
                                hours: this.timeNormHoursPayload(row.hours,
                                      ),
                                source: String(row.source || "").trim(),
                            })),
                    }
                    : this.form;
                const saved = await this.saveRecord(this.record, payload);
                this.$emit(exitAfter ? "saved-and-exit" : "saved", saved);

                if (
                    !exitAfter
                    && !this.record._requestWorkTarget
                    && !this.record._requestPartTarget
                ) {
                    await this.fetchHistory(saved);
                }
            } catch (error) {
                this.error = error.message;
            } finally {
                this.busy = false;
            }
        },
        validateWorkType() {
            if (!String(this.form.name || "").trim()) {
                this.error = "Укажите название работы";
                return false;
            }

            if (!this.workGroups().includes(this.form.group)) {
                this.error = "Выберите группу работы из списка";
                return false;
            }

            const seen = new Set();

            for (const row of this.form.timeNorms) {
                if (!this.timeNormHasData(row)) continue;

                if (!row.applicabilityId) {
                    this.error = "Выберите применимость нормы времени";
                    return false;
                }

                if (
                    row.applicabilityCode === "vehicle"
                    && !row.vehicleMakeId
                    && !row.vehicleModelId
                    && !row.vehicleId
                    && !row.vehicleGenerationId
                    && !row.vehicleEngineId
                    && !row.vehicleTransmissionId
                    && !row.vehicleDriveId
                    && !String(row.modificationCode || "").trim()
                ) {
                    this.error =
                        "Для применимости «По авто» выберите ТС или хотя бы один его признак";
                    return false;
                }

                if (row.vehicleModelId && !row.vehicleMakeId) {
                    this.error = "Для модели ТС сначала выберите марку";
                    return false;
                }

                if (row.vehicleGenerationId && !row.vehicleModelId) {
                    this.error = "Для поколения сначала выберите модель ТС";
                    return false;
                }

                if (row.vehicleEngineId && !row.vehicleModelId) {
                    this.error = "Для двигателя сначала выберите модель ТС";
                    return false;
                }

                const hasModification = Boolean(
                    row.vehicleEngineId
                    || row.vehicleTransmissionId
                    || row.vehicleDriveId
                    || String(row.modificationCode || "").trim(),
                );

                if (
                    hasModification
                    && !row.vehicleGenerationId
                    && !row.vehicleId
                ) {
                    this.error =
                        "Для двигателя, трансмиссии, привода или модификации выберите поколение либо конкретное ТС";
                    return false;
                }

                if (
                    row.hours !== null
                    && row.hours !== ""
                    && !/^\d+(?:\.\d{1,3})?$/.test(String(row.hours))
                ) {
                    this.error =
                        "Норма времени должна быть положительным числом, не более 3 знаков после запятой";
                    return false;
                }

                const hours = this.timeNormHoursPayload(row.hours);
                if (
                    hours !== null
                    && (!Number.isFinite(hours) || hours < 0.001 || hours > 100000)
                ) {
                    this.error =
                        "Норма времени должна быть от 0,001 до 100000 часов";
                    return false;
                }

                const key = [
                    row.applicabilityId,
                    row.vehicleMakeId,
                    row.vehicleModelId,
                    row.vehicleId,
                    row.vehicleGenerationId,
                    row.vehicleEngineId,
                    row.vehicleTransmissionId,
                    row.vehicleDriveId,
                    String(row.modificationCode || "").trim(),
                    row.applicabilityCode === "vehicle"
                        ? ""
                        : row.legacyVehicleModel || row.vehicleModel,
                ].join(":");

                if (seen.has(key)) {
                    this.error = "Нормы времени с одинаковой применимостью дублируются";
                    return false;
                }

                seen.add(key);
            }

            return true;
        },
        timeNormHasData(row) {
            return Boolean(
                row.id
                || (row.hours !== null && row.hours !== "")
                || String(row.source || "").trim()
                || row.vehicleMakeId
                || row.vehicleModelId
                || row.vehicleId
                || row.vehicleGenerationId
                || row.vehicleEngineId
                || row.vehicleTransmissionId
                || row.vehicleDriveId
                || String(row.modificationCode || "").trim()
                || (
                    row.applicabilityCode
                    && row.applicabilityCode !== "general"
                ),
            );
        },
        validateStationSchedule() {
            if (!this.form.workdayStart || !this.form.workdayEnd) {
                this.error = "Укажите время начала и окончания работы СТО";

                return false;
            }

            if (this.form.workdayStart >= this.form.workdayEnd) {
                this.error =
                    "Время окончания работы должно быть позже времени начала";

                return false;
            }

            if (!this.form.workingDays.length) {
                this.error = "Выберите хотя бы один рабочий день СТО";

                return false;
            }

            return true;
        },
        addItem() {
            this.form.items.push({
                workTypeId: "",
                timeNormId: "",
                qty: 1,
            });
        },
        removeItem(index) {
            this.form.items.splice(index, 1);
        },
        resetTimeNorm(item) {
            item.timeNormId = "";
        },
        openHistory() {
            this.$emit("vehicle-history", this.record.id);
        },
        openRecord(record) {
            this.$emit("open-record", record);
        },
        addTimeNorm() {
            if (!this.canAddTimeNorm) return;

            this.form.timeNorms.push(this.blankTimeNorm());
        },
        removeTimeNorm(index) {
            const [removed] = this.form.timeNorms.splice(index, 1);

            if (removed?.id && !String(removed.id).startsWith("new-")) {
                this.deletedTimeNormIds.push(removed.id);
            }

            if (!this.form.timeNorms.length) {
                this.form.timeNorms.push(this.blankTimeNorm());
            }
        },
        applicabilityChanged(row, applicability) {
            row.applicability = applicability?.name || "";
            row.applicabilityCode = applicability?.code || "custom";

            if (row.applicabilityCode !== "vehicle") {
                row.vehicleMakeId = "";
                row.vehicleMake = "";
                row.vehicleModelId = "";
                row.vehicleModel = "";
                this.clearDetailedVehicleApplicability(row);
                row.legacyVehicleModel = "";
            }
        },
        loadVehicleMakes(search) {
            return marketplaceApi.vehicleMakes(search);
        },
        async loadVehicleModels(row, search) {
            if (!row.vehicleMakeId) return [];

            const models = await marketplaceApi.vehicleModels(
                row.vehicleMakeId,
                search,
                true,
            );

            return models.map((model) => ({
                ...model,
                name: model.model,
            }));
        },
        vehicleMakeChanged(row, make) {
            row.vehicleMake = make?.name || "";
            row.vehicleModelId = "";
            row.vehicleModel = "";
            this.clearDetailedVehicleApplicability(row);
        },
        vehicleMakeQuery(row) {
            row.vehicleMake = "";
            row.vehicleModelId = "";
            row.vehicleModel = "";
            this.clearDetailedVehicleApplicability(row);
        },
        vehicleModelChanged(row, model) {
            row.vehicleModel = model?.name || model?.model || "";
            this.clearDetailedVehicleApplicability(row);
        },
        vehicleModelQuery(row) {
            row.vehicleModel = "";
            this.clearDetailedVehicleApplicability(row);
        },
        async loadVehicles(search) {
            const vehicles = await marketplaceApi.searchVehicles(search);

            return vehicles.map((vehicle) => ({
                ...vehicle,
                name: [
                    vehicle.vehicle,
                    vehicle.vin ? `VIN ${vehicle.vin}` : "",
                    vehicle.plate || "",
                ].filter(Boolean).join(" · "),
            }));
        },
        vehicleChanged(row, vehicle) {
            this.clearDetailedVehicleApplicability(row);
            row.vehicleId = vehicle?.id || "";
            row.vehicleLabel = vehicle?.name || "";
            row.vin = vehicle?.vin || "";
            row.vehicleMakeId = vehicle?.vehicleMakeId || "";
            row.vehicleMake = vehicle?.make || "";
            row.vehicleModelId = vehicle?.vehicleModelId || "";
            row.vehicleModel = vehicle?.model || "";
        },
        vehicleQuery(row) {
            row.vehicleMakeId = "";
            row.vehicleMake = "";
            row.vehicleModelId = "";
            row.vehicleModel = "";
            this.clearDetailedVehicleApplicability(row);
        },
        loadVehicleGenerations(row, search) {
            if (!row.vehicleModelId) return [];

            return marketplaceApi.vehicleGenerations(
                row.vehicleModelId,
                search,
            );
        },
        vehicleGenerationChanged(row, generation) {
            row.vehicleGeneration = generation?.name || "";
            row.vehicleEngineId = "";
            row.vehicleEngine = "";
        },
        vehicleGenerationQuery(row) {
            row.vehicleGeneration = "";
            row.vehicleEngineId = "";
            row.vehicleEngine = "";
        },
        loadVehicleEngines(row, search) {
            if (!row.vehicleModelId) return [];

            return marketplaceApi.vehicleEngines(
                row.vehicleModelId,
                row.vehicleGenerationId,
                search,
            );
        },
        vehicleEngineChanged(row, engine) {
            row.vehicleEngine = engine?.name || "";
        },
        vehicleEngineQuery(row) {
            row.vehicleEngine = "";
        },
        loadVehicleTransmissions(search) {
            return marketplaceApi.vehicleTransmissions(search);
        },
        vehicleTransmissionChanged(row, transmission) {
            row.vehicleTransmission = transmission?.name || "";
        },
        vehicleTransmissionQuery(row) {
            row.vehicleTransmission = "";
        },
        loadVehicleDrives(search) {
            return marketplaceApi.vehicleDrives(search);
        },
        vehicleDriveChanged(row, drive) {
            row.vehicleDrive = drive?.name || "";
        },
        vehicleDriveQuery(row) {
            row.vehicleDrive = "";
        },
        normalizeTimeNormHours(row, event) {
            const normalized = String(event.target.value || "")
                .replace(",", ".")
                .replace(/[^0-9.]/g, "");
            const [whole = "", ...decimals] = normalized.split(".");

            row.hours = decimals.length
                ? `${whole}.${decimals.join("").slice(0, 3)}`
                : whole;
            event.target.value = row.hours;
        },
        timeNormHoursPayload(value) {
            if (value === null || value === "") return null;

            return Number(String(value).replace(",", "."));
        },
        clearDetailedVehicleApplicability(row) {
            row.vehicleId = "";
            row.vehicleLabel = "";
            row.vin = "";
            row.vehicleGenerationId = "";
            row.vehicleGeneration = "";
            row.vehicleEngineId = "";
            row.vehicleEngine = "";
            row.vehicleTransmissionId = "";
            row.vehicleTransmission = "";
            row.vehicleDriveId = "";
            row.vehicleDrive = "";
            row.modificationCode = "";
        },
        clearVehicleGenerationDetails(row) {
            row.vehicleGenerationId = "";
            row.vehicleGeneration = "";
            row.vehicleEngineId = "";
            row.vehicleEngine = "";
        },
        addApplicability(row, name) {
            this.applicabilityTarget = row;
            this.newApplicabilityName = name;
            this.applicabilityError = "";
            this.applicabilityDialogOpen = true;
            this.$nextTick(() => this.$refs.applicabilityDialog?.showModal());
        },
        closeApplicabilityDialog() {
            if (this.applicabilityBusy) return;

            this.$refs.applicabilityDialog?.close();
            this.applicabilityDialogOpen = false;
            this.applicabilityTarget = null;
        },
        async saveApplicability() {
            const name = this.newApplicabilityName.trim();

            if (!name) {
                this.applicabilityError = "Укажите название применимости";
                return;
            }

            this.applicabilityBusy = true;
            this.applicabilityError = "";

            try {
                const saved = await this.saveRecord({
                    id: `new-${Date.now()}`,
                    kind: "normApplicabilities",
                    isNew: true,
                }, {
                    name,
                    active: true,
                },
                );

                if (this.applicabilityTarget) {
                    this.applicabilityTarget.applicabilityId = saved.id;
                    this.applicabilityChanged(
                        this.applicabilityTarget,
                        saved);
                }

                this.applicabilityBusy = false;
                this.closeApplicabilityDialog();
            } catch (error) {
                this.applicabilityError = error.message;
            } finally {
                this.applicabilityBusy = false;
            }
        },
        async fetchHistory(record = this.record) {
            if (!this.loadHistory || record.isNew || !record.id) {
                this.history = [];
                return;
            }

            this.historyLoading = true;

            try {
                this.history = await this.loadHistory(record.kind, record.id);
            } catch (error) {
                this.error = error.message;
            } finally {
                this.historyLoading = false;
            }
        },
        historyAction(action) {
            return HISTORY_ACTIONS[action] || action;
        },
        historyField(field) {
            return HISTORY_FIELD_LABELS[field] || field;
        },
        historyFields(fields) {
            return (fields || [])
                .map((field) => this.historyField(field))
                .join(", ");
        },
    },
};
</script>

<template>
    <section class="document-panel">
        <p v-if="record.kind === 'parts'" class="info-banner part-origin">
            Добавлено организацией:
            {{
                record.createdByOrganization ||
                "Будет зафиксировано при сохранении"
            }}
        </p>
        <span class="eyebrow">{{ record.kind === "parts" ? "Общий справочник ЗЧ" : "Справочник УК" }}</span>
        <h1>{{ title }}</h1>
        <p>{{ record.name || record.vehicle || "Новая запись" }}</p>

        <form class="form-grid" @submit.prevent="save()">
            <fieldset
                v-if="record.kind === 'stations'"
                class="full station-schedule"
                :disabled="!editable || busy"
            >
                <legend>Время и график работы СТО</legend>

                <div class="station-hours">
                    <label>
                        <span>Время начала работы</span>
                        <input
                            v-model="form.workdayStart"
                            type="time"
                            step="60"
                            required
                        />
                    </label>
                    <label>
                        <span>Время окончания работы</span>
                        <input
                            v-model="form.workdayEnd"
                            type="time"
                            step="60"
                            required
                        />
                    </label>
                </div>

                <span class="station-days-label">Рабочие дни</span>
                <div class="station-days">
                    <label
                        v-for="day in weekdays"
                        :key="day.value"
                        class="station-day"
                    >
                        <input
                            v-model="form.workingDays"
                            type="checkbox"
                            :value="day.value"
                        />
                        <span>{{ day.label }}</span>
                    </label>
                </div>
            </fieldset>

            <label
                v-for="(label, key) in schema"
                :key="key"
                :class="{ 'checkbox-label': isBool(key) }"
            >
                <span class="field-label-with-help">
                    {{ label }}
                    <InfoHint v-if="fieldHelp(key)" :text="fieldHelp(key)" />
                </span>

                <input
                    v-if="isBool(key)"
                    v-model="form[key]"
                    type="checkbox"
                    :disabled="!editable || busy"
                />

                <ReferencePicker
                    v-else-if="key === 'manufacturerId'"
                    v-model="form.manufacturerId"
                    :label="
                        references.partManufacturers?.find(
                            (row) => row.id === form.manufacturerId,
                        )?.name || ''
                    "
                    :options="references.partManufacturers || []"
                    :max-results="100000"
                    :show-all-on-focus-limit="100000"
                    :disabled="!editable || busy"
                    create-label="Добавить производителя"
                    @create="createManufacturer"
                />
                <template v-else-if="key === 'supplierId'"
                    >
                    <ReferencePicker
                        v-model="form.supplierId"
                    :label="
                            references.suppliers?.find(
                                (row) => row.id === form.supplierId,
                            )?.name || ''
                        "
                        :options="references.suppliers || []"
                    :max-results="100000"
                        :show-all-on-focus-limit="100000"
                        :disabled="!editable || busy"
                create-label="Добавить поставщика"
                        @create="openSupplier"
                    />
                    <button
                        v-if="editable"
                        type="button"
                        class="link-btn"
                        :disabled="busy"
                        @click="openSupplier()"
                    >
                        Добавить поставщика
                    </button>
                </template>
                <input v-else-if="['purchasePrice', 'salePrice'].includes(key)" v-model.number="form[key]" type="number" min="0" step="0.01" :disabled="!editable || busy" required />
                <select
                    v-else-if="key === 'group'"
                    v-model="form[key]"
                    :disabled="!editable || busy"
                    required
                >
                    <option value="" disabled>Выберите группу</option>
                    <option v-for="group in workGroups()" :key="group">
                        {{ group }}
                    </option>
                </select>

                <select
                    v-else-if="isSelect(key)"
                    v-model="form[key]"
                    :disabled="!editable || busy"
                    :required="key === 'workTypeId'"
                >
                    <option value="">
                        {{
                            key === "parentId"
                                ? "Основная причина"
                                : "Выберите работу"
                        }}
                    </option>
                    <option
                        v-for="option in options(key)"
                        :key="option.id"
                        :value="option.id"
                    >
                        {{ option.name }}
                    </option>
                </select>

                <select
                    v-else-if="key === 'unit'"
                    v-model="form[key]"
                    :disabled="!editable || busy"
                    required
                >
                    <option
                        v-for="unit in references.partUnits"
                        :key="unit"
                        :value="unit"
                    >
                        {{ unit }}
                    </option>
                </select>

                <input
                    v-else-if="isNumber(key)"
                    v-model.number="form[key]"
                    type="number"
                    :min="minFor(key)"
                    :step="stepFor(key)"
                    :disabled="!editable || busy"
                />

                <input
                    v-else
                    v-model="form[key]"
                    :type="inputType(key)"
                    :disabled="!editable || busy"
                    :required="isRequired(key)"
                />
            </label>

            <section v-if="isRegulations" class="full catalog-related">
                <h3>Работы регламента</h3>

                <div
                    v-for="(item, index) in form.items"
                    :key="index"
                    class="inline-form"
                >
                    <select
                        v-model="item.workTypeId"
                        :disabled="!editable"
                        @change="resetTimeNorm(item)"
                    >
                        <option value="">Вид работы</option>
                        <option
                            v-for="workType in references.workTypes"
                            :key="workType.id"
                            :value="workType.id"
                        >
                            {{ workType.name }}
                        </option>
                    </select>

                    <select v-model="item.timeNormId" :disabled="!editable">
                        <option value="">Без нормы</option>
                        <option
                            v-for="norm in timeNormsForItem(item)"
                            :key="norm.id"
                            :value="norm.id"
                        >
                            {{ norm.hours }} ч · {{ norm.vehicleModel }}
                        </option>
                    </select>

                    <input
                        v-model.number="item.qty"
                        type="number"
                        step="0.001"
                        min="0.001"
                        :disabled="!editable"
                    />

                    <button
                        v-if="editable"
                        class="ghost-btn"
                        type="button"
                        @click="removeItem(index)"
                    >
                        ×
                    </button>
                </div>

                <button
                    v-if="editable"
                    type="button"
                    class="ghost-btn"
                    @click="addItem"
                >
                    Добавить работу регламента
                </button>
            </section>

            <section
                v-if="record.kind === 'customers'"
                class="full catalog-related"
            >
                <h3>Автомобили заказчика</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Автомобиль</th>
                                <th>VIN / госномер</th>
                                <th>Стоимость нормо/часа, ₽</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="vehicle in customerVehicles"
                                :key="vehicle.id"
                            >
                                <td>{{ vehicle.vehicle }}</td>
                                <td>
                                    {{ vehicle.vin }}
                                    <small>{{ vehicle.plate || "—" }}</small>
                                </td>
                                <td>{{ vehicle.laborHourRate ?? "Не указана" }}</td>
                                <td>
                                    <button
                                        type="button"
                                        class="ghost-btn"
                                        @click="openRecord(vehicle)"
                                    >
                                        Изменить
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!customerVehicles.length" class="muted">
                    У заказчика пока нет автомобилей.
                </p>
            </section>

            <section
                v-if="record.kind === 'workTypes'"
                class="full catalog-related work-norm-editor"
            >
                <div class="section-title">
                    <h3>Нормы времени</h3>
                </div>
                <div class="table-wrap excel-grid-wrap">
                    <table class="excel-grid time-norm-grid">
                        <thead>
                            <tr>
                                <th class="resizable-column">
                                    Применимость
                                    <span
                                        class="column-resize-handle"
                                        @pointerdown="startColumnResize"
                                    />
                                </th>
                                <th class="resizable-column">
                                    Конкретное ТС (VIN)
                                    <span
                                        class="column-resize-handle"
                                        @pointerdown="startColumnResize"
                                    />
                                </th>
                                <th class="resizable-column">
                                    Марка ТС
                                    <span
                                        class="column-resize-handle"
                                        @pointerdown="startColumnResize"
                                    />
                                </th>
                                <th class="resizable-column">
                                    Модель ТС
                                    <span
                                        class="column-resize-handle"
                                        @pointerdown="startColumnResize"
                                    />
                                </th>
                                <th class="resizable-column">
                                    Поколение
                                    <span
                                        class="column-resize-handle"
                                        @pointerdown="startColumnResize"
                                    />
                                </th>
                                <th class="resizable-column">
                                    Двигатель
                                    <span
                                        class="column-resize-handle"
                                        @pointerdown="startColumnResize"
                                    />
                                </th>
                                <th class="resizable-column">
                                    Трансмиссия
                                    <span
                                        class="column-resize-handle"
                                        @pointerdown="startColumnResize"
                                    />
                                </th>
                                <th class="resizable-column">
                                    Привод
                                    <span
                                        class="column-resize-handle"
                                        @pointerdown="startColumnResize"
                                    />
                                </th>
                                <th class="resizable-column">
                                    Код модификации
                                    <span
                                        class="column-resize-handle"
                                        @pointerdown="startColumnResize"
                                    />
                                </th>
                                <th class="resizable-column">
                                    Норма, ч
                                    <span
                                        class="column-resize-handle"
                                        @pointerdown="startColumnResize"
                                    />
                                </th>
                                <th class="resizable-column">
                                    Источник
                                    <span
                                        class="column-resize-handle"
                                        @pointerdown="startColumnResize"
                                    />
                                </th>
                                <th class="row-command-column">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="norm in form.timeNorms"
                                :key="norm.id || norm._clientId"
                            >
                                <td>
                                    <ReferencePicker
                                        v-model="norm.applicabilityId"
                                        :label="norm.applicability"
                                        :options="applicabilityOptions"
                                        :disabled="!editable || busy"
                                        placeholder="Выберите применимость"
                                        create-label="Добавить Применимость"
                                        @select="
                                            applicabilityChanged(norm, $event)
                                        "
                                        @create="addApplicability(norm, $event)"
                                    />
                                </td>
                                <td>
                                    <ReferencePicker
                                        v-model="norm.vehicleId"
                                        :label="norm.vehicleLabel || norm.vin"
                                        :load-options="loadVehicles"
                                        :disabled="
                                            !editable ||
                                            busy ||
                                            norm.applicabilityCode !== 'vehicle'
                                        "
                                        placeholder="VIN, госномер или ТС"
                                        @select="vehicleChanged(norm, $event)"
                                        @query="vehicleQuery(norm)"
                                    />
                                </td>
                                <td>
                                    <ReferencePicker
                                        v-model="norm.vehicleMakeId"
                                        :label="norm.vehicleMake"
                                        :load-options="loadVehicleMakes"
                                        :disabled="
                                            !editable ||
                                            busy ||
                                            norm.applicabilityCode !== 'vehicle'
                                        "
                                        placeholder="Марка ТС"
                                        @select="
                                            vehicleMakeChanged(norm, $event)
                                        "
                                        @query="vehicleMakeQuery(norm)"
                                    />
                                </td>
                                <td>
                                    <ReferencePicker
                                        v-model="norm.vehicleModelId"
                                        :label="norm.vehicleModel"
                                        :load-options="
                                            (search) =>
                                                loadVehicleModels(norm, search)
                                        "
                                        :disabled="
                                            !editable ||
                                            busy ||
                                            norm.applicabilityCode !==
                                                'vehicle' ||
                                            !norm.vehicleMakeId
                                        "
                                        placeholder="Модель ТС"
                                        @select="
                                            vehicleModelChanged(norm, $event)
                                        "
                                        @query="vehicleModelQuery(norm)"
                                    />
                                </td>
                                <td>
                                    <ReferencePicker
                                        v-model="norm.vehicleGenerationId"
                                        :label="norm.vehicleGeneration"
                                        :load-options="
                                            (search) =>
                                                loadVehicleGenerations(
                                                    norm,
                                                    search,
                                                )
                                        "
                                        :disabled="
                                            !editable ||
                                            busy ||
                                            norm.applicabilityCode !==
                                                'vehicle' ||
                                            !norm.vehicleModelId
                                        "
                                        placeholder="Поколение"
                                        @select="
                                            vehicleGenerationChanged(
                                                norm,
                                                $event,
                                            )
                                        "
                                        @query="vehicleGenerationQuery(norm)"
                                    />
                                </td>
                                <td>
                                    <ReferencePicker
                                        v-model="norm.vehicleEngineId"
                                        :label="norm.vehicleEngine"
                                        :load-options="
                                            (search) =>
                                                loadVehicleEngines(norm, search)
                                        "
                                        :disabled="
                                            !editable ||
                                            busy ||
                                            norm.applicabilityCode !==
                                                'vehicle' ||
                                            !norm.vehicleModelId ||
                                            (!norm.vehicleGenerationId &&
                                                !norm.vehicleId)
                                        "
                                        placeholder="Двигатель"
                                        @select="
                                            vehicleEngineChanged(norm, $event)
                                        "
                                        @query="vehicleEngineQuery(norm)"
                                    />
                                </td>
                                <td>
                                    <ReferencePicker
                                        v-model="norm.vehicleTransmissionId"
                                        :label="norm.vehicleTransmission"
                                        :load-options="loadVehicleTransmissions"
                                        :disabled="
                                            !editable ||
                                            busy ||
                                            norm.applicabilityCode !==
                                                'vehicle' ||
                                            (!norm.vehicleGenerationId &&
                                                !norm.vehicleId)
                                        "
                                        placeholder="Трансмиссия"
                                        @select="
                                            vehicleTransmissionChanged(
                                                norm,
                                                $event,
                                            )
                                        "
                                        @query="vehicleTransmissionQuery(norm)"
                                    />
                                </td>
                                <td>
                                    <ReferencePicker
                                        v-model="norm.vehicleDriveId"
                                        :label="norm.vehicleDrive"
                                        :load-options="loadVehicleDrives"
                                        :disabled="
                                            !editable ||
                                            busy ||
                                            norm.applicabilityCode !==
                                                'vehicle' ||
                                            (!norm.vehicleGenerationId &&
                                                !norm.vehicleId)
                                        "
                                        placeholder="Привод"
                                        @select="
                                            vehicleDriveChanged(norm, $event)
                                        "
                                        @query="vehicleDriveQuery(norm)"
                                    />
                                </td>
                                <td>
                                    <input
                                        v-model.trim="norm.modificationCode"
                                        type="text"
                                        maxlength="100"
                                        placeholder="Код"
                                        :disabled="
                                            !editable ||
                                            busy ||
                                            norm.applicabilityCode !==
                                                'vehicle' ||
                                            (!norm.vehicleGenerationId &&
                                                !norm.vehicleId)
                                        "
                                    />
                                </td>
                                <td>
                                    <input
                                        :value="norm.hours ?? ''"
                                        type="text"
                                        inputmode="decimal"
                                        placeholder="Пусто"
                                        :disabled="!editable || busy"
                                        @input="
                                            normalizeTimeNormHours(norm, $event)
                                        "
                                    />
                                </td>
                                <td>
                                    <input
                                        v-model="norm.source"
                                        type="text"
                                        maxlength="10000"
                                        placeholder="Источник"
                                        :disabled="!editable || busy"
                                    />
                                </td>
                                <td>
                                    <button
                                        v-if="editable"
                                        type="button"
                                        class="delete-row"
                                        :disabled="busy"
                                        aria-label="Удалить норму времени"
                                        title="Удалить норму времени после сохранения"
                                        @click="
                                            removeTimeNorm(
                                                form.timeNorms.indexOf(norm),
                                            )
                                        "
                                    >
                                        ×
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <button
                    v-if="editable"
                    type="button"
                    class="grid-add-row"
                    :disabled="busy || !canAddTimeNorm"
                    aria-label="Добавить строку нормы времени"
                    title="Добавить строку"
                    @click="addTimeNorm"
                >
                    ＋
                </button>
            </section>

            <section v-if="record.proposedName" class="full feature-card">
                <p>Предложение СТО: {{ record.name }} → {{ record.proposedName }}; активность: {{ record.proposedActive ? 'Да' : 'Нет' }}.</p>
                <template v-if="record.permissions?.canReview">
                    <button type="button" class="primary-btn" :disabled="busy" @click="reviewManufacturer('approved')">Согласовать</button>
                    <button type="button" class="ghost-btn" :disabled="busy" @click="reviewManufacturer('rejected')">Отклонить</button>
                </template>
            </section>
            <div class="full catalog-actions">
                <button
                    v-if="editable"
                    class="office-action primary-btn"
                    :disabled="busy"
                 title="Сохранить" aria-label="Сохранить"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 3h12l4 4v14H3V3h2z M7 3v7h10V3 M7 21v-7h10v7" /></svg></button>

                <button
                    v-if="editable"
                    type="button"
                    class="office-action ghost-btn"
                    :disabled="busy"
                    @click="save(true)"
                 title="Сохранить и выйти" aria-label="Сохранить и выйти"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 3h11v6H7V3 M3 3v18h11 M15 12h7m-3-3 3 3-3 3" /></svg></button>

                <button
                    v-if="record.kind === 'vehicles' && record.id"
                    type="button"
                    class="ghost-btn"
                    @click="openHistory"
                >
                    История ТО
                </button>
            </div>

            <p v-if="error" class="error-message full">{{ error }}</p>
        </form>

        <dialog
            v-if="applicabilityDialogOpen"
            ref="applicabilityDialog"
            class="action-dialog applicability-dialog"
            @cancel.prevent="closeApplicabilityDialog"
        >
            <form @submit.prevent="saveApplicability">
                <span class="eyebrow">Справочник применимости</span>
                <h2>Новая применимость нормы времени</h2>
                <label>
                    <span>Название применимости</span>
                    <input
                        v-model="newApplicabilityName"
                        maxlength="1000"
                        required
                        autofocus
                        :disabled="applicabilityBusy"
                    />
                </label>
                <p v-if="applicabilityError" class="error-message">
                    {{ applicabilityError }}
                </p>
                <div class="action-buttons">
                    <button
                        type="button"
                        class="ghost-btn"
                        :disabled="applicabilityBusy"
                        @click="closeApplicabilityDialog"
                    >
                        Отмена
                    </button>
                    <button class="primary-btn" :disabled="applicabilityBusy">
                        {{ applicabilityBusy ? "Сохраняем…" : "Создать" }}
                    </button>
                </div>
            </form>
        </dialog>

        <section v-if="!record.isNew" class="catalog-history">
            <h3>Журнал изменений</h3>
            <p v-if="historyLoading" class="muted">Загружаем журнал…</p>
            <p v-else-if="!history.length" class="muted">
                Изменений пока нет.
            </p>
            <ol v-else>
                <li v-for="event in history" :key="event.id">
                    <strong>{{ historyAction(event.action) }}</strong>
                    <span>
                        {{ event.actor }} · {{ formatDateTime(event.occurredAt) }}
                    </span>
                    <small>
                        {{
                            historyFields(event.changedFields) ||
                            "Без изменений полей"
                        }}
                    </small>
                </li>
            </ol>
        </section>
    </section>
<SupplierCreateDialog
        v-if="supplierDialogOpen"
        :initial-name="supplierInitialName"
        :references="references"
        :save-record="saveRecord"
        @created="supplierCreated"
        @close="supplierDialogOpen = false"
    />
</template>
