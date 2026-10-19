/**
 * Локализация, форматирование и справочные поля UI.
 */

/* ─── Роли ─── */

/** @type {Record<string, string>} */
export const roleLabels = {
    customer: "Заказчик",
    station: "СТО-партнёр",
    manager: "Управляющая компания",
};

/* ─── Редактируемые поля заявки ─── */

/** @type {string[]} */
export const requestFields = [
    "vehicleId",
    "mileage",
    "contactId",
    "driverId",
    "description",
    "slot",
    "stationId",
    "reasonId",
    "reasonSubtypeId",
    "routeComment",
    "works",
    "parts",
    "laborRateType",
];

/* ─── Форматирование ─── */

const SECONDS_PER_DAY = 86_400;
const SECONDS_PER_HOUR = 3_600;
const SECONDS_PER_MINUTE = 60;

/** @type {Intl.NumberFormatOptions} */
const MONEY_FORMAT_OPTIONS = {
    maximumFractionDigits: 2,
};

/** @type {Intl.DateTimeFormatOptions} */
const DATE_TIME_FORMAT_OPTIONS = {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
};

/**
 * Форматирует число как денежную сумму в русской локали.
 *
 * @param {number|string|null|undefined} value
 * @returns {string}
 */
export function money(value) {
    const number = Number(value ?? 0);

    if (!Number.isFinite(number)) {
        return "0";
    }

    return number.toLocaleString("ru-RU", MONEY_FORMAT_OPTIONS);
}

/**
 * Считает итоговую сумму заявки: работы + запчасти.
 *
 * @param {object} request
 * @param {Array<{qty: number|string, price: number|string}>} [request.works]
 * @param {Array<{qty: number|string, price: number|string, returnedQty: number|string}>} [request.parts]
 * @returns {number}
 */
export function total(request) {
    const worksTotal = (request?.works || []).reduce(
        (sum, work) => sum + Number(work.qty || 0) * Number(work.price || 0),
        0,
    );

    const partsTotal = (request?.parts || []).reduce(
        (sum, part) =>
            sum +
            (Number(part.qty) - Number(part.returnedQty)) * Number(part.price),
        0,
    );

    return worksTotal + partsTotal;
}

/**
 * Форматирует ISO-дату как `дд.мм.гггг чч:мм` в русской локали.
 *
 * @param {string|null|undefined} value
 * @returns {string}
 */
export function formatDateTime(value) {
    if (!value) {
        return "—";
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return "—";
    }

    return date.toLocaleString("ru-RU", DATE_TIME_FORMAT_OPTIONS);
}

/**
 * Форматирует длительность в формате `N д N ч N мин`.
 *
 * @param {number|null|undefined} seconds
 * @returns {string}
 */
export function duration(seconds) {
    const total = Math.max(0, Math.floor(seconds || 0));

    const days = Math.floor(total / SECONDS_PER_DAY);
    const hours = Math.floor((total % SECONDS_PER_DAY) / SECONDS_PER_HOUR);
    const minutes = Math.floor((total % SECONDS_PER_HOUR) / SECONDS_PER_MINUTE);

    return `${days} д ${hours} ч ${minutes} мин`;
}

/* ─── Справочники: названия и колонки ─── */

/** @type {Record<string, string>} */
export const catalogNames = {
    vehicles: "Справочник ТС Заказчиков",
    vehicleModels: "Справочник ТС (марки, модели)",
    contactPersons: "Контактные лица",
    drivers: "Водители",
    stations: "Справочник СТО",
    customers: "Справочник Заказчики",
    managers: "Справочник УК",
    users: "Справочник Пользователи",
    vehicleApprovals: "Согласование изменений ТС",
    changeRegistry: "Реестр изменений",
    reasons: "Причины обращения",
    workTypes: "Справочник Работ",
    normApplicabilities: "Применимости норм",
    timeNorms: "Нормы времени",
    regulations: "Регламенты ТО",
    suppliers: "Поставщики",
    parts: "Справочник запчастей",
    partManufacturers: "Производитель ЗЧ",
};

/** @type {Record<string, Record<string, string>>} */
export const catalogFields = {
    vehicles: {
        make: "Марка",
        model: "Модель",
        vin: "VIN",
        plate: "Госномер",
        year: "Год выпуска",
        contactNames: "Контактные лица",
        driverNames: "Водители",
        previousServiceMileage: "Предыдущий пробег",
        company: "Наименование заказчика",
        modelYearFrom: "Год выпуска с",
        modelYearTo: "Год выпуска до",
        bodyCode: "Номер кузова",
        engineIndex: "Индекс двигателя",
        horsepower: "Мощность, л.с.",
        engineVolumeLiters: "Объём двигателя, л",
        transmission: "Вид трансмиссии",
        driveType: "Тип привода",
        engineType: "Тип двигателя",
        laborHourRate: "Общий нормо/час, ₽",
        laborHourRateOfficial: "Официальный нормо/час, ₽",
        laborHourRateOther: "Прочий нормо/час, ₽",
    },
    vehicleModels: {
        make: "Марка",
        model: "Модель",
        bodyCode: "Поколение / кузов",
        modelYearFrom: "Модельный год от",
        modelYearTo: "Модельный год до",
        engineIndex: "Индекс двигателя",
        horsepower: "Мощность, л.с.",
        engineVolumeLiters: "Объём двигателя, л",
        transmission: "Вид трансмиссии",
        driveType: "Тип привода",
        engineType: "Тип двигателя",
    },
    contactPersons: {
        lastName: "Фамилия",
        firstName: "Имя",
        middleName: "Отчество",
        company: "Организация",
        phone: "Сотовый телефон",
    },
    drivers: {
        lastName: "Фамилия",
        firstName: "Имя",
        middleName: "Отчество",
        company: "Организация",
        phone: "Сотовый телефон",
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
        rating: "Рейтинг",
        mechanicalBays: "Механические посты",
        lifts: "Подъёмники",
        mechanicsCount: "Механики",
        electriciansCount: "Электрики",
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
        brakeDiscSkimming: "Проточка дисков",
        wheelAlignment: "Сход-развал",
        tireService: "Шиномонтаж",
        tireStorage: "Хранение шин",
        evacuation: "Эвакуация",
        warrantyService: "Гарантийное обслуживание",
        reception: "Зона приёмки",
        waitingArea: "Зона ожидания",
        photoVideoFixation: "Фото- и видеофиксация",
        itAccounting: "ИТ-учёт",
        insuranceFleetExperience: "Опыт со страховыми/автопарками",
        trainingCertificates: "Сертификаты обучения",
        passengerCars: "Легковые ТС",
        lcv: "Лёгкий коммерческий транспорт",
        trucks: "Грузовые ТС",
        workdayStart: "Начало работы",
        workdayEnd: "Окончание работы",
        workingDays: "Рабочие дни",
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
    users: {
        lastName: "Фамилия",
        firstName: "Имя",
        middleName: "Отчество",
        email: "Электронная почта",
        login: "Логин",
        password: "Пароль",
        phone: "Мобильный телефон",
        organization: "Принадлежность к организации",
        organizationType: "Тип организации",
        registeredAt: "Дата первичной регистрации",
        accessRevokedAt: "Дата отзыва доступа",
        active: "Активен",
        hasAccess: "Доступ разрешён",
        accessRights: "Права доступа",
    },
    reasons: {
        name: "Причина / подвид",
        code: "Код",
        parentId: "Основная причина",
    },
    workTypes: {
        name: "Наименование работ",
        group: "Группа",
        laborHourRate: "Стоимость нормо-часа, ₽",
        isMaintenance: "ТО",
        active: "Активна",
    },
    normApplicabilities: {
        name: "Применимость",
        active: "Активна",
    },
    timeNorms: {
        workTypeId: "Вид работы",
        applicability: "Применимость",
        vehicleMake: "Марка ТС",
        vehicleModel: "Модель ТС",
        hours: "Норма, ч",
        source: "Источник",
    },
    regulations: {
        name: "Регламент",
        vehicleModel: "Модель",
        intervalKm: "Интервал, км",
        intervalMonths: "Интервал, мес",
    },
    suppliers: {
        name: "Поставщик",
        legalForm: "Правовая форма",
        contact: "Контакт",
        taxId: "ИНН",
    },
    partManufacturers: {
        name: "Производитель",
        active: "Активен",
        status: "Статус",
        proposedName: "Предложенное название",
    },
    parts: {
        name: "Запчасть",
        article: "Артикул",
        manufacturer: "Производитель",
        createdByOrganization: "Кто добавил",
        supplierId: "Поставщик",
        purchasePrice: "Цена закупа, ₽",
        salePrice: "Цена реализации, ₽",
        unit: "Единица",
        active: "Активна",
    },
    vehicleApprovals: {
        action: "Изменение",
        company: "Заказчик",
        vehicle: "Марка и модель",
        vin: "VIN",
        plate: "Госномер",
        requestedBy: "Пользователь",
        createdAt: "Дата",
    },
    changeRegistry: {
        scope: "Раздел",
        record: "Запись",
        action: "Действие",
        actor: "Пользователь",
        changedFields: "Изменённые поля",
        description: "Комментарий",
        occurredAt: "Дата и время",
    },
};
