// Synthetic PHP/Wasm databases and logged/fake transports only. No production data or external mail.
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
    fileLockManager: new FileLockManagerInMemory(), emscriptenOptions: { processId: process.pid },
  }));
  for (const d of ['app', 'public/assets', 'storage', 'sessions']) php.mkdirTree('/booking/' + d);
  for (const name of fs.readdirSync(root + '/app').filter(f => f.endsWith('.php'))) {
    php.writeFile('/booking/app/' + name, fs.readFileSync(root + '/app/' + name));
  }
  for (const name of fs.readdirSync(root + '/public').filter(f => f.endsWith('.php'))) {
    php.writeFile('/booking/public/' + name, fs.readFileSync(root + '/public/' + name));
  }
  for (const name of ['booking-mail.js', 'booking-mail.css']) {
    php.writeFile('/booking/public/assets/' + name, fs.readFileSync(root + '/public/assets/' + name));
  }
  php.writeFile('/booking/schema.sql', fs.readFileSync(root + '/database/schema.sql'));
  const env = { DB_PATH: '/booking/storage/database.sqlite', APP_ENV: 'test', APP_DEBUG: '1', MAIL_TRANSPORT: 'log', MAIL_FROM_ADDRESS: 'shop@example.test' };
  let checks = 0;
  function check(value, label) { assert.ok(value, label); checks++; }
  async function run(code, options = {}) {
    const response = await php.run({
      code: "<?php ini_set('session.save_path','/booking/sessions');" + code, env,
      $_SERVER: { SCRIPT_NAME: '/huur-module/booking-mail.php', HTTPS: 'on', REMOTE_ADDR: '192.0.2.1' },
      ...options,
    });
    assert.equal(response.exitCode, 0, response.text + response.errors);
    assert.equal(response.errors, '', response.errors);
    return response;
  }
  function setCookies(response, jar) {
    for (const value of response.headers['set-cookie'] || []) {
      const [key, ...rest] = value.split(';')[0].split('='); jar[key] = rest.join('=');
    }
  }
  function cookieHeader(jar) { return Object.entries(jar).map(([k,v]) => k + '=' + v).join('; '); }
  async function request(page, { actor, method = 'GET', data = {}, query = {} } = {}) {
    const response = await run(`require '/booking/public/${page}';`, {
      method, protocol: 'https', relativeUri: '/huur-module/' + page + '?' + new URLSearchParams(query),
      $_SERVER: { SCRIPT_NAME: '/huur-module/' + page, HTTPS: 'on', REMOTE_ADDR: '192.0.2.1' },
      body: method === 'POST' ? Buffer.from(new URLSearchParams(data).toString()) : undefined,
      headers: { Cookie: cookieHeader(actor?.jar || {}), 'Content-Type': 'application/x-www-form-urlencoded' },
    });
    if (actor) setCookies(response, actor.jar);
    return response;
  }
  async function actorRun(actor, code) {
    return run("require '/booking/app/bootstrap.php'; require_once '/booking/app/booking_mail.php';" + code, {
      headers: { Cookie: cookieHeader(actor.jar) },
    });
  }
  async function sql(statement) {
    return run("require '/booking/app/bootstrap.php';db()->exec(base64_decode('" + Buffer.from(statement).toString('base64') + "'));" );
  }
  async function files() {
    return JSON.parse((await run("echo json_encode(glob('/booking/storage/private/mail/*') ?: []);")).text);
  }
  async function session(id) {
    const response = await run(`require '/booking/app/bootstrap.php';$_SESSION['user']=db()->query('SELECT id,name,email,role FROM users WHERE id=${id}')->fetch();echo csrf_token();`);
    const actor = { jar: {}, token: response.text }; setCookies(response, actor.jar); return actor;
  }
  await run("require '/booking/app/bootstrap.php';db()->exec(file_get_contents('/booking/schema.sql'));");
  await sql(`INSERT INTO users(id,name,email,password_hash,role) VALUES
    (1,'Fixture Staff','staff@example.test','x','staff'),
    (2,'Fixture Finance','finance@example.test','x','finance'),
    (3,'Fixture Admin','admin@example.test','x','admin'),
    (4,'Other Staff','other@example.test','x','staff');
    INSERT INTO bikes(id,code,name,category,usage_type,daily_rate,status) VALUES
    (1,'H-1','Synthetic bike','E-bike','rental',30,'active');`);
  // Existing deployments have no mail-run table yet. Merely viewing/skipping must not add it.
  await sql('DROP TABLE IF EXISTS reservation_booking_mail_runs;');
  const staff = await session(1), finance = await session(2), admin = await session(3), other = await session(4);
  let sequence = 0;
  async function create(kind = 'test', actor = staff, quick = false) {
    const day = new Date(Date.UTC(2099, 0, 1 + 4 * sequence++));
    const start = day.toISOString().slice(0, 10); day.setUTCDate(day.getUTCDate() + 1);
    const end = day.toISOString().slice(0, 10);
    const data = quick
      ? { customer_name: 'Synthetic quick customer', start_date: start, return_date: end, bike_id: '1' }
      : { customer_name: 'Synthetic <customer>', customer_email: 'customer@example.test', start_date: start,
          start_time: '09:00', end_date: end, end_time: '17:00', 'bike_ids[0]': '1', total_price: '0', status: 'confirmed' };
    const response = await request(quick ? 'quick-replacement.php' : 'reservation-new.php', { actor, method: 'POST', data: { ...data, _token: actor.token, rental_kind: kind } });
    const id = Number((await run("require '/booking/app/bootstrap.php';echo db()->query('SELECT MAX(id) FROM reservations')->fetchColumn();")).text);
    return { id, response, actor, kind, quick };
  }
  const schemaBefore = await run("require '/booking/app/bootstrap.php';echo db()->query(\"SELECT COUNT(*) FROM sqlite_master WHERE name='reservation_booking_mail_runs'\")->fetchColumn();");
  check(schemaBefore.text === '0', 'GET and skipped choices do not create a mail-delivery table');
  async function prompt(booking) {
    return JSON.parse((await actorRun(booking.actor, `echo json_encode(booking_mail_prompt(${booking.id}));`)).text);
  }
  async function post(booking, data) {
    const p = await prompt(booking);
    return request('booking-mail.php', { actor: booking.actor, method: 'POST', query: { id: booking.id },
      data: { _token: booking.actor.token, reservation_id: String(booking.id), nonce: p?.nonce || '', ...data } });
  }

  const messageChecks = await run(`require '/booking/app/bootstrap.php';require_once '/booking/app/booking_mail_message.php';
    $checks=0;function ok($x,$label){global $checks;if(!$x)throw new Exception($label);$checks++;}
    $r=['id'=>42,'rental_kind'=>'test','status'=>'confirmed','customer_name'=>'<Synthetic & klant>','customer_email'=>'customer@example.test',
        'start_at'=>'2026-12-01 09:00:00','end_at'=>'2026-12-01 17:00:00','bikes'=>[['id'=>1,'code'=>'T-1','name'=>'<Bike & model>']]];
    $m=booking_mail_message($r,new DateTimeImmutable('2026-11-01 12:00:00',new DateTimeZone('UTC')));
    ok(str_contains($m['calendar'],'DTSTART:20261201T080000Z')&&str_contains($m['calendar'],'DTEND:20261201T160000Z'),'Winter UTC');
    ok(str_contains($m['calendar'],'DTSTAMP:20261101T120000Z'),'Deterministic UTC stamp');
    ok(str_contains($m['calendar'],'METHOD:PUBLISH')&&str_contains($m['calendar'],'TRIGGER:-PT1H'),'Personal calendar and reminder');
    ok(str_contains($m['html'],'&lt;Synthetic &amp; klant&gt;')&&str_contains($m['html'],'&lt;Bike &amp; model&gt;'),'HTML escapes customer and bike');
    ok(!str_contains($m['calendar'],'reservation.php')&&!str_contains($m['html'],'reservation.php'),'No internal dossier link');
    $summer=booking_mail_message(array_replace($r,['start_at'=>'2026-07-01 09:00:00','end_at'=>'2026-07-01 17:00:00']));
    ok(str_contains($summer['calendar'],'DTSTART:20260701T070000Z')&&str_contains($summer['calendar'],'DTEND:20260701T150000Z'),'Summer UTC');
    $switch=booking_mail_message(array_replace($r,['start_at'=>'2026-03-28 09:00:00','end_at'=>'2026-03-29 09:00:00']));
    ok(str_contains($switch['calendar'],'DTSTART:20260328T080000Z')&&str_contains($switch['calendar'],'DTEND:20260329T070000Z'),'DST boundary keeps local appointment hours');
    ok(str_contains($summer['calendar'],'UID:booking-42@aertsactionbike.cc'),'Stable booking UID');
    $text="T;1, \\\\ bike\\nBEGIN:VEVENT ".str_repeat('é🚲',80);
    $long=booking_mail_message(array_replace($r,['bikes'=>[['code'=>'T-1','name'=>$text]]]));
    foreach(explode("\\r\\n",$long['calendar']) as $line)ok(strlen($line)<=75&&mb_check_encoding($line,'UTF-8'),'Fold max75octets no split UTF8');
    ok(substr_count($long['calendar'],'BEGIN:VEVENT')===2&&!str_contains($long['calendar'],"\\r\\nBEGIN:VEVENT "),'Text cannot inject calendar component');
    $unfolded=str_replace("\\r\\n ",'',$long['calendar']);
    ok(str_contains($unfolded,booking_mail_calendar_text($text)),'Folding preserves all escaped bike text');
    ok(!preg_match('/(?<!\\r)\\n/',$long['calendar']),'CRLF only');
    foreach([['rental_kind'=>'replacement'],['customer_email'=>"bad\\r\\nBcc:other@example.test"],['start_at'=>'2026-03-29 02:30:00'],['end_at'=>'2026-01-01 17:00:00'],['bikes'=>[]]] as $invalid){
      $rejected=false;try{booking_mail_message(array_replace($r,$invalid));}catch(DomainException $e){$rejected=true;}ok($rejected,'Malformed message rejected');}
    $mime=booking_mail_mime($m,booking_mail_sender());
    ok(str_contains($mime['body'],'Content-Type: text/calendar; charset=UTF-8; method=PUBLISH'),'MIME calendar content type');
    ok(str_contains($mime['body'],chunk_split(base64_encode($m['calendar']),76,"\\r\\n")),'MIME preserves calendar bytes');
    echo json_encode(['checks'=>$checks]);`);
  const calendarChecks = JSON.parse(messageChecks.text).checks;
  check(calendarChecks > 40, 'Calendar UTC/DST, UTF8 folding, escaping, MIME and malformed-input checks passed');

  const anonymous = await request('booking-mail.php', { query: { id: 1 } });
  check(anonymous.httpStatusCode === 302 && anonymous.headers.location?.[0] === 'index.php?route=login', 'Popup requires authenticated session');
  const initialFiles = await files();
  for (const quick of [false, true]) {
    for (const kind of ['rental', 'test', 'replacement']) {
      const b = await create(kind, staff, quick);
      const location = b.response.headers.location?.[0];
      check(b.response.httpStatusCode === 302 && (kind === 'replacement' ? !location.includes('booking-mail') : location === `booking-mail.php?id=${b.id}`), `${quick ? 'Quick' : 'Full'} ${kind}: only Huur/Test open the mail decision after saving`);
      if (kind === 'replacement') {
        check(await prompt(b) === null, 'Replacement has no mail prompt'); continue;
      }
      const p = await prompt(b);
      check(p && /^[a-f0-9]{64}$/.test(p.nonce), 'Prompt has a session-scoped random nonce');
      const page = await request('booking-mail.php', { actor: staff, query: { id: b.id } });
      check(page.httpStatusCode === 200 && page.text.includes('<dialog') && page.text.includes('booking-mail.js'), 'Saved booking renders a CSP-safe dialog and external script');
      check(page.text.includes('Overslaan') && page.text.includes('agenda'), 'Customer mail can be sent or skipped');
      check((await files()).length === initialFiles.length, 'GET popup never sends or logs mail');
      const skipped = await post(b, { action: 'skip' });
      const next = !quick && kind === 'rental' ? `contract.php?reservation_id=${b.id}` : `reservation.php?id=${b.id}`;
      check([302,303].includes(skipped.httpStatusCode) && skipped.headers.location?.[0] === next, 'Skipping preserves original contract/dossier continuation');
      check(await prompt(b) === null && (await files()).length === initialFiles.length, 'Skip consumes prompt and creates no mail');
    }
  }

  const b = await create('test');
  const p = await prompt(b);
  const before = (await files()).length;
  for (const tokenData of [{ _token: 'wrong' }, { '_token[]': staff.token, _token: undefined }]) {
    const body = { reservation_id: String(b.id), nonce: p.nonce, action: 'send', recipient: 'customer@example.test', ...tokenData };
    for (const key of Object.keys(body)) if (body[key] === undefined) delete body[key];
    const bad = await request('booking-mail.php', { actor: staff, method: 'POST', query: { id: b.id }, data: body });
    check(bad.httpStatusCode === 419, 'Wrong or array CSRF cannot send');
  }
  await request('booking-mail.php', { actor: other, method: 'POST', query: { id: b.id }, data: { _token: other.token, reservation_id: String(b.id), nonce: p.nonce, action: 'send', recipient: 'customer@example.test' } });
  await post(b, { action: 'send', nonce: 'bad', recipient: 'customer@example.test' });
  const wrongId = await post(b, { action: 'send', reservation_id: String(b.id + 1), recipient: 'customer@example.test' });
  check(wrongId.httpStatusCode === 400, 'Valid CSRF does not permit mismatched posted dossier ID');
  const arrayAction = await post(b, { 'action[]': 'send', recipient: 'customer@example.test' });
  check(arrayAction.httpStatusCode === 400, 'Array action rejected even with valid CSRF and nonce');
  check((await files()).length === before && (await prompt(b))?.nonce === p.nonce, 'Other session and invalid nonce cannot send or consume prompt');

  // Service checks use a fake sender so duplicate claims and uncertain outcomes are deterministic.
  const service = await actorRun(staff, `$checks=0;function ok($x,$label){global $checks;if(!$x)throw new Exception($label);$checks++;}
    $id=${b.id};$p=booking_mail_prompt($id);$calls=0;$fake=function($message)use(&$calls){$calls++;return ['status'=>'sent','error'=>null];};
    $first=booking_mail_attempt($id,$p['nonce'],'customer@example.test',$fake);
    ok($first['status']==='sent'&&$calls===1,'Explicit service send');
    $second=booking_mail_attempt($id,$p['nonce'],'customer@example.test',$fake);
    ok($calls===1,'Duplicate send does not call transport');
    echo json_encode(['checks'=>$checks]);`);
  check(JSON.parse(service.text).checks === 2, 'Successful and duplicate send claim verified with fake transport');
  const schemaAfter = await run("require '/booking/app/bootstrap.php';echo db()->query(\"SELECT COUNT(*) FROM sqlite_master WHERE name='reservation_booking_mail_runs'\")->fetchColumn();");
  check(schemaAfter.text === '1', 'First explicit send creates missing delivery table for existing installations');

  const q = await create('rental', staff, true);
  const quickResult = await actorRun(staff, `$id=${q.id};$p=booking_mail_prompt($id);$calls=0;$fake=function($message)use(&$calls){$calls++;return ['status'=>'sent','error'=>null];};
    try{booking_mail_attempt($id,$p['nonce'],'invalid',$fake);}catch(DomainException $e){}
    if($calls!==0)throw new Exception('Invalid email reached transport');
    $r=booking_mail_attempt($id,$p['nonce'],'quick@example.test',$fake);
    if($r['status']!=='sent'||$calls!==1||find_reservation($id)['customer_email']!=='quick@example.test')throw new Exception('Quick recipient not persisted on explicit send');echo 'ok';`);
  check(quickResult.text === 'ok', 'Quick booking email supplied at popup, validated and stored only for send');

  const logged = await create('test');
  const loggedPrompt = await prompt(logged);
  const loggedBefore = (await files()).length;
  const loggedResponse = await post(logged, { action: 'send', recipient: 'customer@example.test' });
  check([302,303].includes(loggedResponse.httpStatusCode) && loggedResponse.headers.location?.[0] === `reservation.php?id=${logged.id}`, 'Explicit popup send continues after log transport');
  check((await files()).length === loggedBefore + 1 && await prompt(logged) === null, 'Explicit send creates one private test message and consumes prompt');
  await request('booking-mail.php', { actor: staff, method: 'POST', query: { id: logged.id },
    data: { _token: staff.token, reservation_id: String(logged.id), nonce: loggedPrompt.nonce, action: 'send', recipient: 'customer@example.test' } });
  check((await files()).length === loggedBefore + 1, 'Replayed completed POST cannot send a second message');
  const afterLog = await request('reservation.php', { actor: staff, query: { id: logged.id } });
  check(afterLog.text.includes('Testmodus') && afterLog.text.includes('niet naar de klant verstuurd'), 'Test transport is clearly reported as not delivered');

  const uncertain = await create('test');
  const uncertainResult = await actorRun(staff, `$id=${uncertain.id};$p=booking_mail_prompt($id);$calls=0;$fake=function($m)use(&$calls){$calls++;throw new RuntimeException('Simulated provider timeout');};
    $first=booking_mail_attempt($id,$p['nonce'],'customer@example.test',$fake);$second=booking_mail_attempt($id,$p['nonce'],'customer@example.test',$fake);
    if($first['status']!=='uncertain'||$second['status']!=='uncertain'||$calls!==1)throw new Exception('Uncertain send repeated');echo 'ok';`);
  check(uncertainResult.text === 'ok', 'Provider timeout remains uncertain and transport is never called twice');

  const retry = await create('test');
  const retryResult = await actorRun(staff, `$id=${retry.id};$p=booking_mail_prompt($id);$calls=0;
    $fake=function($m)use(&$calls){$calls++;return ['status'=>$calls===1?'failed':'sent','error'=>$calls===1?'Synthetic setup failure':null];};
    $first=booking_mail_attempt($id,$p['nonce'],'customer@example.test',$fake);
    $second=booking_mail_attempt($id,$p['nonce'],'customer@example.test',$fake);
    $third=booking_mail_attempt($id,$p['nonce'],'customer@example.test',$fake);
    if($first['status']!=='failed'||$second['status']!=='sent'||$third['status']!=='sent'||$calls!==2)throw new Exception('Safe setup retry or dedupe failed');echo 'ok';`);
  check(retryResult.text === 'ok', 'Known pre-delivery failure permits one new explicit attempt; completed send remains deduplicated');

  const closed = await create('test');
  const closedPrompt = await prompt(closed);
  await sql(`UPDATE reservations SET status='no_show' WHERE id=${closed.id};`);
  const closedResult = await actorRun(staff, `$calls=0;
    try{booking_mail_attempt(${closed.id},'${closedPrompt.nonce}','customer@example.test',function($m)use(&$calls){$calls++;return ['status'=>'sent','error'=>null];});}catch(DomainException $e){}
    if($calls!==0)throw new Exception('No-show appointment emailed');echo 'ok';`);
  check(closedResult.text === 'ok', 'Booking closed as no-show cannot send an appointment');

  const f = await create('test', finance);
  const financePage = await request('booking-mail.php', { actor: finance, query: { id: f.id } });
  check(financePage.httpStatusCode === 200, 'Finance can use popup for own new booking');
  const financeSkip = await post(f, { action: 'skip' });
  check(financeSkip.headers.location?.[0] === `reservation.php?id=${f.id}`, 'Finance continues to permitted dossier');

  const changed = await create('test');
  await sql(`UPDATE reservations SET end_at='2099-12-31 17:00:00' WHERE id=${changed.id};`);
  const stale = await actorRun(staff, `$p=booking_mail_prompt(${changed.id});$calls=0;
    try{booking_mail_attempt(${changed.id},$p['nonce'],'customer@example.test',function($m)use(&$calls){$calls++;return ['status'=>'sent','error'=>null];});}catch(DomainException $e){}
    if($calls!==0)throw new Exception('Stale booking emailed');echo booking_mail_finish_prompt(${changed.id},$p['nonce']);`);
  check(stale.text === `reservation.php?id=${changed.id}`, 'Changed booking cannot send stale appointment; skip still works');
  await sql(`UPDATE reservations SET status='cancelled' WHERE id=${changed.id};`);

  const revoked = await create('test');
  const revokedPrompt = await prompt(revoked);
  await sql('UPDATE users SET active=0 WHERE id=1;');
  const revokedResult = await actorRun(staff, `$calls=0;
    try{booking_mail_attempt(${revoked.id},'${revokedPrompt.nonce}','customer@example.test',function($m)use(&$calls){$calls++;return ['status'=>'sent','error'=>null];});}catch(DomainException $e){}
    if($calls!==0)throw new Exception('Inactive account sent');echo 'ok';`);
  check(revokedResult.text === 'ok', 'Fresh user state prevents sending from revoked session');
  await sql('UPDATE users SET active=1 WHERE id=1;');

  const tabs = await actorRun(staff, `$_SESSION['booking_mail_prompts']=[];$ids=[];
    $customer=(int)db()->query('SELECT MIN(id) FROM customers')->fetchColumn();
    $insert=db()->prepare("INSERT INTO reservations(bike_id,customer_id,start_at,end_at,status,rental_kind,created_by) VALUES(1,?,'2099-12-01 09:00:00','2099-12-02 17:00:00','confirmed','test',1)");
    for($i=0;$i<21;$i++){$insert->execute([$customer]);$id=(int)db()->lastInsertId();$ids[]=$id;
      db()->prepare('INSERT INTO reservation_bikes(reservation_id,bike_id) VALUES(?,1)')->execute([$id]);booking_mail_start_prompt($id,'reservation.php?id='.$id);}
    if(booking_mail_prompt($ids[0])!==null)throw new Exception('Oldest prompt not bounded');
    foreach(array_slice($ids,1) as $id)if(booking_mail_prompt($id)===null)throw new Exception('Other booking tab lost its prompt');
    $_SESSION['booking_mail_prompts'][$ids[1]]['created_at']=time()-86401;
    if(booking_mail_prompt($ids[1])!==null||booking_mail_prompt($ids[2])===null)throw new Exception('Expired prompt affected a different booking tab');echo 'ok';`);
  check(tabs.text === 'ok', 'Opening many booking tabs preserves each surviving dossier choice');

  await sql("UPDATE bikes SET name=CAST(X'80' AS TEXT) WHERE id=1;");
  const fallback = await create('test');
  check(fallback.response.headers.location?.[0] === `reservation.php?id=${fallback.id}`, 'Prompt preparation failure continues safely to saved dossier');
  const savedFallback = await run(`require '/booking/app/bootstrap.php';echo db()->query('SELECT COUNT(*) FROM reservations WHERE id=${fallback.id}')->fetchColumn();`);
  check(savedFallback.text === '1', 'Post-commit mail prompt failure preserves booking');
  await sql("UPDATE bikes SET name='Synthetic bike' WHERE id=1;");

  console.log(`PASS: ${checks} optional booking-mail route/service checks and ${calendarChecks} calendar/message checks using synthetic data and fake/logged transports.`);
})().catch(e => { console.error(e); process.exitCode = 1; }).finally(() => {
  try { php?.exit(); } catch {} process.exit(process.exitCode || 0);
});
