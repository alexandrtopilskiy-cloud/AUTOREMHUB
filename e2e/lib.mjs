const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
export const SHOTS = process.env.E2E_SHOTS || new URL('./shots', import.meta.url).pathname;
export async function browser() { return chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}); }
export async function login(b, user, opts = {}) {
  const ctx = await b.newContext({ viewport: opts.viewport || { width: 1600, height: 950 }, locale: 'ru-RU', timezoneId: 'Europe/Moscow', colorScheme: opts.colorScheme || 'light' });
  const p = await ctx.newPage();
  p.on('console', m => { if (m.type() === 'error' && !/401/.test(m.text())) console.log(`[${user}] CONSOLE`, m.text()); });
  p.on('pageerror', e => console.log(`[${user}] PAGEERROR`, e.message));
  p.on('response', async r => { if (r.status() >= 400 && r.url().includes('/api/')) { let t=''; try { t = (await r.text()).slice(0,300); } catch {} console.log(`[${user}] HTTP ${r.status()} ${r.request().method()} ${r.url().replace('http://127.0.0.1:8000','')} ${t}`); } });
  await p.goto(process.env.E2E_FRONT_URL || 'http://localhost:5173/');
  await p.fill('input[name=username]', user);
  await p.fill('input[name=password]', 'Demo-pass-2026');
  await p.click('button.login-submit');
  await p.waitForTimeout(800);
  const force = p.getByRole('button', { name: /Выйти на другом устройстве/ });
  if (await force.count()) { await force.click(); }
  await p.waitForSelector('.login-page', { state: 'detached', timeout: 15000 });
  await p.waitForTimeout(1200);
  return p;
}
export async function shot(p, name) { await p.screenshot({ path: `${SHOTS}/${name}.png` }); console.log('shot', name); }
