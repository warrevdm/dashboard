// Real creation routes run only against copied PHP and synthetic SQLite data in PHP/Wasm.
// No private config, production data, browser, HTTP calls or mail services are used.
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
    emscriptenOptions: { processId: process.pid },
  }));
  for (const directory of ['app', 'public', 'storage', 'sessions']) php.mkdirTree(`/rental-create/${directory}`);
  for (const name of fs.readdirSync(path.join(root, 'app')).filter(name => name.endsWith('.php'))) {
    php.writeFile(`/rental-create/app/${name}`, fs.readFileSync(path.join(root, 'app', name)));
  }
  for (const name of ['reservation-new.php', 'quick-replacement.php']) {
    php.writeFile(`/rental-create/public/${name}`, fs.readFileSync(path.join(root, 'public', name)));
  }
  php.writeFile('/rental-create/schema.sql', fs.readFileSync(path.join(root, 'database/schema.sql')));
  const env = { DB_PATH: '/rental-create/storage/database.sqlite', APP_ENV: 'test', APP_DEBUG: '1', MAIL_TRANSPORT: 'log' };
  let checks = 0;
  function check(condition, label) { assert.ok(condition, label); checks++; }
  async function run(code, options = {}) {
    const response = await php.run({
      code: `<?php ini_set('session.save_path','/rental-create/sessions');${code}`, env,
      $_SERVER: { SCRIPT_NAME: '/huur-module/reservation-new.php', REMOTE_ADDR: '192.0.2.1', HTTPS: 'on' },
      ...options,
    });
    assert.equal(response.errors, '', response.errors);
    assert.equal(response.exitCode, 0, response.text);
    return response;
  }
  function cookies(response, jar) {
    for (const cookie of response.headers['set-cookie'] || []) {
      const [name, ...value] = cookie.split(';')[0].split('=');
      jar[name] = value.join('=');
    }
  }
  async function request(page, { jar = {}, method = 'GET', data = {} } = {}) {
    const response = await run(`require '/rental-create/public/${page}';`, {
      method, relativeUri: `/huur-module/${page}`, protocol: 'https',
      $_SERVER: { SCRIPT_NAME: `/huur-module/${page}`, REMOTE_ADDR: '192.0.2.1', HTTPS: 'on' },
      body: method === 'POST' ? Buffer.from(new URLSearchParams(data).toString()) : undefined,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: Object.entries(jar).map(([key, value]) => `${key}=${value}`).join('; ') },
    });
    cookies(response, jar);
    return response;
  }
  async function sql(statement) {
    const encoded = Buffer.from(statement).toString('base64');
    return run(`require '/rental-create/app/bootstrap.php'; db()->exec(base64_decode('${encoded}'));`);
  }
  async function state() {
    return JSON.parse((await run(`require '/rental-create/app/bootstrap.php';
      $result=[]; foreach (['customers','reservations','reservation_bikes','payment_logs','rental_contracts','identity_documents','audit_logs'] as $table) {
        $result[$table]=db()->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetchAll();
      } echo json_encode($result);`)).text);
  }
  async function reset() {
    await sql('DELETE FROM rental_contracts; DELETE FROM payment_logs; DELETE FROM reservation_bikes; DELETE FROM reservations; DELETE FROM identity_documents; DELETE FROM customers; DELETE FROM audit_logs;');
  }
  async function session(id) {
    const response = await run(`require '/rental-create/app/bootstrap.php';
      $_SESSION['user']=db()->query('SELECT id,name,email,role FROM users WHERE id=${id}')->fetch(); echo csrf_token();`);
    const jar = {};
    cookies(response, jar);
    return { jar, token: response.text };
  }
  await run(`require '/rental-create/app/bootstrap.php'; db()->exec(file_get_contents('/rental-create/schema.sql'));`);
  await sql(`INSERT INTO users (id,name,email,password_hash,role) VALUES
    (1,'Fixture staff','staff@example.test','synthetic','staff'),
    (2,'Fixture finance','finance@example.test','synthetic','finance'),
    (3,'Fixture admin','admin@example.test','synthetic','admin'),
    (4,'Fixture workshop','berten@aertsactionbike.be','synthetic','staff');
    INSERT INTO bikes (id,code,name,category,usage_type,daily_rate,status) VALUES
    (1,'H-1','Fixture rental bike','E-bike','rental',30,'active'),
    (2,'T-1','Fixture test bike','Stadsfiets','test',15,'active'),
    (3,'V-1','Fixture replacement bike','E-bike','replacement',30,'active'),
    (4,'H-2','Fixture unavailable bike','E-bike','rental',30,'maintenance');`);
  const dates = JSON.parse((await run(`require '/rental-create/app/bootstrap.php';
    echo json_encode([(new DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d'),
      (new DateTimeImmutable('today'))->modify('+4 days')->format('Y-m-d')]);`)).text);
  const staff = await session(1);
  const finance = await session(2);
  const admin = await session(3);
  const workshop = await session(4);
  const fullData = {
    customer_name: 'Synthetic customer', customer_email: 'customer@example.test', customer_phone: '0123456789',
    start_date: dates[0], start_time: '09:00', end_date: dates[1], end_time: '17:00',
    'bike_ids[0]': '1', 'bike_ids[1]': '2', total_price: '123.45', price_calculation_mode: 'manual',
    status: 'confirmed', notes: 'Synthetic create fixture', initial_payment_method: 'cash', initial_payment_amount: '20',
  };
  const quickData = { customer_name: 'Synthetic quick customer', start_date: dates[0], return_date: dates[1], bike_id: '1' };
  const routes = [
    { page: 'reservation-new.php', actor: staff, data: fullData, defaultKind: 'rental' },
    { page: 'quick-replacement.php', actor: admin, data: quickData, defaultKind: 'replacement' },
  ];

  // Validate server-rendered choices and defaults, without requiring client-side JavaScript.
  const empty = JSON.stringify(await state());
  for (const { page, actor, data, defaultKind } of routes) {
    const anonymous = await request(page);
    check(anonymous.httpStatusCode === 302 && anonymous.headers.location?.[0] === 'index.php?route=login', `${page}: anonymous GET requires login`);
    const anonymousPost = await request(page, { method: 'POST', data: { ...data, rental_kind: 'test' } });
    check(anonymousPost.httpStatusCode === 302, `${page}: anonymous POST cannot create`);
    const rendered = await request(page, { jar: actor.jar });
    const select = rendered.text.match(/<select\b[^>]*name="rental_kind"[^>]*>([\s\S]*?)<\/select>/);
    check(rendered.httpStatusCode === 200 && select, `${page}: authenticated form renders a type selector`);
    const options = [...select[1].matchAll(/<option\b([^>]*)>([^<]*)<\/option>/g)];
    check(options.length === 3 && options.every((match, index) => match[1].includes(`value="${['rental', 'test', 'replacement'][index]}"`) && match[2] === ['Huur', 'Test', 'Vervang'][index]), `${page}: selector offers Huur, Test and Vervang`);
    check(options.filter(match => /\bselected\b/.test(match[1])).length === 1 && options.some(match => match[1].includes(`value="${defaultKind}"`) && /\bselected\b/.test(match[1])), `${page}: existing default stays selected`);
    for (const method of ['GET', 'POST']) {
      const denied = await request(page, { jar: finance.jar, method, data: { ...data, _token: finance.token, rental_kind: 'test' } });
      check(denied.httpStatusCode === 302 && denied.headers.location?.[0] === 'cashbook.php', `${page}: finance ${method} is redirected before creation`);
    }
    for (const token of [{}, { _token: 'incorrect' }, { '_token[]': actor.token }]) {
      const rejected = await request(page, { jar: actor.jar, method: 'POST', data: { ...data, rental_kind: 'test', ...token } });
      check(rejected.httpStatusCode === 419, `${page}: missing, wrong or array CSRF rejected`);
    }
  }
  for (const method of ['GET', 'POST']) {
    const denied = await request('quick-replacement.php', { jar: staff.jar, method, data: { ...quickData, _token: staff.token, rental_kind: 'test' } });
    check(denied.httpStatusCode === 403, `Quick creation still requires its original access rights for ${method}`);
  }
  check((await request('quick-replacement.php', { jar: workshop.jar })).httpStatusCode === 200, 'Existing workshop account retains quick-form access');
  check(JSON.stringify(await state()) === empty, 'GETs and rejected authorization or CSRF requests create no records');

  // Type alone must not change price calculation, payments, bike selection or availability.
  for (const mode of ['manual', 'auto']) {
    for (const kind of ['rental', 'test', 'replacement']) {
      await reset();
      const response = await request('reservation-new.php', { jar: staff.jar, method: 'POST', data: { ...fullData, _token: staff.token, rental_kind: kind, price_calculation_mode: mode } });
      const saved = await state();
      check(saved.reservations.length === 1 && saved.customers.length === 1, `Full ${kind}/${mode}: creates exactly one dossier and customer`);
      const reservation = saved.reservations[0];
      check(reservation.rental_kind === kind && reservation.status === 'confirmed' && reservation.notes === fullData.notes, `Full ${kind}/${mode}: selected type and entered details persist`);
      check(reservation.start_at === `${dates[0]} 09:00:00` && reservation.end_at === `${dates[1]} 17:00:00`, `Full ${kind}/${mode}: dates and hours persist`);
      check(Number(reservation.total_price) === (mode === 'manual' ? 123.45 : 90), `Full ${kind}/${mode}: selected type does not silently change the price`);
      check(saved.reservation_bikes.length === 2 && saved.reservation_bikes.map(row => Number(row.bike_id)).join(',') === '1,2', `Full ${kind}/${mode}: both selected bikes are reserved irrespective of bike usage category`);
      check(saved.payment_logs.length === 1 && Number(saved.payment_logs[0].amount) === 20 && saved.payment_logs[0].method === 'cash', `Full ${kind}/${mode}: initial payment is preserved`);
      check(saved.audit_logs.length === 1 && saved.audit_logs[0].entity_type === 'reservation' && JSON.parse(saved.audit_logs[0].details_json).rental_kind === kind, `Full ${kind}/${mode}: audit records the actual selected type`);
      const expected = kind === 'rental' ? `contract.php?reservation_id=${reservation.id}` : `reservation.php?id=${reservation.id}`;
      check(response.httpStatusCode === 302 && response.headers.location?.[0] === expected, `Full ${kind}/${mode}: continues to the appropriate contract or dossier page`);
    }
  }
  for (const kind of ['rental', 'test', 'replacement']) {
    await reset();
    const response = await request('quick-replacement.php', { jar: admin.jar, method: 'POST', data: { ...quickData, _token: admin.token, rental_kind: kind } });
    const saved = await state();
    check(saved.reservations.length === 1 && saved.customers.length === 1, `Quick ${kind}: creates exactly one dossier and customer`);
    const reservation = saved.reservations[0];
    check(reservation.rental_kind === kind && reservation.status === 'confirmed', `Quick ${kind}: stores selected type for future reservation`);
    check(reservation.start_at === `${dates[0]} 09:00:00` && reservation.end_at === `${dates[1]} 17:00:00`, `Quick ${kind}: preserves quick-form date and hour rules`);
    check(Number(reservation.total_price) === 0 && saved.payment_logs.length === 0, `Quick ${kind}: keeps the existing zero-price workflow without adding a payment`);
    check(saved.reservation_bikes.length === 1 && Number(saved.reservation_bikes[0].bike_id) === 1 && Number(saved.reservation_bikes[0].daily_rate) === 0, `Quick ${kind}: reserves the selected bike with its existing quick-booking rate`);
    check(saved.audit_logs.length === 1 && saved.audit_logs[0].entity_type === 'quick_replacement' && JSON.parse(saved.audit_logs[0].details_json).rental_kind === kind, `Quick ${kind}: audit records selected type`);
    const expected = kind === 'replacement' ? 'planning.php' : `reservation.php?id=${reservation.id}`;
    check(response.httpStatusCode === 302 && response.headers.location?.[0] === expected, `Quick ${kind}: continues to planning or dossier as appropriate`);
  }

  for (const kind of ['rental', 'test', 'replacement']) {
    await reset();
    const before = JSON.stringify(await state());
    const rejected = await request('reservation-new.php', { jar: staff.jar, method: 'POST', data: { ...fullData, _token: staff.token, rental_kind: kind, total_price: '0' } });
    check(rejected.httpStatusCode === 302 && rejected.headers.location?.[0] === 'reservation-new.php' && JSON.stringify(await state()) === before, `Full ${kind}: positive payment with zero total is rejected without writes`);
  }

  for (const { page, actor, data, defaultKind } of routes) {
    await reset();
    await request(page, { jar: actor.jar, method: 'POST', data: { ...data, _token: actor.token } });
    check((await state()).reservations[0]?.rental_kind === defaultKind, `${page}: omitted field remains backward compatible with older open forms`);
    for (const invalid of [{ rental_kind: 'other' }, { rental_kind: '' }, { rental_kind: '1' }, { 'rental_kind[]': 'test' }, { 'rental_kind[untrusted]': 'replacement' }]) {
      await reset();
      const before = JSON.stringify(await state());
      const rejected = await request(page, { jar: actor.jar, method: 'POST', data: { ...data, _token: actor.token, ...invalid } });
      check(rejected.httpStatusCode === 302 && rejected.headers.location?.[0] === page && JSON.stringify(await state()) === before, `${page}: invalid or array type rejected without writes (${JSON.stringify(invalid)})`);
    }
    for (const kind of ['rental', 'test', 'replacement']) {
      await reset();
      const blockedBike = page === 'reservation-new.php' ? 2 : 1;
      await sql(`INSERT INTO customers (id,name) VALUES (1000,'Synthetic existing booking');
        INSERT INTO reservations (id,bike_id,customer_id,start_at,end_at,status,rental_kind,created_by)
        VALUES (1000,${blockedBike},1000,'${dates[0]} 09:00:00','${dates[1]} 17:00:00','confirmed','rental',1);
        INSERT INTO reservation_bikes (reservation_id,bike_id,daily_rate) VALUES (1000,${blockedBike},30);`);
      const before = JSON.stringify(await state());
      const rejected = await request(page, { jar: actor.jar, method: 'POST', data: { ...data, _token: actor.token, rental_kind: kind } });
      check(rejected.httpStatusCode === 302 && rejected.headers.location?.[0] === page && JSON.stringify(await state()) === before, `${page}: conflicting ${kind} booking rejected without partial customer, payment or audit records`);
    }
  }
  // Compare the batched query with the original per-bike conflict predicate.
  const parity = JSON.parse((await run(`require '/rental-create/app/bootstrap.php';
    $checks = 0;
    foreach (['reserved','confirmed','picked_up','returned','cancelled'] as $status) {
      db()->exec("UPDATE reservations SET status = '" . $status . "'");
      foreach (['active','maintenance','inactive'] as $bikeStatus) {
        db()->exec("UPDATE bikes SET status = '" . $bikeStatus . "'");
        foreach ([null, 1000] as $exclude) {
          foreach ([['${dates[0]} 09:00:00','${dates[1]} 17:00:00'], ['${dates[1]} 17:00:00','${dates[1]} 18:00:00'], ['${dates[0]} 08:00:00','${dates[0]} 09:00:00']] as [$start,$end]) {
            $actual = bike_availability($start,$end,$exclude);
            foreach (all_bikes(true) as $bike) {
              $expected = $bike['status'] === 'active' && !reservation_conflicts((int)$bike['id'],$start,$end,$exclude);
              if ($actual[$bike['id']]['available'] !== $expected) throw new RuntimeException('Availability mismatch');
              $checks++;
            }
          }
        }
      }
    }
    echo json_encode($checks);`)).text);
  check(parity > 0, 'Batched availability matches conflict rules across statuses, excluded reservation and touching boundaries');
  check(!php.fileExists('/rental-create/storage/private/mail'), 'Creation requests never send or generate mail');
  check((await state()).rental_contracts.length === 0, 'Creation does not silently generate or sign any contract');
  console.log(`PASS: ${checks} real-route creation checks for types, defaults, pricing, permissions, CSRF, conflicts and audit using PHP 8.3.`);
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(() => {
  try { php?.exit(); } catch {}
  process.exit(process.exitCode || 0);
});
