/**
 * Применяет выбранный автомобиль к редактируемому состоянию заявки.
 * Производные поля сохраняются только в памяти до ответа API.
 *
 * @param {object} request
 * @param {object} vehicle
 */
export function applyVehicleSelection(request, vehicle) {
    Object.assign(request, {
        vehicleId: vehicle.id,
        vehicle: vehicle.vehicle,
        make: vehicle.make || "",
        model: vehicle.model || "",
        vehicleModelId: vehicle.vehicleModelId || "",
        vin: vehicle.vin,
        plate: vehicle.plate,
        year: vehicle.year,
        company: vehicle.company,
        previousServiceMileage: vehicle.previousServiceMileage,
        previousServiceAt: vehicle.previousServiceAt,
        contactId: "",
        contact: "",
        driverId: "",
        driver: "",
    });
}

/**
 * Очищает зависимый подвид, только если он доступен текущей роли.
 *
 * @param {object} request
 * @param {boolean} canEditSubtype
 */
export function resetReasonSubtype(request, canEditSubtype) {
    if (canEditSubtype) {
        request.reasonSubtypeId = "";
    }
}
