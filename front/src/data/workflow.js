/**
 * Workflow заявки: этапы, владельцы и действия перехода.
 *
 * Индекс в каждом массиве соответствует номеру этапа заявки (0–11).
 * Эти данные должны совпадать с backend (Workflow::STAGES в PHP).
 */

/* ─── Этапы ─── */

/** Названия этапов (индекс = номер этапа). */
const STATUSES = [
  'Черновик',                 // 0
  'Маршрутизация УК',         // 1
  'Согласование СТО',         // 2
  'Согласование Заказчика',   // 3
  'Ожидание ТС на СТО',       // 4
  'В работе',                 // 5
  'Готово',                   // 6
  'Выдача автомобиля',        // 7
  'Закрывающие документы',    // 8
  'Ожидает оплаты',           // 9
  'Оплата на проверке УК',    // 10
  'Заявка закрыта',           // 11
];

/** Владельцы этапов (индекс = номер этапа). */
const OWNERS = [
  'Заказчик',
  'УК',
  'СТО-партнёр',
  'Заказчик',
  'СТО-партнёр',
  'СТО-партнёр',
  'УК',
  'Заказчик',
  'УК',
  'Заказчик',
  'УК',
  'Архив',
];

/** Действие для перехода на следующий этап (индекс = текущий этап). */
const NEXT_ACTIONS = [
  'Отправить в УК',
  'Передать СТО',
  'Согласовать условия',
  'Подтвердить финальную заявку',
  'Оформить приём ТС',
  'Подтвердить завершение работ',
  'Разрешить выдачу',
  'Подтвердить приёмку',
  'Передать счёт Заказчику',
  'Отправить оплату на проверку',
  'Закрыть оплаченную заявку',
  'Завершено',
];

/* ─── Классы этапов ─── */

/** Номер последнего этапа («Заявка закрыта»). */
const CLOSED_STAGE = 11;

const CLASS_GREEN = 'green';

/* ─── Экспорты ─── */

/**
 * @typedef {object} WorkflowStep
 * @property {string} status — название этапа.
 * @property {string} owner  — владелец этапа.
 * @property {string} next   — действие для перехода на следующий этап.
 */

/**
 * Workflow: этапы с владельцами и действиями.
 *
 * @type {WorkflowStep[]}
 */
export const workflow = STATUSES.map((status, index) => ({
  status,
  owner: OWNERS[index],
  next: NEXT_ACTIONS[index],
}));

/**
 * CSS-класс для этапа заявки.
 *
 * @param {number} stage
 * @returns {string}
 */
export function statusClass(stage, permissions = {}) {
  if (stage === CLOSED_STAGE) return CLASS_GREEN;
  const actionable = permissions.requiresAction ?? ['canAdvance', 'canReturn', 'canStationDecide', 'canCustomerSlotDecide', 'canAcceptVehicle', 'canDiagnose', 'canIssueInvoice', 'canSubmitPayment', 'canVerifyPayment'].some(key => permissions[key] === true);
  return actionable ? 'red' : 'gray';
}
