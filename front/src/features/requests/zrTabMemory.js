/**
 * Стартовая вкладка ЗР и память открытой вкладки.
 *
 * При открытии ЗР вкладка выбирается по этапу (startZrTab). Пока ЗР
 * открыта в рабочей области, карточка может пересоздаваться (например,
 * при переходе в Рекомендации и обратно) — тогда возвращаемся на вкладку,
 * на которой ЗР оставили. После закрытия ЗР память сбрасывается
 * (forgetZrTab), а при смене этапа снова действует стартовая вкладка.
 */

/** Стартовые вкладки по номеру этапа; остальные этапы — «Автомобиль и причина обращения». */
const STAGE_START_TABS = {
  4: 'acts', // Ожидание ТС на СТО
  5: 'works', // В работе (и «Согласование работ / з/ч»)
  6: 'acts', // ТС готово к выдаче Заказчику
  7: 'documents', // Выдан. Подготовка документов
  8: 'payments', // Подтверждение оплаты Заказчика
  9: 'payments', // Оплата в СТО
};

export const DEFAULT_ZR_TAB = 'vehicle';

export function startZrTab(stage) {
  return STAGE_START_TABS[Number(stage)] || DEFAULT_ZR_TAB;
}

const lastTabs = new Map();

export function rememberedZrTab(requestId, stage) {
  const saved = requestId ? lastTabs.get(requestId) : null;

  return saved && saved.stage === Number(stage) ? saved.key : startZrTab(stage);
}

export function rememberZrTab(requestId, stage, key) {
  if (requestId) {
    lastTabs.set(requestId, { stage: Number(stage), key });
  }
}

export function forgetZrTab(requestId) {
  lastTabs.delete(requestId);
}
