import test from "node:test";
import assert from "node:assert/strict";
import { createServer } from "vite";
import { marketplaceApi } from "../src/api/marketplace.js";

const root = new URL("..", import.meta.url).pathname;

test("sequential vehicle selection resets dependent values and resolves the final variant", async () => {
    const server = await createServer({
        root,
        server: { middlewareMode: true },
        appType: "custom",
    });
    try {
        const component = (
            await server.ssrLoadModule("/src/features/catalogs/SequentialVehiclePicker.vue")
        ).default;
        const emitted = [];
        const state = {
            ...component.data(),
            $emit: (...args) => emitted.push(args),
            make: { id: "make", name: "Chery" },
            filters: {
                model: "Tiggo",
                generation: "old",
                engineType: "Бензин",
                engineIndex: "old",
            },
            options: { model: [], generation: [], engineType: [], engineIndex: [] },
        };
        Object.assign(
            state,
            Object.fromEntries(
                Object.entries(component.methods).map(([key, fn]) => [key, fn.bind(state)]),
            ),
        );
        const loaded = [];
        state.load = async (key) => {
            loaded.push(key);
            state.options[key] = [];
        };
        await state.choose("model");
        assert.deepEqual(state.filters, { model: "Tiggo" });
        assert.deepEqual(loaded, ["generation"]);
        assert.equal(emitted.at(-1)[1].vehicleModelId, "");
        state.filters.driveType = "передний";
        state.resolve = async () => state.$emit("change", { vehicleModelId: "variant" });
        await state.choose("driveType");
        assert.equal(emitted.at(-1)[1].vehicleModelId, "variant");
    } finally {
        await server.close();
    }
});

test("all tariffs validate independently and stay manager-only", async () => {
    const { validateVehicleForm } = await import("../src/features/catalogs/vehicleValidation.js");
    const form = {
        make: "Chery",
        model: "Tiggo",
        vehicleModelId: "id",
        vin: "LVTDB21B5MD123456",
        plate: "А111АА78",
        organizationId: "org",
        contactPersonIds: [],
        driverPersonIds: [],
        laborRateCoefficient: 1,
        laborHourRateOfficial: -1,
        laborHourRateOther: 1.234,
    };
    const errors = validateVehicleForm(form, { isManager: true });
    assert.ok(errors.laborHourRateOfficial);
    assert.ok(errors.laborHourRateOther);
    const customerErrors = validateVehicleForm(form, { isManager: false });
    assert.equal(customerErrors.laborHourRateOfficial, undefined);
});

test("vehicle fields retain selected labels and invalidate dependent selections when typing", async () => {
    const server = await createServer({ root, server: { middlewareMode: true }, appType: "custom" });
    try {
        const component = (await server.ssrLoadModule("/src/features/catalogs/SequentialVehiclePicker.vue")).default;
        const state = {
            ...component.data(),
            make: { id: "chery", name: "Chery" },
            filters: { model: "Tiggo", generation: "generation" },
            options: { model: [{ value: "Tiggo", label: "Tiggo" }], generation: [] },
            $emit() {},
        };
        for (const [name, method] of Object.entries(component.methods)) state[name] = method.bind(state);
        state.choose = async () => {};
        await state.selectStep("model", { id: "Tiggo", name: "Tiggo" });
        assert.equal(state.stepLabel("model"), "Tiggo");
        state.clearStep("model", "");
        assert.equal(state.filters.model, undefined);
        assert.equal(state.filters.generation, undefined);
        assert.equal(state.options.generation, undefined);
        assert.equal(state.stepOptions("model")[0].name, "Tiggo");

        const picker = (await server.ssrLoadModule("/src/components/ReferencePicker.vue")).default;
        let opened = false;
        await picker.methods.show.call({ disabled: false, openOnInputOnly: true, set open(value) { opened = value; } });
        assert.equal(opened, false);
        const selected = [];
        const field = { query: "Ch", open: true, $emit: (...args) => selected.push(args) };
        picker.methods.choose.call(field, { id: "chery", name: "Chery" });
        assert.equal(field.query, "Chery");
        assert.equal(field.open, false);
        assert.deepEqual(selected[0], ["update:modelValue", "chery"]);
    } finally {
        await server.close();
    }
});

test("vehicle reference search matches all fragments anywhere in a label", async () => {
    const server = await createServer({ root, server: { middlewareMode: true }, appType: "custom" });
    try {
        const picker = (await server.ssrLoadModule("/src/components/ReferencePicker.vue")).default;
        const rows = [
            { id: "one", name: "Toyota Land Cruiser Prado" },
            { id: "two", name: "Toyota Corolla" },
            { id: "inactive", name: "Toyota Land Cruiser", active: false },
        ];
        const find = (query, searchMode = "fragments") => picker.computed.filtered.call({ rows, query, searchMode, loadOptions: null }).map((item) => item.id);
        assert.deepEqual(find("cruis"), ["one"]);
        assert.deepEqual(find("CRUIS land"), ["one"]);
        assert.deepEqual(find("  prAD   cruis  toy  "), ["one"]);
        assert.deepEqual(find("cruis cor"), []);
        assert.deepEqual(find("cruis", "prefix"), ["one"]);
        assert.deepEqual(find("toy", "prefix"), ["one", "two"]);
        assert.deepEqual(find(""), ["one", "two"]);
    } finally {
        await server.close();
    }
});

test("small dependent reference lists open on focus; large lists require typing", async () => {
    const server = await createServer({ root, server: { middlewareMode: true }, appType: "custom" });
    try {
        const picker = (await server.ssrLoadModule("/src/components/ReferencePicker.vue")).default;
        const focus = async (count) => {
            const state = {
                disabled: false, openOnInputOnly: true, showAllOnFocusLimit: 15,
                rows: Array.from({ length: count }, (_, id) => ({ id, name: `Value ${id}` })),
                open: false, query: "selected", searchMode: "fragments", loadOptions: null,
                $nextTick: async () => {}, positionOptions() {}, load: async () => {},
            };
            await picker.methods.show.call(state);
            return state;
        };
        const small = await focus(15);
        assert.equal(small.open, true);
        assert.equal(picker.computed.filtered.call(small).length, 15);
        assert.equal((await focus(16)).open, false);
        const vehicle = (await server.ssrLoadModule("/src/features/catalogs/SequentialVehiclePicker.vue")).default;
        for (const key of ["engineType", "driveType", "transmission"]) assert.equal(vehicle.methods.isClosedList(key), true);
        assert.equal(vehicle.methods.isClosedList("generation"), false);
        const work = (await server.ssrLoadModule("/src/features/requests/WorkTypePicker.vue")).default;
        const state = {
            options: [{ id: "one", name: "Масляный фильтр", active: true }],
            edited: false, disabled: false, query: "", modelValue: "", browseAll: false,
            $nextTick() {}, updateDropdownPosition() {}, normalize: work.methods.normalize,
        };
        work.methods.selectCurrentText.call(state, { target: { select() {} } });
        assert.equal(work.computed.showResults.call(state), true);
        assert.deepEqual(work.computed.results.call(state).map((item) => item.id), ["one"]);
    } finally {
        await server.close();
    }
});

test("time norm model picker requests unique names without changing vehicle variant searches", async () => {
    const server = await createServer({ root, server: { middlewareMode: true }, appType: "custom" });
    try {
        const api = (await server.ssrLoadModule("/src/api/marketplace.js")).marketplaceApi;
        const original = api.vehicleModels;
        const calls = [];
        api.vehicleModels = async (...args) => { calls.push(args); return [{ id: "camry", model: "Camry" }]; };
        try {
            const editor = (await server.ssrLoadModule("/src/features/catalogs/CatalogEditor.vue")).default;
            const result = await editor.methods.loadVehicleModels.call({}, { vehicleMakeId: "toyota" }, "Cam");
            assert.deepEqual(calls, [["toyota", "Cam", true]]);
            assert.equal(result[0].name, "Camry");
        } finally { api.vehicleModels = original; }
    } finally { await server.close(); }
});
