// Executes only copied application functions and synthetic SQLite fixtures in PHP/Wasm.
// Only synthetic local requests; no private configuration, real data, HTTP or mail services.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const runtimeRequire = createRequire(path.resolve(__dirname, '../../lease/tests/package.json'));
const { PHP, FileLockManagerInMemory } = runtimeRequire('@php-wasm/universal');
const { loadNodeRuntime } = runtimeRequire('@php-wasm/node');
const root = path.resolve(__dirname, '..');
let php;
(async () => {
  php = new PHP(await loadNodeRuntime('8.3', {
    fileLockManager: new FileLockManagerInMemory(),
    emscriptenOptions: {processId: process.pid},
  }));
  php.mkdirTree('/rental-fixture/app');
  for (const name of ['database.php', 'env.php', 'security.php', 'repositories.php', 'contracts_v2.php', 'reservation_edit.php']) {
    php.writeFile(`/rental-fixture/app/${name}`, fs.readFileSync(path.join(root, 'app', name)));
  }
  php.writeFile('/rental-fixture/schema.sql', fs.readFileSync(path.join(root, 'database/schema.sql')));
  php.writeFile('/rental-fixture/tests.php', fs.readFileSync(path.join(__dirname, 'reservation-edit.php')));
  const response = await php.run({scriptPath: '/rental-fixture/tests.php'});
  assert.equal(response.errors, '', response.errors);
  assert.equal(response.exitCode, 0, response.text);
  assert.match(response.text, /^PASS: \d+ rental edit and migration checks using PHP /);
  console.log(response.text.trim());

  let webChecks = 0;
  function check(condition, label) { assert.ok(condition, label); webChecks++; }
  // Exercise real bootstrap + routes against a separate copy of the synthetic database.
  php.mkdirTree('/rental-web/app');
  php.mkdirTree('/rental-web/public');
  php.mkdirTree('/rental-web/storage');
  php.mkdirTree('/rental-web/sessions');
  for (const name of fs.readdirSync(path.join(root, 'app')).filter(name => name.endsWith('.php'))) {
    php.writeFile(`/rental-web/app/${name}`, fs.readFileSync(path.join(root, 'app', name)));
  }
  for (const name of ['reservation.php', 'reservation-end-date.php', 'reservation-stamp.php']) {
    php.writeFile(`/rental-web/public/${name}`, fs.readFileSync(path.join(root, 'public', name)));
  }
  php.writeFile('/rental-web/storage/database.sqlite', php.readFileAsBuffer('/rental-fixture/synthetic.sqlite'));
  const env = {DB_PATH: '/rental-web/storage/database.sqlite', APP_ENV: 'test', APP_DEBUG: '1', MAIL_TRANSPORT: 'log'};
  const prelude = "ini_set('session.save_path','/rental-web/sessions');";
  async function run(code, options = {}) {
    const result = await php.run({code: `<?php ${prelude}${code}`, env,
      $_SERVER: {SCRIPT_NAME: '/huur-module/reservation.php', REMOTE_ADDR: '192.0.2.1', HTTPS: 'on'}, ...options});
    assert.equal(result.errors, '', result.errors);
    assert.equal(result.exitCode, 0, result.text);
    return result;
  }
  async function request({jar = {}, method = 'GET', data = {}, page = 'reservation.php', id = '1'} = {}) {
    const response = await run(`require '/rental-web/public/${page}';`, {
      method, relativeUri: `/huur-module/${page}?id=${id}`, protocol: 'https',
      body: method === 'POST' ? Buffer.from(new URLSearchParams(data).toString()) : undefined,
      headers: {'Content-Type': 'application/x-www-form-urlencoded', Cookie: Object.entries(jar).map(([key, value]) => `${key}=${value}`).join('; ')},
    });
    for (const cookie of response.headers['set-cookie'] || []) {
      const [name, ...value] = cookie.split(';')[0].split('=');
      jar[name] = value.join('=');
    }
    return response;
  }
  async function seedSession(role) {
    const response = await run(`require '/rental-web/app/bootstrap.php'; $_SESSION['user']=['id'=>${role === 'finance' ? 2 : 1},'name'=>'Fixture','email'=>'fixture@example.test','role'=>'${role}']; echo csrf_token();`);
    const jar = {};
    for (const cookie of response.headers['set-cookie'] || []) {
      const [name, ...value] = cookie.split(';')[0].split('=');
      jar[name] = value.join('=');
    }
    return {jar, token: response.text};
  }
  async function state() {
    return JSON.parse((await run("require '/rental-web/app/bootstrap.php'; echo json_encode([db()->query('SELECT * FROM reservations ORDER BY id')->fetchAll(),db()->query('SELECT * FROM customers ORDER BY id')->fetchAll(),db()->query('SELECT * FROM audit_logs ORDER BY id')->fetchAll()]);")).text);
  }
  // The PHP suite ends with a returned dossier; reopen its fixture and remove its synthetic competitor.
  await run("require '/rental-web/app/bootstrap.php'; db()->exec(\"DELETE FROM reservations WHERE id>1; UPDATE reservations SET status='confirmed' WHERE id=1\");");
  const beforeRequests = JSON.stringify(await state());
  const anonymous = await request();
  check(anonymous.httpStatusCode === 302 && anonymous.headers.location?.[0] === 'index.php?route=login', 'Anonymous editor GET redirects to login');
  const anonPost = await request({method: 'POST', data: {action: 'update-details', id: '1'}});
  check(anonPost.httpStatusCode === 302, 'Anonymous editor POST cannot mutate');
  const staff = await seedSession('staff');
  const staffPage = await request({jar: staff.jar});
  check(staffPage.httpStatusCode === 200 && staffPage.text.includes('name="action" value="update-details"'), 'Staff can open the dossier edit form');
  for (const field of ['start_date', 'start_time', 'end_date', 'end_time', 'customer_name', 'customer_email', 'customer_phone', 'customer_address', 'rental_kind', 'status', 'notes', 'version']) {
    check(staffPage.text.includes(`name="${field}"`), `Rendered form includes ${field}`);
  }
  check(['Huur', 'Test', 'Vervang'].every(label => staffPage.text.includes(`>${label}</option>`)), 'Form offers all three booking types');
  const finance = await seedSession('finance');
  const financePage = await request({jar: finance.jar});
  check(financePage.httpStatusCode === 200 && financePage.text.includes('Alleen-lezen voor Boekhouding') && !financePage.text.includes('value="update-details"'), 'Finance sees dossier read-only without an edit form');
  const financePost = await request({jar: finance.jar, method: 'POST', data: {_token: finance.token, action: 'update-details', id: '1'}});
  check(financePost.httpStatusCode === 403, 'Finance POST rejected even with valid CSRF');
  for (const token of [{}, {_token: 'invalid'}, {'_token[]': staff.token}]) {
    const rejected = await request({jar: staff.jar, method: 'POST', data: {action: 'update-details', id: '1', ...token}});
    check(rejected.httpStatusCode === 419, 'Missing, wrong or array CSRF token rejected by real route');
  }
  check(JSON.stringify(await state()) === beforeRequests, 'Page reads and rejected requests leave dossier data unchanged');
  const version = staffPage.text.match(/name="version" value="([a-f0-9]{64})"/)[1];
  const editData = {_token: staff.token, action: 'update-details', id: '1', version,
    customer_name: 'Synthetic customer', customer_email: 'customer@example.test', customer_phone: '0123456789', customer_address: 'Fixture street 1',
    start_date: '2026-10-10', start_time: '08:45', end_date: '2026-10-12', end_time: '18:15', rental_kind: 'test', status: 'confirmed', notes: 'Web route change'};
  const success = await request({jar: staff.jar, method: 'POST', data: editData});
  check(success.httpStatusCode === 302 && success.headers.location?.[0] === 'reservation.php?id=1#dossier-bewerken', 'Valid form saves and redirects to dossier editor');
  const saved = (await state())[0][0];
  check(saved.start_at === '2026-10-10 08:45:00' && saved.end_at === '2026-10-12 18:15:00' && saved.rental_kind === 'test' && saved.notes === 'Web route change', 'Real POST persists dates, times, type and notes');
  const savedPage = await request({jar: staff.jar});
  const currentVersion = savedPage.text.match(/name="version" value="([a-f0-9]{64})"/)[1];
  const rejectedData = {...editData, version: currentVersion, end_date: '2026-01-01', notes: '<script>alert("fixture")</script>'};
  const invalidPage = await request({jar: staff.jar, method: 'POST', data: rejectedData});
  check(invalidPage.httpStatusCode === 200 && invalidPage.text.includes('role="alert"') && invalidPage.text.includes('value="2026-01-01"'), 'Validation errors render entered values for correction');
  check(invalidPage.text.includes('&lt;script&gt;') && !invalidPage.text.includes('<script>alert("fixture")</script>'), 'Rejected input is safely escaped when redisplayed');
  check((await state())[0][0].end_at === '2026-10-12 18:15:00', 'Invalid route input does not alter saved dates');
  const beforeOldRoute = JSON.stringify(await state());
  const oldRoute = await request({jar: staff.jar, page: 'reservation-end-date.php', method: 'POST', data: {_token: staff.token, end_date: '2099-01-01'}});
  check(oldRoute.httpStatusCode === 302 && oldRoute.headers.location?.[0] === 'reservation.php?id=1#dossier-bewerken' && JSON.stringify(await state()) === beforeOldRoute, 'Old end-date route redirects without bypassing the new validations');
  await run("require '/rental-web/app/bootstrap.php'; db()->exec(\"UPDATE reservations SET status='cancelled' WHERE id=1\");");
  const cancelledPage = await request({jar: staff.jar});
  check(cancelledPage.httpStatusCode === 200 && !cancelledPage.text.includes('value="update-details"'), 'Cancelled dossier renders without an edit form');
  check(!php.fileExists('/rental-web/storage/private/mail'), 'Editing and rendering never send or generate mail');
  console.log(`PASS: ${webChecks} real-route authentication, CSRF, rendering and form-submission checks.`);
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(() => {
  try { php?.exit(); } catch {}
  process.exit(process.exitCode || 0);
});
