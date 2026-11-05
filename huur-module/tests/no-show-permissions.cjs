// Real routes against synthetic users/reservations only; never reads private/live data.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const req = createRequire(path.resolve(__dirname, '../../lease/tests/package.json'));
const { PHP, FileLockManagerInMemory } = req('@php-wasm/universal');
const { loadNodeRuntime } = req('@php-wasm/node');
const root = path.resolve(__dirname, '..');
let php;

(async () => {
  php = new PHP(await loadNodeRuntime('8.3', {
    fileLockManager: new FileLockManagerInMemory(),
    emscriptenOptions: { processId: process.pid },
  }));
  for (const name of ['app', 'public/assets', 'storage', 'sessions']) php.mkdirTree('/permissions/' + name);
  for (const dir of ['app', 'public']) {
    for (const name of fs.readdirSync(path.join(root, dir)).filter(name => name.endsWith('.php'))) {
      php.writeFile(`/permissions/${dir}/${name}`, fs.readFileSync(path.join(root, dir, name)));
    }
  }
  for (const name of fs.readdirSync(path.join(root, 'public/assets'))) {
    if (fs.statSync(path.join(root, 'public/assets', name)).isFile()) {
      php.writeFile('/permissions/public/assets/' + name, fs.readFileSync(path.join(root, 'public/assets', name)));
    }
  }
  php.writeFile('/permissions/schema.sql', fs.readFileSync(path.join(root, 'database/schema.sql')));
  const env = { DB_PATH: '/permissions/storage/test.sqlite', APP_ENV: 'test', APP_DEBUG: '1', MAIL_TRANSPORT: 'log' };
  let checks = 0;
  function check(value, label) { assert.ok(value, label); checks++; }
  async function run(code, options = {}) {
    const response = await php.run({
      code: "<?php ini_set('session.save_path','/permissions/sessions');" + code,
      env, $_SERVER: { SCRIPT_NAME: '/fixture.php', HTTPS: 'on', REMOTE_ADDR: '192.0.2.1' }, ...options,
    });
    assert.equal(response.errors, '', response.errors);
    assert.equal(response.exitCode, 0, response.text);
    return response;
  }
  function updateCookies(response, actor) {
    for (const cookie of response.headers['set-cookie'] || []) {
      const [key, ...rest] = cookie.split(';')[0].split('=');
      actor.cookies[key] = rest.join('=');
    }
  }
  async function session(id, cachedRole) {
    const response = await run(`require '/permissions/app/bootstrap.php';
      $_SESSION['user']=['id'=>${id},'name'=>'Krista fixture','email'=>'krista@example.test','role'=>'${cachedRole}'];
      echo csrf_token();`);
    const actor = { cookies: {}, token: response.text };
    updateCookies(response, actor);
    return actor;
  }
  async function request(page, actor, { method = 'GET', query = {}, data = {}, scriptName } = {}) {
    const response = await run(`require '/permissions/public/${page}';`, {
      method, protocol: 'https', relativeUri: '/huur-module/' + page + '?' + new URLSearchParams(query),
      $_SERVER: { SCRIPT_NAME: scriptName || '/huur-module/' + page, HTTPS: 'on', REMOTE_ADDR: '192.0.2.1' },
      body: method === 'POST' ? Buffer.from(new URLSearchParams(data).toString()) : undefined,
      headers: { Cookie: Object.entries(actor.cookies).map(([key, value]) => key + '=' + value).join('; '),
        'Content-Type': 'application/x-www-form-urlencoded' },
    });
    updateCookies(response, actor);
    return response;
  }
  async function sql(statement) {
    return run("require '/permissions/app/bootstrap.php'; db()->exec(base64_decode('" + Buffer.from(statement).toString('base64') + "'));" );
  }
  async function reservation(kind = 'test', status = 'confirmed', start = '2000-01-01 09:00:00') {
    // Deliberately owned by another employee; staff no-show permission is not ownership-limited.
    const result = await run(`require '/permissions/app/bootstrap.php';
      $q=db()->prepare('INSERT INTO reservations(bike_id,customer_id,start_at,end_at,status,rental_kind,created_by) VALUES(1,1,?,?,?, ?,2)');
      $q->execute(['${start}','2099-12-31 17:00:00','${status}','${kind}']);
      $id=(int)db()->lastInsertId();
      db()->prepare('INSERT INTO reservation_bikes(reservation_id,bike_id) VALUES(?,1)')->execute([$id]);echo $id;`);
    return Number(result.text);
  }
  async function status(id) {
    return (await run(`require '/permissions/app/bootstrap.php'; echo find_reservation(${id})['status'];`)).text;
  }
  async function mark(actor, id, extra = {}) {
    return request('index.php', actor, { method: 'POST', query: { route: 'reservation-status', id },
      data: { _token: actor.token, status: 'no_show', confirm_no_show: '1', ...extra } });
  }

  await run("require '/permissions/app/bootstrap.php'; db()->exec(file_get_contents('/permissions/schema.sql'));" );
  await sql(`INSERT INTO users(id,name,email,password_hash,role,active) VALUES
    (1,'Krista fixture','krista@example.test','synthetic','staff',1),
    (2,'Other employee','other@example.test','synthetic','staff',1),
    (3,'Finance fixture','finance@example.test','synthetic','finance',1),
    (4,'Inactive employee','inactive@example.test','synthetic','staff',0),
    (5,'Deleted employee','deleted@example.test','synthetic','staff',1),
    (6,'Administrator fixture','admin@example.test','synthetic','admin',1);
    INSERT INTO customers(id,name,email) VALUES(1,'Synthetic customer','customer@example.test');
    INSERT INTO bikes(id,code,name,category,status) VALUES(1,'T-1','Synthetic test bike','E-bike','active');`);

  const staff = await session(1, 'staff');
  const staffId = await reservation();
  const staffPage = await request('reservation.php', staff, { query: { id: staffId } });
  check(staffPage.httpStatusCode === 200 && staffPage.text.includes('data-finance-readonly="0"'), 'Current employee gets writable dossier');
  check((staffPage.text.match(/<option value="no_show"/g) || []).length === 2, 'Employee sees both no-show status choices');
  const staffSave = await mark(staff, staffId);
  check(staffSave.httpStatusCode === 302 && await status(staffId) === 'no_show', 'Employee can mark another employee’s test booking no-show');

  const staleFinance = await session(1, 'finance');
  const staleId = await reservation('rental');
  const refreshedPage = await request('reservation.php', staleFinance, { query: { id: staleId } });
  check(refreshedPage.httpStatusCode === 200 && refreshedPage.text.includes('data-finance-readonly="0"'), 'Old finance session refreshes to current employee role before readonly gate');
  check((refreshedPage.text.match(/<option value="no_show"/g) || []).length === 2, 'New employee rights expose both no-show choices without another login');
  const savedAfterGet = await mark(staleFinance, staleId);
  check(savedAfterGet.httpStatusCode === 302 && await status(staleId) === 'no_show', 'Refreshed employee role can save Huur no-show');

  const direct = await session(1, 'finance');
  const directId = await reservation();
  const directPost = await mark(direct, directId);
  check(directPost.httpStatusCode === 302 && directPost.headers.location?.[0] !== 'cashbook.php', 'Direct status POST refreshes role before finance route whitelist');
  check(await status(directId) === 'no_show', 'Direct POST works with retained CSRF even without dossier GET');

  const finance = await session(3, 'staff');
  const financeId = await reservation();
  const financePage = await request('reservation.php', finance, { query: { id: financeId } });
  check(financePage.httpStatusCode === 200 && financePage.text.includes('data-finance-readonly="1"'), 'Revoked staff role becomes finance readonly immediately');
  check(!financePage.text.includes('<option value="no_show"'), 'Actual finance user does not get no-show controls');
  const deniedFinance = await mark(finance, financeId);
  check([302, 403].includes(deniedFinance.httpStatusCode) && await status(financeId) === 'confirmed', 'Finance cannot mark no-show using old employee session');
  const directFinance = await session(3, 'staff');
  await mark(directFinance, financeId);
  check(await status(financeId) === 'confirmed', 'Finance permission revocation also works on POST without prior GET');

  for (const [id, label] of [[4, 'inactive'], [5, 'deleted']]) {
    const account = await session(id, 'staff');
    if (id === 5) await sql('DELETE FROM users WHERE id=5;');
    const closedId = await reservation();
    const page = await request('reservation.php', account, { query: { id: closedId } });
    check(page.httpStatusCode === 302 && page.headers.location?.[0] === 'index.php?route=login', `${label} account is removed from authenticated dossier access`);
    const attempt = await mark(account, closedId);
    check([302, 403].includes(attempt.httpStatusCode) && await status(closedId) === 'confirmed', `${label} account cannot change a no-show status`);
  }

  const badCsrfActor = await session(1, 'finance');
  const badCsrfId = await reservation();
  const badCsrf = await mark(badCsrfActor, badCsrfId, { _token: 'invalid' });
  check(badCsrf.httpStatusCode === 419 && await status(badCsrfId) === 'confirmed', 'Refreshing employee access does not bypass CSRF');

  const legacy = await session(1, 'finance');
  const legacyId = await reservation();
  const legacySave = await request('index.php', legacy, { method: 'POST', scriptName: '/huur-module/legacy.php',
    query: { route: 'reservation-status', id: legacyId }, data: { _token: legacy.token, status: 'no_show', confirm_no_show: '1' } });
  check(legacySave.httpStatusCode === 302 && await status(legacyId) === 'no_show', 'Legacy dispatcher uses the same no-show handler and refreshed staff rights');
  const deniedLegacy = await session(3, 'staff');
  const deniedLegacyId = await reservation();
  await request('index.php', deniedLegacy, { method: 'POST', scriptName: '/huur-module/legacy.php',
    query: { route: 'reservation-status', id: deniedLegacyId }, data: { _token: deniedLegacy.token, status: 'no_show', confirm_no_show: '1' } });
  check(await status(deniedLegacyId) === 'confirmed', 'Legacy dispatcher also denies current finance role');

  const admin = await session(6, 'admin');
  const adminId = await reservation();
  await mark(admin, adminId);
  check(await status(adminId) === 'no_show', 'Administrator retains no-show permission');

  for (const scenario of [
    { kind: 'test', status: 'picked_up', start: '2000-01-01 09:00:00', label: 'already picked up' },
    { kind: 'rental', status: 'confirmed', start: '2099-01-01 09:00:00', label: 'future start' },
    { kind: 'replacement', status: 'confirmed', start: '2000-01-01 09:00:00', label: 'replacement booking' },
  ]) {
    const id = await reservation(scenario.kind, scenario.status, scenario.start);
    await mark(staff, id);
    check(await status(id) === scenario.status, `Employee permission does not bypass ${scenario.label} rule`);
  }

  console.log(`PASS: ${checks} no-show permission checks for current/stale staff, finance, inactive/deleted users, CSRF and legacy routes.`);
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(() => {
  try { php?.exit(); } catch {} process.exit(process.exitCode || 0);
});
