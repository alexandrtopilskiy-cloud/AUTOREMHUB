import { browser, login, shot } from './lib.mjs';
import { pickDateTime, saveForm, openRequest } from './helpers.mjs';
const UNTIL = Number(process.argv[2] || 99);
const b = await browser();
const log = (...a) => console.log('▶', ...a);
async function nextRoute(p) {
  const btn = p.locator('.office-action.primary-btn').last();
  log('route button:', await btn.getAttribute('aria-label'));
  await btn.click();
  await p.waitForTimeout(1200);
}
async function dialogInfo(p) {
  const d = p.locator('dialog[open]');
  if (await d.count()) { log('dialog:', (await d.first().innerText()).replace(/\s+/g, ' ').slice(0, 400)); return d.first(); }
  return null;
}
// 1. Customer
const c = await login(b, 'alfa.petrova');
await c.getByRole('button', { name: /Новая заявка/ }).click();
await c.waitForTimeout(1500);
await c.getByPlaceholder('Введите VIN, госномер, марку или модель').fill('777');
await c.waitForTimeout(1200);
await c.locator('.search-results button').first().click();
await c.waitForTimeout(800);
await c.getByPlaceholder('Введите пробег').fill('52300');
await c.locator('select', { has: c.locator('option', { hasText: 'Диагностика' }) }).first().selectOption({ label: 'ТО' });
await c.getByPlaceholder('Укажите дополнительную информацию при необходимости').fill('Плановое ТО, скрип передних тормозов');
await c.locator('select', { has: c.locator('option', { hasText: 'Выберите контактное лицо' }) }).selectOption({ index: 1 });
await c.locator('select', { has: c.locator('option', { hasText: 'Выберите водителя' }) }).selectOption({ index: 1 });
await pickDateTime(c, c.locator('main'), '2026-10-21', '11:00');
await saveForm(c);
await shot(c, '01-customer-draft-saved');
await nextRoute(c);
let d = await dialogInfo(c);
if (d) { await shot(c, '01b-customer-send-dialog'); }

await shot(c, '01c-customer-after-send');
log('customer status after send:', (await c.locator('main').innerText()).match(/Маршрутизация УК|Черновик|Согласование[^\n]*/)?.[0]);
if (UNTIL <= 1) { await b.close(); process.exit(0); }
async function pickWork(p, idx, q) {
  const inp = p.getByPlaceholder('Начните вводить работу').nth(idx);
  await inp.click(); await inp.fill(q); await p.waitForTimeout(900);
  await p.locator('.work-search-results button').first().click();
  await p.waitForTimeout(900);
}
async function pickPart(p, idx, q) {
  const inp = p.getByPlaceholder('Начните вводить запчасть').nth(idx);
  await inp.click(); await inp.fill(q); await p.waitForTimeout(900);
  const opt = p.locator('.reference-picker-options button, .reference-picker-options [role=option], .search-results button').first();
  await opt.click(); await p.waitForTimeout(900);
}
// 2. UK routing
const u = await login(b, 'uk.ivanov');
await openRequest(u, 'RP-000001');
await u.locator('select', { has: u.locator('option', { hasText: 'ООО "КУБ"' }) }).selectOption({ label: 'ООО "КУБ"' });
await pickWork(u, 0, 'масл фильтр');
await u.getByRole('button', { name: 'Добавить строку работы' }).click();
await pickWork(u, 1, 'колод');
await pickPart(u, 0, 'фильтр масл');
await u.waitForTimeout(500);
await shot(u, '02-uk-estimate-before-save');
await saveForm(u);
await shot(u, '02b-uk-estimate-saved');
await nextRoute(u);
d = await dialogInfo(u);
await shot(u, '02c-uk-after-next');
log('uk status:', (await u.locator('main').innerText()).match(/Согласование СТО|Маршрутизация УК/)?.[0]);
if (UNTIL <= 2) { await b.close(); process.exit(0); }
async function buttons(p) {
  return (await p.locator('.office-action, .command-bar button, footer button').evaluateAll(els => els.map(e => (e.getAttribute('aria-label') || e.textContent.trim()) + (e.disabled ? '[dis]' : '')))).join(', ');
}
// 3. Station: changes estimate, confirms time -> УК (station_changes)
const s = await login(b, 'kub.master');
await shot(s, '03-station-home');
await openRequest(s, 'RP-000001');
log('station buttons:', await buttons(s));
await s.getByRole('button', { name: 'Добавить строку работы' }).click();
await pickWork(s, 2, 'диагн ходов');
await saveForm(s);
await shot(s, '03b-station-changed-estimate');
await nextRoute(s);
d = await dialogInfo(s);
if (d) await shot(s, '03c-station-dialog');
await s.waitForTimeout(800);
log('station status:', (await s.locator('main').innerText()).match(/Согласование работ \/ з\/ч|Согласование СТО|Согласование Заказчика/)?.[0]);
if (UNTIL <= 3) { await b.close(); process.exit(0); }
async function reload(p, number) {
  await p.reload(); await p.waitForTimeout(1500);
  await openRequest(p, number);
}
// 4. УК: station_changes -> Return to station with mandatory comment
await reload(u, 'RP-000001');
await shot(u, '04-uk-station-changes');
log('uk buttons:', await buttons(u));
await u.getByRole('button', { name: 'Вернуть в СТО для корректировки' }).click();
await u.waitForTimeout(800);
d = await dialogInfo(u);
await shot(u, '04b-uk-return-dialog');
const ta = d.locator('textarea');
const confirmBtn = d.locator('button.primary-btn, button[type=submit]').last();
log('confirm disabled w/o comment:', await confirmBtn.isDisabled());
if (!(await confirmBtn.isDisabled())) { await confirmBtn.click(); await u.waitForTimeout(800); }
log('after empty-comment click, dialog open:', await u.locator('dialog[open]').count(), (await u.locator('dialog[open]').innerText().catch(()=>'')).match(/обязател[^\n.]*/i)?.[0]);
await ta.fill('Диагностику ходовой согласуем отдельно, уберите строку');
await confirmBtn.click(); await u.waitForTimeout(1200);
log('uk status:', (await u.locator('main').innerText()).match(/Согласование СТО|Согласование работ \/ з\/ч/)?.[0]);
// 5. Station removes the line, confirms -> УК again? (changed estimate) -> we check behaviour
await reload(s, 'RP-000001');
await shot(s, '05-station-sees-return');
await s.locator('button[aria-label="Удалить работу"]').nth(2).click();
await saveForm(s);
await nextRoute(s);
d = await dialogInfo(s);
log('station status:', (await s.locator('main').innerText()).match(/Согласование Заказчика|Согласование работ \/ з\/ч|Согласование СТО/)?.[0]);
await shot(s, '05b-station-after-confirm');
if (UNTIL <= 5) { await b.close(); process.exit(0); }
// 6. УК approves -> customer
await reload(u, 'RP-000001');
await u.getByRole('button', { name: 'Согласовать и направить Заказчику' }).click();
await u.waitForTimeout(1200);
d = await dialogInfo(u);
if (d) { await d.locator('button.primary-btn, button[type=submit]').last().click(); await u.waitForTimeout(1200); }
log('uk status:', (await u.locator('main').innerText()).match(/Согласование Заказчика|Согласование работ \/ з\/ч/)?.[0]);
// 7. Customer final approval
await reload(c, 'RP-000001');
await shot(c, '07-customer-final-approval');
log('customer buttons:', await buttons(c));
log('customer sees block 04:', await c.getByText('Работы, запчасти и ремонт').count(), ' supplier col:', await c.getByText('Поставщик', { exact: true }).count());
await c.getByRole('button', { name: 'Подтвердить финальную заявку' }).click();
await c.waitForTimeout(1200);
d = await dialogInfo(c);
if (d) { await shot(c, '07b-customer-dialog'); await d.locator('button.primary-btn, button[type=submit]').last().click(); await c.waitForTimeout(1200); }
log('customer status:', (await c.locator('main').innerText()).match(/Ожидание ТС на СТО|Согласование Заказчика/)?.[0]);
if (UNTIL <= 7) { await b.close(); process.exit(0); }
// 8. Station: acceptance act (electronic)
await reload(s, 'RP-000001');
await s.getByRole('button', { name: 'Приём ТС', exact: true }).click();
await s.waitForTimeout(500);
const act = s.locator('form', { has: s.getByText('Сохранить акт и начать работы') });
await act.locator('input[type=number]').fill('52350');
await act.locator('select').first().selectOption('half');
await act.getByPlaceholder('Комплектность, состояние салона и кузова').fill('Комплект полный, салон чистый');
await act.locator('input[type=checkbox][value=scratch]').check();
await act.locator('.vehicle-diagram button').first().click();
await act.getByPlaceholder('Комментарий').first().fill('Царапина 5 см');
await shot(s, '08-station-acceptance-act');
await act.getByRole('button', { name: 'Сохранить акт и начать работы' }).click();
await s.waitForTimeout(1500);
log('station status:', (await s.locator('main').innerText()).match(/В работе|Ожидание ТС на СТО/)?.[0], '| act:', (await s.locator('main').innerText()).match(/AP-RP-\d+/)?.[0]);
await shot(s, '08b-station-in-work');
log('station buttons:', await buttons(s));
if (UNTIL <= 8) { await b.close(); process.exit(0); }
// 9. Station: diagnosis, parts movement, complete
const diag = s.locator('form', { has: s.getByText('Сохранить заключение') });
await diag.locator('textarea').fill('Износ передних колодок 2 мм, замена выполнена. Масло заменено.');
await diag.getByRole('button', { name: 'Сохранить заключение' }).click();
await s.waitForTimeout(1200);
for (const action of ['ordered', 'received']) {
  const sel = s.locator('select[aria-label^="Действие с запчастью"]').first();
  log('part status select:', await sel.locator('option').allTextContents());
  await sel.selectOption(action);
  await s.waitForTimeout(800);
  const op = s.locator('form.inset-form', { has: s.locator('textarea') }).last();
  await op.locator('textarea').fill(action === 'ordered' ? 'Заказано у Деталь-Снаб' : 'Получено на склад СТО');
  await op.locator('button.primary-btn').click();
  await s.waitForTimeout(1200);
}
await shot(s, '09-station-repair');
await nextRoute(s);
d = await dialogInfo(s);
if (d) { await shot(s, '09b-station-complete-dialog'); const t = d.locator('textarea'); if (await t.count()) await t.fill('Работы выполнены'); await d.locator('button.primary-btn, button[type=submit]').last().click(); await s.waitForTimeout(1500); }
log('station status:', (await s.locator('main').innerText()).match(/Готово|В работе/)?.[0]);
await shot(s, '09c-station-done');
if (UNTIL <= 9) { await b.close(); process.exit(0); }
async function upload(p, purposeLabel, file) {
  const sel = p.locator('select', { has: p.locator('option', { hasText: purposeLabel }) }).first();
  await sel.selectOption({ label: purposeLabel });
  await sel.locator('xpath=ancestor::*[.//input[@type="file"]][1]').locator('input[type=file]').first().setInputFiles(file);
  await p.waitForTimeout(1800);
}
async function confirmDialog(p, comment) {
  const dd = await dialogInfo(p);
  if (!dd) return;
  const t = dd.locator('textarea'); if (comment && await t.count()) await t.fill(comment);
  await dd.locator('button.primary-btn, button[type=submit]').last().click(); await p.waitForTimeout(1500);
}
const stageOf = async (p) => (await p.locator('.workspace .status-badge, main').first().innerText()).match(/Готово|Выдача автомобиля|Закрывающие документы|Ожидает оплаты|Оплата на проверке УК|Заявка закрыта/)?.[0];
// 10. УК: 6 -> 7
await reload(u, 'RP-000001');
log('uk buttons @6:', await buttons(u));
await nextRoute(u); await confirmDialog(u, 'Ремонт принят, автомобиль к выдаче');
log('after UK @6:', await stageOf(u));
// 11. Customer: 7 -> 8
await reload(c, 'RP-000001');
log('customer buttons @7:', await buttons(c));
await nextRoute(c); await confirmDialog(c, 'Автомобиль получен');
log('after customer @7:', await stageOf(c));
// 12. УК: invoice (stage 8)
await reload(u, 'RP-000001');
await shot(u, '12-uk-closing-docs');
async function uploadFile(p, file, purposeLabel) {
  if (purposeLabel) await p.locator('main select', { has: p.locator('option', { hasText: purposeLabel }) }).last().selectOption({ label: purposeLabel });
  await p.locator('main input[type=file]').last().setInputFiles(file);
  await p.waitForTimeout(2000);
}
await uploadFile(u, 'schet-2026-001.pdf', 'Выставленный счёт');
const inv = u.locator('form', { has: u.getByText(/Выставить счёт на/) });
await inv.locator('input').first().fill('СЧ-2026-001');
await inv.locator('select').selectOption({ index: 1 });
await inv.getByRole('button', { name: /Выставить счёт на/ }).click();
await u.waitForTimeout(1500);
await shot(u, '12b-uk-invoice-issued');
log('after invoice @8:', await stageOf(u), '| uk buttons:', await buttons(u));
if ((await stageOf(u)) === 'Закрывающие документы') { await nextRoute(u); await confirmDialog(u, 'Счёт выставлен'); }
log('after UK @8:', await stageOf(u));
// 13. Customer: payment (stage 9)
await reload(c, 'RP-000001');
await uploadFile(c, 'platezhka-2026-001.pdf', 'Закрытый счёт');
const pay = c.locator('form', { has: c.getByText('Сохранить подтверждение оплаты') });
await pay.locator('input[type=number]').fill('5810');
await pickDateTime(c, pay, '2026-10-19', '09:00').catch(e => log('paid date pick failed:', e.message.split('\n')[0]));
await pay.locator('select').last().selectOption({ index: 1 });
await pay.locator('textarea').fill('Оплачено платёжным поручением №118');
await shot(c, '13-customer-payment');
await pay.getByRole('button', { name: 'Сохранить подтверждение оплаты' }).click();
await c.waitForTimeout(1500);
log('after payment @9:', await stageOf(c), '| customer buttons:', await buttons(c));
if ((await stageOf(c)) === 'Ожидает оплаты') { await nextRoute(c); await confirmDialog(c, 'Оплата отправлена'); }
log('after customer @9:', await stageOf(c));
// 14. УК verifies payment (stage 10)
await reload(u, 'RP-000001');
const ver = u.locator('form', { has: u.getByRole('button', { name: 'Подтвердить оплату' }) });
await ver.locator('textarea').fill('Оплата поступила в полном объёме');
await ver.getByRole('button', { name: 'Подтвердить оплату' }).click();
await u.waitForTimeout(1500);
log('after verify @10:', await stageOf(u), '| uk buttons:', await buttons(u));
if ((await stageOf(u)) === 'Оплата на проверке УК') { await nextRoute(u); await confirmDialog(u, 'Закрыто'); }
log('final:', await stageOf(u));
await shot(u, '14-uk-closed');
await reload(c, 'RP-000001');
await shot(c, '14b-customer-closed');
await b.close();
