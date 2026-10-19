import test from "node:test";
import assert from "node:assert/strict";
import { createSSRApp } from "vue";
import { renderToString } from "vue/server-renderer";
import { createServer } from "vite";
import { stripClientMetadata } from "../src/stores/requestStore.js";
const root = new URL("..", import.meta.url).pathname;

test("0.37.1 supplier, estimate and recommendation behavior", async (t) => {
    const server = await createServer({
        root,
        server: { middlewareMode: true, hmr: false },
        optimizeDeps: { noDiscovery: true, include: [] },
    });
    try {
        const { default: Supplier } = await server.ssrLoadModule(
            "/src/features/catalogs/SupplierEditor.vue",
        );
        const { default: Recommendations } = await server.ssrLoadModule(
            "/src/features/requests/RecommendationPanel.vue",
        );
        const { default: VehicleDialog } = await server.ssrLoadModule(
            "/src/features/catalogs/VehicleModelCreateDialog.vue",
        );
        const { default: Parts } = await server.ssrLoadModule(
            "/src/features/requests/PartsPanel.vue",
        );
        const { default: Repair } = await server.ssrLoadModule(
            "/src/features/requests/RepairEditor.vue",
        );
        const { default: App } = await server.ssrLoadModule("/src/App.vue");
        const { requestFields } = await server.ssrLoadModule(
            "/src/data/permissions.js",
        );
        const request = {
            ...Object.fromEntries(requestFields.map((key) => [key, ""])),
            id: "r",
            number: "RP-000001",
            stage: 0,
            revision: 0,
            works: [],
            parts: [],
            attachments: [],
            history: [],
            diagnosticReports: [],
            statusPeriods: [],
            approvals: [],
            permissions: { editableFields: [], canDeferRecommendations: false },
            workOrder: null,
            invoice: null,
        };
        const references = {
            reasons: [],
            workTypes: [],
            timeNorms: [],
            parts: [],
            suppliers: [],
            partUnits: ["шт."],
        };
        const props = {
            request,
            user: { role: "customer" },
            records: [],
            references,
            busy: false,
            perform: async () => {},
        };
        await t.test(
            "supplier form chooses legal form before displaying requisites",
            async () => {
                const html = await renderToString(
                    createSSRApp(Supplier, {
                        record: { isNew: true, permissions: { canEdit: true } },
                        references: {
                            supplierLegalForms: [
                                { id: "ooo", name: "ООО", individual: false },
                                { id: "ip", name: "ИП", individual: true },
                            ],
                        },
                        saveRecord: async () => {},
                    }),
                );
                assert.match(html, /Организационно-правовая форма/);
                assert.doesNotMatch(html, /Расчётный счёт/);
            },
        );
        await t.test(
            "supplier company and individual show their own requisites",
            () => {
                const company = Supplier.computed.fields.call({
                    individual: false,
                    form: { legalForm: "ooo" },
                });
                const individual = Supplier.computed.fields.call({
                    individual: true,
                    form: { legalForm: "ip" },
                });
                assert.equal(company.kpp, "КПП");
                assert.equal(company.ogrn, "ОГРН");
                assert.equal(individual.ogrn, "ОГРНИП");
                assert.ok(individual.lastName);
                assert.equal(individual.kpp, undefined);
            },
        );
        await t.test(
            "station has supplier directory and can create records there",
            () => {
                assert.ok(
                    App.computed.catalogKeys
                        .call({ user: { role: "station" } })
                        .includes("suppliers"),
                );
                assert.equal(
                    App.computed.canCreateCatalog.call({
                        user: { role: "station" },
                        active: { key: "suppliers" },
                    }),
                    true,
                );
            },
        );
        await t.test(
            "customer cannot see repair block before first approval",
            async () => {
                const html = await renderToString(createSSRApp(Repair, props));
                assert.doesNotMatch(html, /class="repair-calculation-section/);
                assert.doesNotMatch(html, /<h3>Заключение диагностики СТО/);
            },
        );
        await t.test(
            "previously approved estimate remains visible after return to UK",
            async () => {
                const html = await renderToString(
                    createSSRApp(Repair, {
                        ...props,
                        request: {
                            ...request,
                            stage: 1,
                            permissions: {
                                editableFields: [],
                                canViewRepairEstimate: true,
                            },
                        },
                    }),
                );
                assert.match(html, /class="repair-calculation-section/);
            },
        );
        await t.test(
            "customer part row excludes supplier and purchase cost",
            async () => {
                const html = await renderToString(
                    createSSRApp(Parts, {
                        request: {
                            ...request,
                            parts: [
                                {
                                    id: "p",
                                    name: "Фильтр",
                                    article: "A1",
                                    unit: "шт.",
                                    qty: 1,
                                    price: 200,
                                    purchasePrice: 100,
                                    supplierId: "s",
                                },
                            ],
                        },
                        suppliers: [{ id: "s", name: "Секретный поставщик" }],
                        perform: async () => {},
                        customerView: true,
                    }),
                );
                assert.doesNotMatch(
                    html,
                    /Секретный поставщик|>Поставщик<|Цена закупа/,
                );
                assert.match(html, /Фильтр/);
            },
        );
        await t.test(
            "separate recommendation buttons and explicit checkboxes",
            async () => {
                const html = await renderToString(
                    createSSRApp(Repair, {
                        ...props,
                        request: {
                            ...request,
                            stage: 3,
                            works: [{ id: "w", name: "Работа", qty: 1 }],
                            parts: [
                                {
                                    id: "p",
                                    name: "ЗЧ",
                                    qty: 1,
                                    price: 20,
                                    status: "proposed",
                                },
                            ],
                            permissions: {
                                editableFields: [],
                                canDeferRecommendations: true,
                            },
                        },
                    }),
                );
                assert.match(html, /Добавить работы в рекомендации/);
                assert.match(html, /Добавить ЗЧ в рекомендации/);
                assert.equal(
                    (html.match(/class="selection-checkbox"/g) || []).length,
                    2,
                );
            },
        );
        await t.test(
            "recommendations aggregate all source documents into work and part groups",
            () => {
                const documents = [
                    {
                        id: "a",
                        revision: 0,
                        items: [
                            {
                                id: "w",
                                kind: "work",
                                createdAt: "2026-10-15T10:00:00Z",
                            },
                        ],
                    },
                    {
                        id: "b",
                        revision: 0,
                        items: [
                            {
                                id: "p",
                                kind: "part",
                                createdAt: "2026-10-15T11:00:00Z",
                            },
                        ],
                    },
                ];
                const items = Recommendations.computed.items.call({
                    documents,
                });
                const groups = Recommendations.computed.groups.call({ items });
                assert.deepEqual(
                    groups.map((g) => [g.title, g.items.map((i) => i.id)]),
                    [
                        ["Работы", ["w"]],
                        ["ЗЧ", ["p"]],
                    ],
                );
            },
        );
        await t.test(
            "recommendation header includes make model plate and VIN without document selector",
            async () => {
                const docs = [
                    {
                        id: "a",
                        number: "REC-000002",
                        revision: 0,
                        make: "Toyota",
                        model: "Camry",
                        plate: "А111АА78",
                        vin: "VIN123",
                        items: [],
                    },
                ];
                const Component = {
                    ...Recommendations,
                    data() {
                        return {
                            ...Recommendations.data.call(this),
                            documents: docs,
                        };
                    },
                };
                const html = await renderToString(
                    createSSRApp(Component, { vehicleId: "v" }),
                );
                assert.match(html, /Toyota · Camry · А111АА78/);
                assert.match(html, /VIN123/);
                assert.doesNotMatch(html, /REC-000002/);
            },
        );
        await t.test(
            "free vehicle body input can create a nonexisting variant",
            async () => {
                let payload;
                const context = {
                    form: {
                        make: "Toyota",
                        model: "Camry",
                        bodyCode: "AXV70A",
                        modelYearFrom: 2024,
                        modelYearTo: 2026,
                        engineType: "Бензин",
                        engineIndex: "A25A",
                        horsepower: "199,5",
                        engineVolumeLiters: "2,5",
                        transmission: "АКПП",
                        driveType: "передний",
                    },
                    createModel: async (data) => {
                        payload = data;
                        return data;
                    },
                    $emit() {},
                };
                await VehicleDialog.methods.submit.call(context);
                assert.equal(payload.bodyCode, "AXV70A");
                assert.equal(payload.horsepower, 199.5);
                assert.equal(payload.engineVolumeLiters, 2.5);
            },
        );
        await t.test(
            "vehicle creation contains all fields in one form",
            async () => {
                const html = await renderToString(
                    createSSRApp(VehicleDialog, {
                        createModel: async () => {},
                    }),
                );
                assert.match(html, /Тип двигателя/);
                assert.match(html, /Тип привода/);
                assert.match(html, /Год выпуска с/);
                assert.doesNotMatch(html, /Шаг \d|Далее/);
            },
        );
        await t.test(
            "display-only norm description is excluded from request payload",
            () => {
                assert.deepEqual(
                    stripClientMetadata({
                        normApplicabilityLabel: "Применимость общая",
                        normHours: 1,
                        _clientId: "x",
                    }),
                    { normHours: 1 },
                );
            },
        );
    } finally {
        await server.close();
    }
});
