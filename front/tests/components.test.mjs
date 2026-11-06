import test from "node:test";
import assert from "node:assert/strict";
import { createServer } from "vite";
import { createSSRApp, reactive } from "vue";
import { renderToString } from "vue/server-renderer";
import { catalogNames, requestFields } from "../src/data/permissions.js";
import {
    applyVehicleSelection,
    resetReasonSubtype,
} from "../src/features/requests/formState.js";
import { APP_VERSION, formatVersion } from "../src/data/version.js";
import { calculatePlannedCompletion } from "../src/utils/plannedCompletion.js";

const root = new URL("..", import.meta.url).pathname;
const request = () => ({
    ...Object.fromEntries(
        requestFields.map((key) => [key, key === "works" ? [] : ""]),
    ),
    id: "28b677ae-a3d6-4c72-99e9-dd65cf5f85ca",
    number: "ZR-000001",
    stage: 0,
    revision: 0,
    vehicle: "",
    vin: "",
    plate: "",
    company: "",
    previousServiceMileage: null,
    parts: [],
    attachments: [],
    diagnosticReports: [],
    approvals: [],
    statusPeriods: [],
    history: [],
    permissions: {
        editableFields: requestFields.filter((key) => key !== "works"),
        canAdvance: true,
        canReturn: false,
        canUpload: true,
    },
    workOrder: null,
    invoice: null,
});
const references = {
    reasons: [
        { id: "root", parentId: "", name: "Диагностика", code: "diagnostics" },
        { id: "maintenance", parentId: "", name: "ТО", code: "maintenance" },
        {
            id: "child",
            parentId: "root",
            name: "Ходовая часть",
            code: "diagnostics_suspension",
        },
    ],
    workTypes: [],
    timeNorms: [],
    normApplicabilities: [
        { id: "general", name: "Общая", code: "general", active: true },
        { id: "special", name: "Специальная", code: "special", active: true },
        { id: "vehicle", name: "По авто", code: "vehicle", active: true },
    ],
    workGroups: ["Кузов", "Техническое обслуживание", "Прочее"],
    regulations: [],
    suppliers: [],
    parts: [],
    partUnits: [],
    organizations: [],
    accessRights: [],
};

test("planned completion includes all request work norms", () => {
    const result = calculatePlannedCompletion(
        {
            slot: "2026-10-20T11:00",
            stationSlot: "",
            works: [
                { normHours: 1, qty: 1 },
                { normHours: 2, qty: 1.5 },
            ],
        },
        {
            workdayStart: "10:00",
            workdayEnd: "20:00",
            workingDays: [1, 2, 3, 4, 5],
        },
    );

    assert.equal(result, "2026-10-20T15:30");

    assert.equal(
        calculatePlannedCompletion(
            { slot: "2026-10-23T19:30", works: [] },
            {
                workdayStart: "10:00",
                workdayEnd: "20:00",
                workingDays: [1, 2, 3, 4, 5],
            },
        ),
        "2026-10-26T10:00",
    );
});

test("Options API component rendering and interactive methods", async (context) => {
    // Версия под логотипом: x.xx.xxx из semver package.json.
    assert.match(APP_VERSION, /^ver\. \d\.\d{2}\.\d{3}$/);
    assert.equal(formatVersion("0.39.1"), "0.39.001");
    assert.equal(formatVersion("1.5.12"), "1.05.012");

    const server = await createServer({
        root,
        server: {
            middlewareMode: true,
            hmr: false,
            watch: null,
        },
        appType: "custom",
        optimizeDeps: {
            noDiscovery: true,
            include: [],
        },
        logLevel: "error",
    });

    try {
        const { default: RepairEditor } = await server.ssrLoadModule(
            "/src/features/requests/RepairEditor.vue",
        );
        const props = {
            request: request(),
            user: { role: "customer" },
            records: [],
            references,
            busy: false,
            perform: async () => {},
        };

        await context.test(
            "customer form is blank and role-limited",
            async () => {
                const html = await renderToString(
                    createSSRApp(RepairEditor, props),
                );

                assert.match(html, /ZR-000001/);
                assert.match(html, /Предыдущий пробег, км/);
                assert.match(html, /class="form-grid mileage-row"/);
                assert.match(
                    html,
                    /Дополнительная информация \(необязательно\)/,
                );
                assert.match(html, /> Водитель Заказчика </);
                assert.match(html, /> Контактное лицо Заказчика </);
                assert.doesNotMatch(html, /Добавить позицию/);
                assert.doesNotMatch(html, /Добавить запчасть/);
                assert.doesNotMatch(html, /СТО-партнёр/);
                // Черновик: Заказчик видит блок 04 и вносит работы без норм.
                assert.match(html, /class="repair-calculation-section/);
                assert.doesNotMatch(html, /Норма времени/);
                assert.doesNotMatch(
                    html,
                    /Фото, видео и комментарий необязательны/,
                );
            },
        );

        await context.test(
            "manager can edit estimate but not customer description",
            async () => {
                const current = {
                    ...request(),
                    stage: 1,
                    vehicleId: "car",
                    vehicle: "Lada Vesta",
                    vin: "XTA00000000000001",
                    plate: "А001АА77",
                    mileage: "15000",
                    permissions: {
                        ...request().permissions,
                        editableFields: [
                            "contactId",
                            "driverId",
                            "slot",
                            "stationId",
                            "routeComment",
                            "works",
                        ],
                        canEditParts: true,
                        canIssueInvoice: false,
                    },
                };
                const html = await renderToString(
                    createSSRApp(RepairEditor, {
                        ...props,
                        user: { role: "manager" },
                        request: current,
                    }),
                );

                assert.match(
                    html,
                    /Lada Vesta · VIN XTA00000000000001 · А001АА77/,
                );
                assert.doesNotMatch(html, /госномер А001АА77/);
                assert.match(
                    html,
                    /textarea disabled placeholder="Укажите дополнительную информацию/,
                );
                assert.match(html, /Стоимость нормо\/часа/);
                assert.match(html, /class="excel-grid parts-grid"/);
                assert.match(html, /Начните вводить запчасть/);
                assert.match(html, /Производитель/);
                assert.match(html, /aria-label="Добавить строку запчасти"/);
                const addButton = html.match(
                    /<button[^>]*aria-label="Добавить строку запчасти"[^>]*>/,
                )?.[0];
                assert.ok(addButton);
                assert.doesNotMatch(addButton, /disabled/);
                assert.doesNotMatch(html, /Добавить позицию/);
                assert.match(html, /Фото, видео и документы/);
                // Блоки ЗР разделены вкладками и больше не нумеруются.
                assert.doesNotMatch(html, /class="section-title"><span>\d\d<\/span>/);
                // Вкладки ЗР: шесть разделов, при открытии активна первая.
                const tabs = [...html.matchAll(/<button[^>]*role="tab"[^>]*>([^<]*)<\/button>/g)];
                assert.deepEqual(tabs.map((m) => m[1]), [
                    "Автомобиль и причина обращения",
                    "Работы и ЗЧ",
                    "Акты приёма-передачи",
                    "Документы по заявке",
                    "Фото, видео и документы",
                    "Взаиморасчёты",
                    "История ТО и работ",
                ]);
                assert.match(tabs[0][0], /aria-selected="true"/);
                assert.ok(tabs.slice(1).every((m) => /aria-selected="false"/.test(m[0])));
                assert.match(html, /id="zr-tab-works"[^>]*style="display:none;?"|style="display:none;?"[^>]*id="zr-tab-works"/);
                assert.doesNotMatch(
                    html,
                    /Комментарий к решению необязателен; вложения не требуются/,
                );
            },
        );

        await context.test(
            "station gets calculated completion and dedicated slot actions",
            async () => {
                const current = {
                    ...request(),
                    stage: 2,
                    vehicleId: "car",
                    vehicle: "Lada Vesta",
                    vin: "XTA00000000000001",
                    plate: "А001АА77",
                    mileage: "15000",
                    slot: "2026-10-20T11:00",
                    permissions: {
                        editableFields: [],
                        canAdvance: false,
                        canReturn: false,
                        canStationDecide: true,
                        canEditParts: false,
                        canManageParts: false,
                    },
                };
                const html = await renderToString(
                    createSSRApp(RepairEditor, {
                        ...props,
                        user: { role: "station" },
                        request: current,
                    }),
                );

                assert.match(html, /Фактическая дата и время окончания ремонта/);
                assert.match(html, /Ремонт не завершён/);
                assert.match(html, /class="form-grid visit-dates-row"/);
                assert.match(html, /Отказаться от выполнения/);
                assert.match(html, /Предложить другое время/);
                assert.match(html, /Подтвердить время/);
                assert.match(html, /Дата и время визита, подтверждённые СТО/);
            },
        );

        await context.test(
            "manager searches work and can save a request-only manual norm",
            async () => {
                const current = {
                    ...request(),
                    stage: 1,
                    laborHourRate: 1500,
                    works: [
                        {
                            name: "",
                            qty: 1,
                            price: 0,
                            workTypeId: "",
                            timeNormId: "",
                            normHours: null,
                        },
                    ],
                    permissions: {
                        editableFields: ["works"],
                        canEditParts: true,
                    },
                };
                const html = await renderToString(
                    createSSRApp(RepairEditor, {
                        ...props,
                        user: { role: "manager" },
                        request: current,
                        references: {
                            ...references,
                            workTypes: [{ id: "work-1", name: "Диагностика" }],
                        },
                    }),
                );

                assert.match(html, /Начните вводить работу/);
                assert.doesNotMatch(
                    html,
                    /class="search-results work-search-results"/,
                );
                assert.match(html, /aria-label="Норма времени для этой заявки"/);
                assert.match(html, /Итого по работам/);
                assert.match(html, />Кратность</);

                const { default: WorkTypePicker } = await server.ssrLoadModule(
                    "/src/features/requests/WorkTypePicker.vue",
                );
                const pickerContext = {
                    query: "пруж дрос",
                    edited: true,
                    disabled: false,
                    options: [
                        {
                            id: "work-spring",
                            name: "Пружина сервопривода дроссельной заслонки - с/у",
                            active: true,
                        },
                        {
                            id: "work-other",
                            name: "Замена пружины",
                            active: true,
                        },
                    ],
                };
                pickerContext.normalize = (value) =>
                    WorkTypePicker.methods.normalize.call(pickerContext, value);

                const results =
                    WorkTypePicker.computed.results.call(pickerContext);

                assert.deepEqual(
                    results.map((item) => item.id),
                    ["work-spring"],
                );

                const limitedContext = {
                    query: "работа",
                    edited: true,
                    disabled: false,
                    options: Array.from({ length: 20 }, (_, index) => ({
                        id: `work-${index + 1}`,
                        name: `Работа ${index + 1}`,
                        active: true,
                    })),
                    normalize: pickerContext.normalize,
                };
                const limitedResults =
                    WorkTypePicker.computed.results.call(limitedContext);

                assert.equal(limitedResults.length, 15);
            },
        );

        await context.test(
            "station can supplement works and parts during its approval",
            async () => {
                const current = {
                    ...request(),
                    stage: 2,
                    laborHourRate: 1500,
                    permissions: {
                        editableFields: ["works"],
                        canStationDecide: true,
                        canEditParts: true,
                    },
                };
                const html = await renderToString(
                    createSSRApp(RepairEditor, {
                        ...props,
                        user: { role: "station" },
                        request: current,
                    }),
                );

                assert.match(html, /aria-label="Добавить строку работы"/);
                assert.match(html, /class="excel-grid works-grid compact-works-grid"/);
                assert.match(html, /Запчасти и поставщики/);
                assert.match(html, /class="excel-grid parts-grid"/);
                assert.match(html, /Начните вводить запчасть/);
                assert.match(html, /Комментарий к решению необязателен/);
            },
        );

        await context.test(
            "works grid is compact with totals; work card shows all fields",
            async () => {
                const current = {
                    ...request(),
                    stage: 2,
                    laborHourRate: 1500,
                    stationId: "station-1",
                    stationDetails: {
                        name: "ООО «КУБ»",
                        address: "г. Москва, ул. Ленинская Слобода, д. 19",
                        contact: "Орлов Максим",
                        phone: "+74953332211",
                        email: "",
                        workday: "10:00–20:00",
                    },
                    repairCompletedAt: "2026-11-05T12:30:00Z",
                    works: [
                        { id: "w1", name: "Замена масла", qty: 2, normHours: 0.5, hourlyRate: 1500, lineAmount: 1500, agentDiscountPercent: 10, comment: "Масло клиента" },
                        { id: "w2", name: "Диагностика", qty: 1, normHours: 1.25, hourlyRate: 1500, lineAmount: 1875, agentDiscountPercent: 10 },
                    ],
                    permissions: { editableFields: [], canEditParts: false },
                };
                const html = await renderToString(
                    createSSRApp(RepairEditor, {
                        ...props,
                        user: { role: "station" },
                        request: current,
                    }),
                );
                const head = html.match(/<thead>([\s\S]*?)<\/thead>/)[1];
                const headers = [...head.matchAll(/<th[^>]*>([^<]*)/g)].map((m) => m[1].trim()).filter(Boolean);

                assert.deepEqual(headers, [
                    "Наименование работы",
                    "Норма времени, ч",
                    "Стоимость нормо/часа, ₽",
                    "Кратность",
                    "Стоимость, ₽",
                    "Агентская скидка, ₽",
                ]);
                assert.doesNotMatch(head, /Комментарий/);
                const foot = html.match(/<tfoot>([\s\S]*?)<\/tfoot>/)[1];
                assert.match(foot, /Итого по работам/);
                assert.match(foot, />2,25</);
                assert.match(foot, />3\s?375</);
                assert.match(foot, />337,5</);
                assert.match(html, /💬 Масло клиента/);
                assert.match(html, /ООО «КУБ»/);
                assert.match(html, /Фактический адрес/);
                assert.match(html, /\+74953332211/);
                assert.doesNotMatch(html, /Ремонт не завершён/);

                const { default: WorkLineDialog } = await server.ssrLoadModule(
                    "/src/features/requests/WorkLineDialog.vue",
                );
                const card = await renderToString(
                    createSSRApp(WorkLineDialog, {
                        work: { ...current.works[0], normApplicabilityLabel: "Общая норма", normIsManual: false },
                        position: 1,
                        hourlyRate: 1500,
                        amount: 1500,
                        showAgentDiscount: true,
                        agentPercent: 10,
                        agentDiscount: 150,
                        scopeText: "общая",
                    }),
                );

                assert.match(card, /Работа № 1/);
                assert.match(card, /Применимость нормы/);
                assert.match(card, /Общая норма/);
                assert.match(card, /Масло клиента/);
                assert.match(card, /Кратность/);
                assert.match(card, /К оплате СТО/);
                assert.match(card, /1\s?350/);
            },
        );

        await context.test(
            "server-selected norm and its scope are shown in the request",
            async () => {
                const current = {
                    ...request(),
                    stage: 1,
                    vehicleId: "vehicle-dashing",
                    make: "Jetour",
                    model: "Dashing",
                    vehicleModelId: "model-dashing",
                    laborHourRate: 1500,
                    works: [
                        {
                            name: "ТО",
                            qty: 1,
                            price: 0,
                            workTypeId: "work-1",
                            timeNormId: "vehicle-norm",
                            normId: "ST-00001-N002",
                            normSelectionScope: "make_model",
                            normApplicabilityLabel:
                                "Применимость к Jetour · Dashing",
                            normHours: 0.8,
                        },
                    ],
                    permissions: { editableFields: ["works"] },
                };
                const html = await renderToString(
                    createSSRApp(RepairEditor, {
                        ...props,
                        user: { role: "manager" },
                        request: current,
                        references: {
                            ...references,
                            workTypes: [{ id: "work-1", name: "ТО" }],
                            timeNorms: [],
                        },
                    }),
                );

                assert.match(html, /Применимость к Jetour · Dashing/);
                assert.doesNotMatch(html, /Норма ST-00001-N002/);
            },
        );

        await context.test(
            "station explicitly accepts manager estimate changes",
            async () => {
                const current = {
                    ...request(),
                    stage: 2,
                    reviewContext: "manager_changes",
                    estimateVersion: 4,
                    reviewBaselineVersion: 4,
                    permissions: {
                        editableFields: ["works"],
                        canStationDecide: true,
                        canEditParts: true,
                    },
                };
                const html = await renderToString(
                    createSSRApp(RepairEditor, {
                        ...props,
                        user: { role: "station" },
                        request: current,
                    }),
                );

                assert.match(html, /УК скорректировала работы или запчасти/);
                assert.match(html, /Принять и направить Заказчику/);
            },
        );

        await context.test(
            "station sends repeated estimate edits back to manager",
            async () => {
                const current = {
                    ...request(),
                    stage: 2,
                    reviewContext: "manager_changes",
                    estimateVersion: 5,
                    reviewBaselineVersion: 4,
                    permissions: {
                        editableFields: ["works"],
                        canStationDecide: true,
                        canEditParts: true,
                    },
                };
                const html = await renderToString(
                    createSSRApp(RepairEditor, {
                        ...props,
                        user: { role: "station" },
                        request: current,
                    }),
                );

                assert.match(html, /Отправить изменения в УК/);
                assert.match(html, /повторно[\s\S]*согласование в УК/);
            },
        );

        await context.test(
            "customer confirms the final request before vehicle intake",
            async () => {
                const current = {
                    ...request(),
                    stage: 3,
                    works: [{ id: "w1", name: "Диагностика", qty: 1, price: 1000 }],
                    permissions: {
                        editableFields: [],
                        canCustomerSlotDecide: true,
                    },
                };
                const html = await renderToString(
                    createSSRApp(RepairEditor, {
                        ...props,
                        user: { role: "customer" },
                        request: current,
                    }),
                );

                assert.match(html, /Финальное согласование/);
                assert.match(html, /Подтвердить финальную заявку/);
                assert.match(html, /Ожидание ТС на СТО/);
                assert.doesNotMatch(html, /В ЗР нет работ/);
            },
        );

        await context.test(
            "request without works cannot be moved further by customer or manager",
            async () => {
                for (const [role, stage, permissions] of [
                    ["customer", 3, { editableFields: [], canCustomerSlotDecide: true }],
                    ["manager", 1, { editableFields: ["works"], canAdvance: true }],
                ]) {
                    const html = await renderToString(
                        createSSRApp(RepairEditor, {
                            ...props,
                            user: { role },
                            request: { ...request(), stage, works: [], permissions },
                        }),
                    );

                    assert.match(html, /В ЗР нет работ/, role);
                    assert.match(
                        html,
                        /<button[^>]*title="В ЗР нет работ[^"]*"[^>]*disabled/,
                        role,
                    );
                }
            },
        );

        await context.test(
            "station gets the vehicle acceptance action while waiting for vehicle",
            async () => {
                const current = {
                    ...request(),
                    stage: 4,
                    vehicle: "Lada Vesta",
                    vin: "XTA00000000000001",
                    plate: "А001АА77",
                    mileage: "15000",
                    permissions: {
                        editableFields: [],
                        canAcceptVehicle: true,
                        canUpload: true,
                    },
                };
                const html = await renderToString(
                    createSSRApp(RepairEditor, {
                        ...props,
                        user: { role: "station" },
                        request: current,
                    }),
                );

                assert.match(html, /Приём и ремонт ТС/);
                assert.match(
                    html,
                    /После прибытия автомобиля оформите электронный акт/,
                );
                assert.match(html, /> Приём ТС </);
            },
        );

        await context.test(
            "unsaved state and save are in route area",
            async () => {
                const html = await renderToString(
                    createSSRApp(RepairEditor, {
                        ...props,
                        dirty: true,
                    }),
                );

                assert.match(html, /route-save-state/);
                assert.match(html, /Изменения не сохранены/);
                assert.match(html, /title="Сохранить"/);
                assert.match(html, /Сохранить и выйти/);
            },
        );

        await context.test(
            "request chat action sits beside save and shows unread count",
            async () => {
                const newChat = await renderToString(
                    createSSRApp(RepairEditor, props),
                );
                const existingChat = await renderToString(
                    createSSRApp(RepairEditor, {
                        ...props,
                        chatCreated: true,
                        chatUnread: 2,
                    }),
                );

                assert.match(
                    newChat,
                    /route-save-state[\s\S]*Сохранить[\s\S]*Создать чат/,
                );
                assert.match(
                    existingChat,
                    /Открыть чат[\s\S]*request-chat-count[^>]*>2</,
                );
            },
        );

        await context.test(
            "dependent request form helpers reset person fields",
            () => {
                const current = request();
                current.contactId = "old-contact";
                current.driverId = "old-driver";
                applyVehicleSelection(current, {
                    id: "car",
                    vehicle: "Марка",
                    vin: "VIN",
                    plate: "НОМЕР",
                    company: "ООО «Тест»",
                    previousServiceMileage: 12000,
                    previousServiceAt: "2026-09-01T10:00:00Z",
                });

                assert.equal(current.vehicleId, "car");
                assert.equal(current.contactId, "");
                assert.equal(current.driverId, "");
                current.reasonSubtypeId = "old";
                resetReasonSubtype(current, true);
                assert.equal(current.reasonSubtypeId, "");
            },
        );

        await context.test(
            "visit date and time use the compact picker and business hours",
            async () => {
                const { default: DateTime } = await server.ssrLoadModule(
                    "/src/components/ConfirmedDateTimeInput.vue",
                );
                const html = await renderToString(
                    createSSRApp(DateTime, {
                        modelValue: "",
                        title: "Дата визита",
                        minTime: "08:00",
                        maxTime: "22:00",
                    }),
                );

                assert.match(html, /confirmed-datetime-trigger/);
                assert.match(html, /confirmed-datetime-panel/);
                assert.match(html, /08:00/);
                assert.match(html, /22:00/);
                assert.doesNotMatch(html, /07:30/);
                assert.doesNotMatch(html, /22:30/);
                assert.doesNotMatch(html, />ОК</);
                assert.doesNotMatch(
                    html,
                    /Выбор фиксируется сразу — отдельная кнопка «ОК» не нужна/,
                );
                assert.match(
                    html,
                    /24-часовой формат, шаг 30 минут · 08:00–22:00/,
                );
            },
        );

        await context.test(
            "payment date accepts past local time and rejects future",
            async () => {
                const { default: DateTime } = await server.ssrLoadModule(
                    "/src/components/ConfirmedDateTimeInput.vue",
                );
                const render = (modelValue) =>
                    renderToString(
                        createSSRApp(DateTime, {
                            modelValue,
                            title: "Дата оплаты (ваше местное время)",
                            pastOnly: true,
                        }),
                    );

                const past = await render("2001-02-03T09:00");
                assert.match(past, /3 февраля 2001 г\., 09:00/);
                assert.doesNotMatch(past, /не ранее текущего времени/);
                assert.doesNotMatch(past, /не позже текущего времени/);
                assert.match(past, /type="date"[^>]*max="\d{4}-\d{2}-\d{2}"/);
                assert.doesNotMatch(past, /type="date"[^>]*min=/);

                const future = await render("2999-01-01T10:00");
                assert.match(future, /не позже текущего времени/);

                const visit = await renderToString(
                    createSSRApp(DateTime, {
                        modelValue: "2001-02-03T09:00",
                        title: "Дата визита",
                    }),
                );
                assert.match(visit, /не ранее текущего времени плюс 30 минут/);
            },
        );

        await context.test(
            "vehicle make and model use dependent searchable selectors",
            async () => {
                const { default: Picker } = await server.ssrLoadModule(
                    "/src/features/catalogs/VehicleReferencePicker.vue",
                );
                const html = await renderToString(
                    createSSRApp(Picker, {
                        make: "",
                        model: "",
                        modelId: "",
                        loadMakes: async () => [],
                        loadModels: async () => [],
                        createModel: async () => ({}),
                    }),
                );

                assert.match(html, /Начните вводить марку/);
                assert.match(html, /Сначала выберите марку/);
                assert.match(html, /disabled/);
            },
        );

        await context.test(
            "person directory shows own organization and phone mask",
            async () => {
                const { default: PersonEditor } = await server.ssrLoadModule(
                    "/src/features/catalogs/PersonCatalogEditor.vue",
                );
                const html = await renderToString(
                    createSSRApp(PersonEditor, {
                        record: { id: "new", kind: "drivers", isNew: true },
                        records: [],
                        user: {
                            role: "customer",
                            organization: "ООО «Тест»",
                            organizationId: "org",
                        },
                        saveRecord: async () => {},
                        deleteRecord: async () => {},
                    }),
                );

                assert.match(html, /Новое: водитель/);
                assert.match(html, /ООО «Тест»/);
                assert.match(html, /\+7 \(ххх\) ххх хх хх/);
                assert.match(html, /Сохранить и выйти/);
            },
        );

        await context.test(
            "vehicle editor assigns people only from directories",
            async () => {
                const { default: VehicleEditor } = await server.ssrLoadModule(
                    "/src/features/catalogs/VehicleCatalogEditor.vue",
                );
                const html = await renderToString(
                    createSSRApp(VehicleEditor, {
                        record: {
                            id: "new-1",
                            kind: "vehicles",
                            isNew: true,
                            organizationId: "org",
                            permissions: { canEdit: true },
                        },
                        records: [
                            {
                                id: "driver-1",
                                kind: "drivers",
                                organizationId: "org",
                                fullName: "Иванов Иван",
                                phone: "+7 (999) 000 00 00",
                            },
                        ],
                        user: { role: "customer", organizationId: "org" },
                        saveRecord: async () => {},
                        deleteRecord: async () => {},
                    }),
                );

                assert.match(html, /Выберите из справочника/);
                assert.match(html, /Иванов Иван/);
                assert.match(html, /Добавить ещё/);
                assert.doesNotMatch(html, />Добавить<\/button>/);
                assert.doesNotMatch(html, /placeholder="\+79991234567"/);
                assert.doesNotMatch(html, /Стоимость нормо\/часа/);
                assert.match(html, /Сохранить и выйти/);
            },
        );

        await context.test(
            "vehicle editor explains invalid VIN instead of silently blocking save",
            async () => {
                const { default: VehicleEditor } = await server.ssrLoadModule(
                    "/src/features/catalogs/VehicleCatalogEditor.vue",
                );
                let saves = 0;
                const state = {
                    editable: true,
                    form: {
                        make: "JETOUR",
                        model: "DASHING",
                        vehicleModelId: "9baf6937-c468-4e5f-86df-e4379d673ffa",
                        vin: "1111111111111234",
                        plate: "А111АА78",
                        year: 2025,
                        baselineServiceMileage: 200,
                        contactPersonIds: [],
                        driverPersonIds: [],
                    },
                    record: { isNew: true },
                    isNew: true,
                    isManager: false,
                    peopleByKind: { contacts: [], drivers: [] },
                    error: "",
                    fieldErrors: {},
                    busy: false,
                    saveRecord: async () => {
                        saves += 1;
                    },
                };

                await VehicleEditor.methods.save.call(state);

                assert.equal(saves, 0);
                assert.match(state.fieldErrors.vin, /ровно 17/);
                assert.match(
                    state.fieldErrors.vin,
                    /например X96330200R0000001/,
                );
            },
        );

        await context.test(
            "vehicle editor explains the correct format for every invalid field",
            async () => {
                const { validateVehicleForm } = await server.ssrLoadModule(
                    "/src/features/catalogs/vehicleValidation.js",
                );
                const errors = validateVehicleForm(
                    {
                        make: "",
                        model: "",
                        vehicleModelId: "",
                        vin: "123",
                        plate: "А111АА78!",
                        year: 1800,
                        baselineServiceMileage: -1,
                        laborHourRate: 1.999,
                        laborRateCoefficient: 0,
                        organizationId: "",
                        contactPersonIds: ["unknown"],
                        driverPersonIds: ["driver", "driver"],
                    },
                    {
                        isManager: true,
                        isNew: true,
                        contactIds: [],
                        driverIds: ["driver"],
                    },
                );

                for (const field of [
                    "organizationId",
                    "vehicleReference",
                    "vin",
                    "plate",
                    "year",
                    "baselineServiceMileage",
                    "laborHourRate",
                    "laborRateCoefficient",
                    "contactPersonIds",
                    "driverPersonIds",
                ]) {
                    assert.match(errors[field], /Правильно:/);
                }
            },
        );

        await context.test(
            "internal make and model directory is paginated and filterable",
            async () => {
                const { default: VehicleReferenceCatalog } =
                    await server.ssrLoadModule(
                        "/src/features/catalogs/VehicleReferenceCatalog.vue",
                    );
                const html = await renderToString(
                    createSSRApp(VehicleReferenceCatalog),
                );

                assert.match(html, /Марка/);
                assert.match(html, /Модель/);
                assert.match(html, /Поколение \/ кузов/);
                assert.match(html, /Фильтр/);
                assert.match(html, /Страница 1 из 1/);
            },
        );

        await context.test(
            "manager catalog import uses one four-step Excel wizard",
            async () => {
                const { default: CatalogImportWizard } =
                    await server.ssrLoadModule(
                        "/src/features/catalogs/CatalogImportWizard.vue",
                    );
                const html = await renderToString(
                    createSSRApp(CatalogImportWizard, {
                        kind: "parts",
                        title: "Справочник запчастей",
                    }),
                );

                assert.match(html, /Импорт из Excel/);
                assert.match(html, /Скачать шаблон XLSX/);
                assert.match(html, /Шаблон/);
                assert.match(html, /Файл/);
                assert.match(html, /Проверка/);
                assert.match(html, /Готово/);
            },
        );

        await context.test(
            "manager user directory separates accounts from organizations",
            async () => {
                const { default: UserEditor } = await server.ssrLoadModule(
                    "/src/features/catalogs/UserCatalogEditor.vue",
                );
                const html = await renderToString(
                    createSSRApp(UserEditor, {
                        record: {
                            id: "user-1",
                            kind: "users",
                            fullName: "Иванов Иван Иванович",
                            lastName: "Иванов",
                            firstName: "Иван",
                            middleName: "Иванович",
                            email: "ivanov@example.test",
                            login: "ivanov",
                            phone: "+7 (999) 111 22 33",
                            organizationId: "org-1",
                            registeredAt: "2026-09-27T00:00:00Z",
                            accessRevokedAt: null,
                            active: true,
                            accessRights: ["requests.view"],
                            permissions: { canEdit: true },
                        },
                        references: {
                            ...references,
                            organizations: [
                                {
                                    id: "org-1",
                                    name: "ООО «Тест»",
                                    kind: "customer",
                                },
                            ],
                            accessRights: [
                                {
                                    value: "requests.view",
                                    label: "Просмотр заявок",
                                },
                            ],
                        },
                        saveRecord: async () => {},
                    }),
                );

                assert.match(html, /Иванов Иван Иванович/);
                assert.match(html, /ООО «Тест»/);
                assert.match(html, /Новый пароль \(если требуется\)/);
                assert.match(html, /Просмотр заявок/);
                assert.match(html, /Доступ разрешён/);
                assert.match(html, /Сохранить и выйти/);
            },
        );

        await context.test(
            "station directory edits required hours and working days",
            async () => {
                const { default: CatalogEditor } = await server.ssrLoadModule(
                    "/src/features/catalogs/CatalogEditor.vue",
                );
                const html = await renderToString(
                    createSSRApp(CatalogEditor, {
                        record: {
                            id: "station-1",
                            kind: "stations",
                            name: "ООО «СТО»",
                            workdayStart: "10:00",
                            workdayEnd: "20:00",
                            workingDays: [1, 2, 3, 4, 5],
                            permissions: { canEdit: true },
                        },
                        records: [],
                        references,
                        saveRecord: async () => {},
                    }),
                );

                assert.match(html, /Время и график работы СТО/);
                assert.match(html, /value="10:00"[^>]*type="time"/);
                assert.match(html, /value="20:00"[^>]*type="time"/);
                assert.match(html, /Пн\./);
                assert.match(html, /Вск\./);
                assert.match(html, /Сохранить и выйти/);
            },
        );

        await context.test(
            "new work returns to the originating request row",
            async () => {
                const { default: App } =
                    await server.ssrLoadModule("/src/App.vue");
                const work = {
                    _clientId: "work-row-1",
                    name: "Пруж дрос",
                    workTypeId: "",
                };
                const requestRecord = { id: "request-1", works: [work] };
                const tab = {
                    id: "record:new-work",
                    recordId: "new-work",
                    record: {
                        _requestWorkTarget: {
                            requestId: requestRecord.id,
                            requestTabId: "request:request-1",
                            rowId: "",
                            clientId: work._clientId,
                            index: 0,
                        },
                    },
                };
                let touched = "";
                const state = {
                    active: tab,
                    activeId: tab.id,
                    tabs: [{ id: "request:request-1" }, tab],
                    store: {
                        get: () => requestRecord,
                        touch: (id) => {
                            touched = id;
                        },
                    },
                    notify: () => {},
                    removeTab(id) {
                        this.tabs = this.tabs.filter((item) => item.id !== id);
                    },
                    activate: () => {},
                };

                App.methods.catalogSaved.call(state, {
                    id: "work-type-1",
                    kind: "workTypes",
                    name: "Пружина сервопривода дроссельной заслонки - с/у",
                });

                assert.equal(work.workTypeId, "work-type-1");
                assert.equal(
                    work.name,
                    "Пружина сервопривода дроссельной заслонки - с/у",
                );
                assert.equal(touched, "request-1");
                assert.equal(
                    state.tabs.some((item) => item.id === tab.id),
                    false,
                );
            },
        );

        await context.test(
            "new catalog part returns to the originating request row without changing scroll",
            async () => {
                const { default: App } =
                    await server.ssrLoadModule("/src/App.vue");
                const part = {
                    _clientId: "part-row-1",
                    name: "Фильтр",
                    catalogPartId: "",
                };
                const requestRecord = {
                    id: "request-1",
                    parts: [part],
                };
                const tab = {
                    id: "record:new-part",
                    recordId: "new-part",
                    record: {
                        _requestPartTarget: {
                            requestId: requestRecord.id,
                            requestTabId: "request:request-1",
                            partId: "",
                            clientId: part._clientId,
                            index: 0,
                            scroll: 790,
                        },
                    },
                };
                const state = {
                    active: tab,
                    activeId: tab.id,
                    tabs: [{ id: "request:request-1" }, tab],
                    store: { get: () => requestRecord, touch: () => {} },
                    notify: () => {},
                    removeTab(id) {
                        this.tabs = this.tabs.filter((item) => item.id !== id);
                    },
                    $refs: { center: { scrollTop: 0 } },
                    activate: async () => {
                        state.$refs.center.scrollTop = 0;
                    },
                };

                App.methods.catalogSaved.call(state, {
                    id: "catalog-part-1",
                    kind: "parts",
                    name: "Фильтр масляный",
                    article: "OF-1",
                    manufacturer: "Test Parts",
                    unit: "шт.",
                });

                await Promise.resolve();
                assert.equal(state.$refs.center.scrollTop, 790);
                assert.equal(part.catalogPartId, "catalog-part-1");
                assert.equal(part.name, "Фильтр масляный");
                assert.equal(part.article, "OF-1");
                assert.equal(part.manufacturer, "Test Parts");
                assert.equal(
                    state.tabs.some((item) => item.id === tab.id),
                    false,
                );
            },
        );

        await context.test(
            "notification click opens its linked request",
            async () => {
                const { default: App } =
                    await server.ssrLoadModule("/src/App.vue");
                const linked = { id: "request-1", number: "ZR-000001" };
                const calls = [];

                await App.methods.openNotification.call(
                    {
                        store: {
                            readNotification: async (id) =>
                                calls.push(["read", id]),
                            get: (id) => (id === linked.id ? linked : null),
                        },
                        openRecord: async (record) =>
                            calls.push(["open", record.id]),
                        notify: (message) => calls.push(["error", message]),
                    },
                    {
                        id: "notification-1",
                        targetType: "request",
                        targetId: linked.id,
                        requestId: linked.id,
                    },
                );

                assert.deepEqual(calls, [
                    ["read", "notification-1"],
                    ["open", "request-1"],
                ]);
            },
        );

        await context.test(
            "save and exit closes a request only after a successful save",
            async () => {
                const { default: App } =
                    await server.ssrLoadModule("/src/App.vue");
                const calls = [];

                await App.methods.saveAndExit.call({
                    activeId: "request:request-1",
                    current: { id: "request-1" },
                    store: {
                        save: async (id) => calls.push(["save", id]),
                    },
                    notify: (message) => calls.push(["notify", message]),
                    removeTab: (id) => calls.push(["close", id]),
                });

                assert.deepEqual(calls, [
                    ["save", "request-1"],
                    ["notify", "Сохранено"],
                    ["close", "request:request-1"],
                ]);
            },
        );

        await context.test(
            "click outside the request chat closes it",
            async () => {
                const { default: App } =
                    await server.ssrLoadModule("/src/App.vue");
                const state = { chatRequestId: "request-1" };

                App.methods.closeChatOutside.call(state, {
                    target: { closest: () => null },
                });

                assert.equal(state.chatRequestId, "");
            },
        );

        await context.test(
            "work directory uses grouped Excel-like time norm rows",
            async () => {
                const { default: CatalogEditor } = await server.ssrLoadModule(
                    "/src/features/catalogs/CatalogEditor.vue",
                );
                const html = await renderToString(
                    createSSRApp(CatalogEditor, {
                        record: {
                            id: "new-work",
                            kind: "workTypes",
                            name: "Замена масла",
                            group: "Техническое обслуживание",
                            isNew: true,
                            permissions: { canEdit: true },
                        },
                        records: [],
                        references,
                        saveRecord: async () => {},
                    }),
                );

                assert.match(html, /Название работы/);
                assert.match(html, /Группа/);
                assert.match(html, /class="excel-grid time-norm-grid"/);
                assert.match(html, /Применимость/);
                assert.match(html, /Марка ТС/);
                assert.match(html, /Модель ТС/);
                assert.match(html, /Конкретное ТС \(VIN\)/);
                assert.match(html, /Поколение/);
                assert.match(html, /Двигатель/);
                assert.match(html, /Трансмиссия/);
                assert.match(html, /Привод/);
                assert.match(html, /Код модификации/);
                assert.match(html, /placeholder="Выберите применимость"/);
                assert.match(html, /aria-label="Удалить норму времени"/);
                assert.match(
                    html,
                    /aria-label="Добавить строку нормы времени"/,
                );

                const row = {
                    id: "norm-1",
                    hours: null,
                };
                const state = {
                    form: { timeNorms: [row] },
                    deletedTimeNormIds: [],
                    blankTimeNorm: CatalogEditor.methods.blankTimeNorm,
                    defaultApplicability: references.normApplicabilities[0],
                };

                CatalogEditor.methods.removeTimeNorm.call(state, 0);
                assert.deepEqual(state.deletedTimeNormIds, ["norm-1"]);
                assert.equal(state.form.timeNorms.length, 1);
                assert.equal(
                    CatalogEditor.methods.timeNormHoursPayload("1.250"),
                    1.25,
                );
                assert.equal(
                    CatalogEditor.methods.timeNormHoursPayload(null),
                    null,
                );
            },
        );

        await context.test(
            "service messages expire after each 15 second display",
            async () => {
                const { default: App } =
                    await server.ssrLoadModule("/src/App.vue");
                const originalSetTimeout = globalThis.setTimeout;
                const originalClearTimeout = globalThis.clearTimeout;
                const timers = new Map();
                let sequence = 0;

                globalThis.setTimeout = (callback, delay) => {
                    const id = ++sequence;
                    timers.set(id, { callback, delay });
                    return id;
                };
                globalThis.clearTimeout = (id) => timers.delete(id);

                try {
                    const message =
                        "API не ответил вовремя. Изменения остались в форме.";
                    const state = {
                        toast: "",
                        fatal: message,
                        toastTimer: null,
                        fatalTimer: null,
                        saveErrorTimer: null,
                        store: { saveError: message },
                    };

                    App.methods.notify.call(state, message);
                    assert.deepEqual(
                        [...timers.values()].map((timer) => timer.delay),
                        [15_000, 15_000, 15_000],
                    );
                    for (const timer of [...timers.values()]) timer.callback();
                    assert.equal(state.toast, "");
                    assert.equal(state.fatal, "");
                    assert.equal(state.store.saveError, "");

                    timers.clear();
                    state.fatal = message;
                    state.store.saveError = message;
                    App.methods.notify.call(state, message);
                    assert.equal(timers.size, 3);
                } finally {
                    globalThis.setTimeout = originalSetTimeout;
                    globalThis.clearTimeout = originalClearTimeout;
                }
            },
        );

        await context.test(
            "background timeout is retried silently and never shown as a form error",
            async () => {
                const { default: App } =
                    await server.ssrLoadModule("/src/App.vue");
                const previousDocument = globalThis.document;
                let notices = 0;
                const state = {
                    liveRefreshBusy: false,
                    user: { id: "user-1" },
                    active: null,
                    fatal: "",
                    store: {
                        busy: false,
                        loading: false,
                        refreshLive: async () => {
                            throw Object.assign(new Error("Фоновый таймаут"), {
                                status: 0,
                            });
                        },
                    },
                    synchronizeRequestLocks: async () => {},
                    pruneDraftSelection: () => {},
                    notify: () => {
                        notices += 1;
                    },
                };

                globalThis.document = { hidden: false };

                try {
                    await App.methods.liveRefresh.call(state);
                    assert.equal(notices, 0);
                    assert.equal(state.fatal, "");
                    assert.equal(state.liveRefreshBusy, false);
                } finally {
                    if (previousDocument === undefined) {
                        delete globalThis.document;
                    } else {
                        globalThis.document = previousDocument;
                    }
                }
            },
        );

        await context.test(
            "document tab acquires a server lock before opening",
            async () => {
                const { default: App } =
                    await server.ssrLoadModule("/src/App.vue");
                let activated = "";
                const calls = [];
                const state = {
                    tabs: [],
                    acquireTabLock: async (tab) => {
                        calls.push(tab.id);
                        return true;
                    },
                    activate(id) {
                        activated = id;
                    },
                };

                await App.methods.openRecord.call(
                    state,
                    reactive({
                        id: "car",
                        vehicle: "Автомобиль",
                        kind: "vehicles",
                    }),
                    "record",
                );

                assert.deepEqual(calls, ["record:car"]);
                assert.equal(state.tabs.length, 1);
                assert.equal(activated, "record:car");
            },
        );

        await context.test(
            "locked catalog record opens in read-only mode",
            async () => {
                const { default: App } =
                    await server.ssrLoadModule("/src/App.vue");
                let activated = "";
                const state = {
                    tabs: [],
                    acquireTabLock: async () => false,
                    activate(id) {
                        activated = id;
                    },
                };

                await App.methods.openRecord.call(
                    state,
                    reactive({
                        id: "car",
                        vehicle: "Автомобиль",
                        kind: "vehicles",
                    }),
                    "record",
                );

                assert.equal(state.tabs[0].lockDenied, true);
                assert.equal(activated, "record:car");
            },
        );

        await context.test(
            "locks only editable records and never the whole catalog",
            async () => {
                const { default: App } =
                    await server.ssrLoadModule("/src/App.vue");
                const state = {
                    requestNeedsLock: App.methods.requestNeedsLock,
                    store: {
                        get: () => ({ permissions: { editableFields: [] } }),
                    },
                };

                assert.equal(
                    App.methods.lockDescriptor.call(state, {
                        id: "list:vehicles",
                        kind: "list",
                        key: "vehicles",
                    }),
                    null,
                );
                assert.equal(
                    App.methods.lockDescriptor.call(state, {
                        id: "request:1",
                        kind: "request",
                        recordId: "1",
                    }),
                    null,
                );
                assert.deepEqual(
                    App.methods.lockDescriptor.call(state, {
                        id: "record:car",
                        kind: "record",
                        recordId: "car",
                        title: "Автомобиль",
                        record: {
                            kind: "vehicles",
                            permissions: { canEdit: true },
                        },
                    }),
                    {
                        type: "catalog_record",
                        key: "vehicles:car",
                        label: "Автомобиль",
                    },
                );
            },
        );

        await context.test(
            "unsaved dialog has save and discard choices",
            async () => {
                const { default: Dialog } = await server.ssrLoadModule(
                    "/src/features/requests/UnsavedChangesDialog.vue",
                );
                const html = await renderToString(
                    createSSRApp(Dialog, {
                        title: "ZR-000001",
                        busy: false,
                    }),
                );

                assert.match(html, /Сохранить и продолжить/);
                assert.match(html, /Не сохранить и продолжить/);
            },
        );

        await context.test("right preview is read-only", async () => {
            const { default: Preview } = await server.ssrLoadModule(
                "/src/features/requests/RequestPreview.vue",
            );
            const html = await renderToString(
                createSSRApp(Preview, {
                    item: request(),
                    kind: "request",
                }),
            );

            assert.match(html, /Только просмотр/);
            assert.doesNotMatch(html, /<(?:input|textarea|select)\b/);
        });
        await context.test(
            "customer final approval exposes icon route actions and recommendation selection",
            async () => {
                const current = {
                    ...request(),
                    stage: 3,
                    works: [
                        {
                            id: "w1",
                            name: "Полное наименование работы",
                            qty: 1,
                            normHours: 1,
                            hourlyRate: 1000,
                            lineAmount: 1000,
                        },
                    ],
                    parts: [
                        {
                            id: "p1",
                            name: "Запчасть",
                            article: "A1",
                            qty: 1,
                            price: 100,
                            status: "approved",
                        },
                    ],
                    permissions: {
                        editableFields: [],
                        canCustomerSlotDecide: true,
                        canDeferRecommendations: true,
                        requiresAction: true,
                    },
                };
                const html = await renderToString(
                    createSSRApp(RepairEditor, { ...props, request: current }),
                );
                assert.match(html, /aria-label="Выбрать работу"/);
                assert.match(
                    html,
                    /aria-label="Выбрать запчасть для рекомендаций"/,
                );
                assert.match(html, /Переместить в Рекомендации/);
                // Заказчик не редактирует работы: удаления и копирования нет.
                assert.doesNotMatch(html, />\s*Создать копию\s*</);
                assert.match(html, /Добавить ЗЧ в рекомендации/);
                assert.match(html, /title="Выбрать иную дату и время"/);
                assert.match(html, /title="Подтвердить финальную заявку"/);
                assert.match(html, /При согласии заявка перейдёт/);
                assert.doesNotMatch(
                    html,
                    /(?:Сохранить позицию|Сохранить запчасть)/,
                );
            },
        );
        await context.test(
            "manager estimate review has explicit approve and return icons",
            async () => {
                const current = {
                    ...request(),
                    stage: 1,
                    reviewContext: "station_changes",
                    estimateVersion: 2,
                    reviewBaselineVersion: 1,
                    permissions: {
                        editableFields: ["works", "parts"],
                        canAdvance: true,
                        canReturn: true,
                        canEditParts: true,
                        requiresAction: true,
                    },
                };
                const html = await renderToString(
                    createSSRApp(RepairEditor, {
                        ...props,
                        user: { role: "manager" },
                        request: current,
                    }),
                );

                assert.match(html, /title="Вернуть в СТО для корректировки"/);
                assert.match(html, /Согласовать/);
                assert.match(html, /aria-label="Добавить строку запчасти"/);
            },
        );
        await context.test(
            "manufacturer empty search displays the full approved directory",
            async () => {
                const { default: Picker } = await server.ssrLoadModule(
                    "/src/components/ReferencePicker.vue",
                );
                const rows = Array.from({ length: 120 }, (_, i) => ({
                    id: String(i),
                    name: "Brand " + i,
                    active: true,
                }));
                const state = {
                    rows,
                    query: "",
                    browseAll: true,
                    maxResults: 100000,
                    valueLabel: (row) => row.name,
                };
                const results = Picker.computed.filtered.call(state);
                assert.equal(results.length, 120);
                assert.equal(results.at(-1).name, "Brand 119");
            },
        );
        await context.test(
            "recommendations transfer only into the request they were opened from",
            async () => {
                const { default: Panel } = await server.ssrLoadModule(
                    "/src/features/requests/RecommendationPanel.vue",
                );
                const requests = [
                    { id: "allowed", number: "ZR-1", vehicleId: "v1", vin: "VIN1", permissions: { canRestoreRecommendations: true } },
                    { id: "wrong-vin", number: "ZR-2", vehicleId: "v2", vin: "VIN2", permissions: { canRestoreRecommendations: true } },
                    { id: "closed", number: "ZR-3", vehicleId: "v1", vin: "VIN1", permissions: { canRestoreRecommendations: false } },
                ];
                const block = (requestId, pending = {}) => {
                    const ctx = { vehicleId: "v1", requests, requestId, pending, document: { vin: "VIN1" } };
                    ctx.sourceRequest = Panel.computed.sourceRequest.call(ctx);
                    return Panel.computed.transferBlock.call(ctx);
                };
                assert.equal(block("allowed"), "");
                assert.match(block("wrong-vin"), /не совпадает/);
                assert.match(block("closed"), /«Черновик» по «В работе»/);
                assert.match(block("allowed", { allowed: true }), /сохраните/);
                assert.equal(block(""), "");
                assert.equal(Panel.computed.canCreateRequest.call({ userRole: "customer" }), true);
                assert.equal(Panel.computed.canCreateRequest.call({ userRole: "manager" }), false);
                assert.equal(Panel.computed.canCreateRequest.call({ userRole: "station" }), false);
                // Удалять строки Рекомендаций могут Заказчик и УК, не СТО.
                assert.equal(Panel.computed.canDelete.call({ userRole: "customer" }), true);
                assert.equal(Panel.computed.canDelete.call({ userRole: "manager" }), true);
                assert.equal(Panel.computed.canDelete.call({ userRole: "station" }), false);
            },
        );

        await context.test(
            "side menu: report item and catalogs as a popup list",
            async () => {
                const { default: SidebarMenu } = await server.ssrLoadModule(
                    "/src/components/SidebarMenu.vue",
                );
                const render = async (role, catalogKeys, active = null, open = false) => {
                    const ctx = {};
                    const component = open
                        ? {
                              ...SidebarMenu,
                              data() {
                                  return { ...SidebarMenu.data.call(this), catalogsOpen: true };
                              },
                          }
                        : SidebarMenu;
                    const html = await renderToString(
                        createSSRApp(component, {
                            user: { role, organization: "Org", label: "User" },
                            panel: { menu: true },
                            catalogKeys,
                            active,
                        }),
                        ctx,
                    );
                    return { html, popup: ctx.teleports?.body || "" };
                };

                const closed = await render("station", ["parts", "suppliers"]);
                assert.match(closed.html, /Отчёт/);
                assert.match(closed.html, /Справочники/);
                assert.match(closed.html, /aria-haspopup="menu"/);
                assert.doesNotMatch(closed.html + closed.popup, /Справочник запчастей/);

                // Активный справочник не разворачивает список внутри меню
                const activeClosed = await render("station", ["parts", "suppliers"], {
                    kind: "list",
                    key: "parts",
                });
                assert.match(activeClosed.html, /class="active nav-item nav-group"/);
                assert.doesNotMatch(activeClosed.html + activeClosed.popup, /Справочник запчастей/);

                // Открытый список рендерится всплывающим окном вне меню
                const opened = await render(
                    "station",
                    ["parts", "suppliers"],
                    { kind: "list", key: "parts" },
                    true,
                );
                assert.doesNotMatch(opened.html, /Справочник запчастей/);
                assert.match(opened.popup, /class="catalog-popup"/);
                assert.match(opened.popup, /role="menu"/);
                assert.match(opened.popup, /Справочник запчастей/);
                assert.match(opened.popup, /Поставщики/);
                assert.match(opened.popup, /class="[^"]*active[^"]*catalog-popup-item|class="catalog-popup-item active/);

                const customer = await render("customer", []);
                assert.match(customer.html, /Отчёт/);
                assert.doesNotMatch(customer.html, /☷ Справочники/);
            },
        );

        await context.test(
            "closing panel: station signs transfer act, manager sees seven documents",
            async () => {
                const { default: ClosingPanel } = await server.ssrLoadModule(
                    "/src/features/requests/ClosingPanel.vue",
                );
                const transferAct = {
                    id: "a1", type: "transfer_act", label: "Акт передачи ТС",
                    number: "APP-000005/2", date: "2026-11-04", amount: 0,
                    driver: { fullName: "Петров Пётр", phone: "+7" },
                    powerOfAttorney: { number: "DV-000005", date: null },
                    driverDocumentsVerified: false,
                };
                const station = await renderToString(
                    createSSRApp(ClosingPanel, {
                        request: {
                            ...request(),
                            stage: 6,
                            attachments: [],
                            closing: { transferAct, documents: [] },
                            permissions: { canSignTransferAct: true, canGenerateDocuments: true },
                        },
                        perform: async () => {},
                    }),
                );
                assert.match(station, /APP-000005\/2/);
                assert.match(station, /DV-000005/);
                assert.match(station, /Загрузить скан акта/);
                assert.match(station, /Подтвердить выдачу ТС/);

                const doc = (type, side, label, amount) => ({
                    id: type, type, side, label, number: `X-${type}`,
                    date: "2026-11-04", seller: "S", buyer: "B", amount, vatAmount: 0,
                });
                const documents = [
                    doc("station_work_order", "station", "Заказ-наряд", 1350),
                    doc("station_upd", "station", "УПД", 1350),
                    doc("station_invoice", "station", "Счёт на оплату", 1350),
                    doc("customer_work_order", "customer", "Заказ-наряд", 1500),
                    doc("customer_upd", "customer", "УПД", 1500),
                    doc("customer_invoice", "customer", "Счёт на оплату", 1500),
                    doc("agent_report", "customer", "Отчёт агента", 1500),
                ];
                const manager = await renderToString(
                    createSSRApp(ClosingPanel, {
                        request: {
                            ...request(),
                            stage: 8,
                            attachments: [],
                            closing: { transferAct: { ...transferAct, driverDocumentsVerified: true }, documents },
                            permissions: { canMarkCustomerPayment: true },
                        },
                        perform: async () => {},
                    }),
                );
                assert.equal((manager.match(/>PDF</g) || []).length, 8);
                assert.match(manager, /СТО → УК/);
                assert.match(manager, /Отчёт агента/);
                assert.match(manager, /Оплата Заказчика по счёту X-customer_invoice/);
                assert.doesNotMatch(manager, /Загрузить скан акта/);
            },
        );

        await context.test(
            "report: columns per cabinet, left to right",
            async () => {
                const { default: RequestReport } = await server.ssrLoadModule(
                    "/src/features/reports/RequestReport.vue",
                );
                const headers = async (role) => {
                    const html = await renderToString(
                        createSSRApp(RequestReport, { user: { role } }),
                    );
                    const head = html.slice(html.indexOf("<thead"), html.indexOf("</thead>"));
                    return [...head.matchAll(/<th[^>]*>([^<]*)<\/th>/g)].map((m) => m[1]);
                };
                assert.deepEqual(await headers("customer"), [
                    "№ ЗР", "ТС", "Передано на СТО", "Выдано с СТО", "Сумма, ₽",
                ]);
                assert.deepEqual(await headers("manager"), [
                    "№ ЗР", "Заказчик", "СТО", "ТС", "Передано на СТО", "Выдано с СТО",
                    "Сумма ЗР (Заказчик), ₽", "Оплата Заказчика", "Сумма ЗР (СТО), ₽",
                    "Оплата СТО", "Сумма Агентского, ₽", "Этап",
                ]);
                assert.deepEqual(await headers("station"), [
                    "№ ЗР", "ТС", "Передано на СТО", "Выдано с СТО",
                    "Сумма ЗР (Заказчик), ₽", "Оплата Заказчика", "Сумма ЗР (СТО), ₽",
                    "Оплата СТО", "Сумма Агентского, ₽", "Этап",
                ]);
            },
        );

        await context.test(
            "side menu: EDO documents right after recommendations, not in the top bar",
            async () => {
                const { default: SidebarMenu } = await server.ssrLoadModule(
                    "/src/components/SidebarMenu.vue",
                );
                const { default: TopBar } = await server.ssrLoadModule(
                    "/src/components/TopBar.vue",
                );
                const menu = await renderToString(
                    createSSRApp(SidebarMenu, {
                        user: { role: "customer", organization: "Org", label: "User" },
                        panel: { menu: true },
                        active: { kind: "edo" },
                    }),
                );
                const rec = menu.indexOf("Рекомендации");
                const edo = menu.indexOf("Документы ЭДО");
                const awaiting = menu.indexOf("Ожидают действия");
                assert.ok(rec > -1 && edo > rec && awaiting > edo);
                assert.match(menu, /class="active nav-item"[^>]*>\s*⇄ Документы ЭДО/);
                const top = await renderToString(
                    createSSRApp(TopBar, { user: { login: "uk", label: "УК" } }),
                );
                assert.doesNotMatch(top, /Документы ЭДО/);
            },
        );
    } finally {
        await server.close();
    }
});
