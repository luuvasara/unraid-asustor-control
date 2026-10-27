// shot.cjs <base-url> <out-dir> - screenshots of the harness page (tests/harness/serve.sh). Written 2026-10-27.
// Viewport 1440 wide (a desktop webGui), browser time zone UTC (the fixtures are stamped in UTC), en-GB 24 h clock.
// Shots: status.png (Status tab, whole content), lcd-settings.png (LCD Settings tab) and leds.png (LEDs & Buttons tab). The page is captured as it
// renders; nothing is hidden or removed for the capture.
const pc = require(process.env.PLAYWRIGHT_CORE || 'playwright-core');
(async () => {
  const [base, out] = process.argv.slice(2);
  if (!base || !out) { console.error('usage: node shot.cjs <base-url> <out-dir>'); process.exit(2); }
  const b = await pc.chromium.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', headless: true,
                                       args: ['--disable-gpu', '--disable-dev-shm-usage'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, timezoneId: 'UTC', locale: 'en-GB' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  for (const [name, tab] of [['status', null], ['lcd-settings', 'LCD Settings'], ['leds', 'LEDs & Buttons']]) {
    await page.goto(base + '/Utilities/AsustorControl', { waitUntil: 'networkidle' });
    if (tab) await page.locator('[role=tab]', { hasText: tab }).first().click();
    await page.waitForTimeout(1500);   // ajax answers + canvas draw
    const h = await page.evaluate(() => document.documentElement.scrollHeight);
    await page.setViewportSize({ width: 1440, height: Math.min(h + 20, 6000) });
    await page.waitForTimeout(500);    // the chart redraws at the new size on the next refresh; give layout a beat
    await page.evaluate(() => typeof refreshHistory === 'function' && refreshHistory());
    await page.waitForTimeout(800);
    await page.locator('#displaybox').screenshot({ path: `${out}/${name}.png` });
    console.log('shot', name);
  }
  if (errors.length) { console.error('page errors:\n' + errors.join('\n')); process.exitCode = 1; }
  await b.close();
})().catch(e => { console.error(e.message); process.exit(1); });
