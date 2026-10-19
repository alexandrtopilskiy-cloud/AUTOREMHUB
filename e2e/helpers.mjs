export async function pickDateTime(p, scope, date, time) {
  const root = scope.locator('.confirmed-datetime').filter({ has: p.locator('.confirmed-datetime-trigger:not([disabled])') }).first();
  await root.locator('.confirmed-datetime-trigger').click();
  await root.locator('input[type=date]').fill(date);
  await root.locator('input[type=date]').dispatchEvent('change');
  await root.locator('select').selectOption(time);
  await p.waitForTimeout(300);
}
export async function saveForm(p) {
  await p.getByRole('button', { name: 'Сохранить', exact: true }).click();
  await p.waitForTimeout(1200);
}
export async function openRequest(p, number) {
  await p.getByRole('button', { name: /Заявки на ремонт/ }).first().click();
  await p.waitForTimeout(800);
  await p.locator('tr', { hasText: number }).first().dblclick();
  await p.waitForTimeout(1500);
}
export async function statusText(p) {
  return (await p.locator('.workspace .status-badge, .request-status, .status-pill').first().textContent().catch(() => ''))?.trim();
}
