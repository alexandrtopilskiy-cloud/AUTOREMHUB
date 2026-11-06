/**
 * Последняя открытая вкладка каждой ЗР в текущем сеансе.
 * Карточка ЗР пересоздаётся при переключении рабочих вкладок (например,
 * при переходе в Рекомендации и обратно), и без этой памяти она снова
 * открывалась бы на первой вкладке.
 */
const lastTabs = new Map();

export function rememberedZrTab(requestId, fallback) {
  return (requestId && lastTabs.get(requestId)) || fallback;
}

export function rememberZrTab(requestId, key) {
  if (requestId) {
    lastTabs.set(requestId, key);
  }
}
