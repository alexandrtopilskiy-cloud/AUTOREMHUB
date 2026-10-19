const DEFAULT_WORKDAY_START = "10:00";
const DEFAULT_WORKDAY_END = "20:00";
const ALL_WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];

function parseLocalDateTime(value) {
    const match = String(value || "").match(
        /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/,
    );

    if (!match) {
        return null;
    }

    const [, year, month, day, hour, minute] = match;
    const date = new Date(
        Number(year),
        Number(month) - 1,
        Number(day),
        Number(hour),
        Number(minute),
        0,
        0,
    );

    return Number.isNaN(date.getTime()) ? null : date;
}

function minutesFromTime(value, fallback) {
    const match = String(value || fallback).match(/^(\d{2}):(\d{2})$/);

    if (!match) {
        return minutesFromTime(fallback, DEFAULT_WORKDAY_START);
    }

    return Number(match[1]) * 60 + Number(match[2]);
}

function isoWeekday(date) {
    return date.getDay() || 7;
}

function setMinuteOfDay(date, minuteOfDay) {
    const result = new Date(date);

    result.setHours(
        Math.floor(minuteOfDay / 60),
        minuteOfDay % 60,
        0,
        0,
    );

    return result;
}

function nextWorkday(date, workingDays, startMinute) {
    const candidate = new Date(date);

    candidate.setDate(candidate.getDate() + 1);
    candidate.setHours(0, 0, 0, 0);

    for (let attempt = 0; attempt < 7; attempt += 1) {
        if (workingDays.includes(isoWeekday(candidate))) {
            return setMinuteOfDay(candidate, startMinute);
        }

        candidate.setDate(candidate.getDate() + 1);
    }

    return setMinuteOfDay(candidate, startMinute);
}

function applyStationSchedule(date, station) {
    if (!station) {
        return date;
    }

    const workingDays = Array.isArray(station.workingDays)
        && station.workingDays.length
        ? [...new Set(station.workingDays.map(Number))]
        : ALL_WEEKDAYS;
    const startMinute = minutesFromTime(
        station.workdayStart,
        DEFAULT_WORKDAY_START,
    );
    const endMinute = minutesFromTime(
        station.workdayEnd,
        DEFAULT_WORKDAY_END,
    );

    if (!workingDays.includes(isoWeekday(date))) {
        return nextWorkday(date, workingDays, startMinute);
    }

    const minuteOfDay = date.getHours() * 60 + date.getMinutes();

    if (minuteOfDay < startMinute) {
        return setMinuteOfDay(date, startMinute);
    }

    if (minuteOfDay >= endMinute) {
        return nextWorkday(date, workingDays, startMinute);
    }

    return date;
}

function formatLocalDateTime(date) {
    const pad = (value) => String(value).padStart(2, "0");

    return [
        date.getFullYear(),
        "-",
        pad(date.getMonth() + 1),
        "-",
        pad(date.getDate()),
        "T",
        pad(date.getHours()),
        ":",
        pad(date.getMinutes()),
    ].join("");
}

/**
 * Время визита + 30 минут на приёмку + сумма норм времени с учётом количества.
 * Расчёт повторяет серверную логику и сразу показывает результат до сохранения.
 *
 * @param {object} request
 * @param {object|null} station
 * @returns {string}
 */
export function calculatePlannedCompletion(request, station = null) {
    const slot = request?.stationSlot || request?.slot || "";
    const completion = parseLocalDateTime(slot);

    if (!completion) {
        return "";
    }

    const normMinutes = (request?.works || []).reduce((total, work) => {
        const hours = Number(work?.normHours);
        const quantity = Number(work?.qty);

        if (!Number.isFinite(hours) || !Number.isFinite(quantity)) {
            return total;
        }

        return total + hours * quantity * 60;
    }, 0);

    completion.setMinutes(completion.getMinutes() + 30 + normMinutes);

    return formatLocalDateTime(applyStationSchedule(completion, station));
}
