const VIN_PATTERN = /^[A-HJ-NPR-Z0-9]{17}$/;
const PLATE_PATTERN = /^[\p{L}\p{N} -]*$/u;

function filled(value) {
    return value !== null && value !== undefined && value !== "";
}

function validInteger(value, minimum, maximum) {
    if (!filled(value)) return true;

    const number = Number(value);

    return Number.isInteger(number)
        && number >= minimum
        && number <= maximum;
}

function validDecimal(value, minimum, maximum, scale) {
    if (!filled(value)) return true;

    const number = Number(value);

    if (!Number.isFinite(number) || number < minimum || number > maximum) {
        return false;
    }

    return Math.abs(number - Number(number.toFixed(scale))) < 1e-9;
}

function validPeople(selected, allowed) {
    if (!Array.isArray(selected)) return false;

    const ids = selected.filter(Boolean);

    return new Set(ids).size === ids.length
        && ids.every((id) => allowed.has(id));
}

/**
 * Проверяет форму ТС до отправки в API и возвращает понятные подсказки.
 *
 * @param {object} form
 * @param {object} context
 * @returns {Record<string, string>}
 */
export function validateVehicleForm(form, context = {}) {
    const errors = {};
    const make = String(form.make || "").trim();
    const model = String(form.model || "").trim();
    const vin = String(form.vin || "").trim().toUpperCase();
    const plate = String(form.plate || "").trim();

    if (context.isManager && !form.organizationId) {
        errors.organizationId =
            "Организация не выбрана. Правильно: выберите заказчика из выпадающего списка.";
    }

    if (
        !make
        || !model
        || make.length > 255
        || model.length > 255
        || (!form.vehicleModelId && (context.isNew || context.referenceChanged))
    ) {
        errors.vehicleReference =
            "Марка или модель выбрана неверно. Правильно: выберите оба значения из выпадающих списков либо создайте новую марку и модель.";
    }

    if (!VIN_PATTERN.test(vin)) {
        errors.vin =
            "VIN введён неверно. Правильно: ровно 17 латинских букв и цифр без I, O и Q, например X96330200R0000001.";
    }

    if (plate.length > 30 || !PLATE_PATTERN.test(plate)) {
        errors.plate =
            "Госномер введён неверно. Правильно: до 30 букв и цифр; допустимы пробел и дефис, например А111АА78.";
    }

    if (!validInteger(form.year, 1900, 2100)) {
        errors.year =
            "Год выпуска введён неверно. Правильно: целое число от 1900 до 2100, например 2025.";
    }

    if (!validInteger(form.baselineServiceMileage, 0, 999999999)) {
        errors.baselineServiceMileage =
            "Предыдущий пробег введён неверно. Правильно: целое число от 0 до 999999999, например 200.";
    }

    if (
        context.isManager
        && !validDecimal(form.laborHourRate, 0, 1000000000, 2)
    ) {
        errors.laborHourRate =
            "Стоимость нормо/часа введена неверно. Правильно: число от 0 до 1000000000, не более двух знаков после запятой, например 2500,00.";
    }

    if (context.isManager) {
        for (const key of ["laborHourRateOfficial", "laborHourRateOther"]) {
            if (!validDecimal(form[key], 0, 1000000000, 2)) {
                errors[key] = "Стоимость нормо/часа: число от 0 до 1000000000, не более двух знаков после запятой.";
            }
        }
    }

    if (
        context.isManager
        && !validDecimal(form.laborRateCoefficient, 0.001, 100, 3)
    ) {
        errors.laborRateCoefficient =
            "Поправочный коэффициент введён неверно. Правильно: число от 0,001 до 100, не более трёх знаков после запятой, например 1,000.";
    }

    if (!validPeople(
        form.contactPersonIds,
        new Set(context.contactIds || []),
    )) {
        errors.contactPersonIds =
            "Контактное лицо выбрано неверно. Правильно: выберите каждое лицо один раз из справочника этой организации.";
    }

    if (!validPeople(
        form.driverPersonIds,
        new Set(context.driverIds || []),
    )) {
        errors.driverPersonIds =
            "Водитель выбран неверно. Правильно: выберите каждого водителя один раз из справочника этой организации.";
    }

    return errors;
}

export function apiVehicleFieldErrors(error) {
    const fields = error?.details?.error?.fields;

    if (!fields || typeof fields !== "object") {
        return {};
    }

    const aliases = {
        make: "vehicleReference",
        model: "vehicleReference",
        vehicleModelId: "vehicleReference",
        organizationId: "organizationId",
        vin: "vin",
        plate: "plate",
        year: "year",
        baselineServiceMileage: "baselineServiceMileage",
        laborHourRate: "laborHourRate",
        laborRateCoefficient: "laborRateCoefficient",
        contactPersonIds: "contactPersonIds",
        driverPersonIds: "driverPersonIds",
    };

    return Object.fromEntries(
        Object.entries(fields).map(([key, message]) => [
            aliases[key.split(".")[0]] || key.split(".")[0],
            String(message),
        ]),
    );
}
