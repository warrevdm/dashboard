// Isolated PHP routes and synthetic data only; no production requests.
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
  php = new PHP(await loadNodeRuntime('8.3', {fileLockManager:new FileLockManagerInMemory(),emscriptenOptions:{processId:process.pid}}));
  for (const dir of ['app','public','sessions','storage/private/bikes']) php.mkdirTree('/test/'+dir);
  for (const file of fs.readdirSync(root+'/app').filter(f=>f.endsWith('.php'))) php.writeFile('/test/app/'+file,fs.readFileSync(root+'/app/'+file));
  for (const file of ['planning.php','bike-photo.php','api-bike-availability.php']) php.writeFile('/test/public/'+file,fs.readFileSync(root+'/public/'+file));
  php.writeFile('/test/schema.sql',fs.readFileSync(root+'/database/schema.sql'));
  let checks=0;
  function check(value,label){assert.ok(value,label);checks++;}
  async function run(code,opts={}) {
    const r=await php.run({code:"<?php ini_set('session.save_path','/test/sessions');"+code,env:{DB_PATH:'/test/storage/test.sqlite',APP_ENV:'test',APP_DEBUG:'1'},...opts});
    assert.equal(r.errors,'');assert.equal(r.exitCode,0,r.text);return r;
  }
  await run(`require '/test/app/bootstrap.php'; db()->exec(file_get_contents('/test/schema.sql'));
    db()->exec("INSERT INTO bikes(id,code,name,category) VALUES(1,'A','Bike A','City'),(2,'B','Bike B','City');
    INSERT INTO customers(id,name) VALUES(1,'Fixture customer');
    INSERT INTO reservations(id,bike_id,customer_id,start_at,end_at,status,total_price) VALUES
    (1,1,1,'2026-09-24 09:00:00','2026-09-25 17:00:00','confirmed',100),
    (2,2,1,'2026-09-26 09:00:00','2026-09-27 17:00:00','returned',0),
    (3,1,1,'2026-09-26 09:00:00','2026-09-27 17:00:00','cancelled',50),
    (4,1,1,'2020-01-01','2020-01-02','returned',100);
    INSERT INTO reservation_bikes(reservation_id,bike_id) VALUES(1,1),(1,2),(2,2),(3,1),(4,1);
    INSERT INTO payment_logs(reservation_id,amount,method) VALUES(1,25,'cash'),(1,30,'cash'),(3,10,'cash');
    WITH RECURSIVE n(x) AS (VALUES(1) UNION ALL SELECT x+1 FROM n WHERE x<10000)
    INSERT INTO payment_logs(reservation_id,amount,method) SELECT 4,1,'cash' FROM n;");`);
  const result=JSON.parse((await run(`require '/test/app/bootstrap.php';
    $rows=reservations_for_range(new DateTimeImmutable('2026-09-24'),new DateTimeImmutable('2026-10-08'));
    $start=microtime(true); for($i=0;$i<20;$i++) reservations_for_range(new DateTimeImmutable('2026-09-24'),new DateTimeImmutable('2026-10-08'));
    $newMs=(microtime(true)-$start)*1000;
    $sql="SELECT r.id, rb.bike_id, COALESCE(p.paid_amount,0) paid_amount FROM reservations r JOIN reservation_bikes rb ON rb.reservation_id=r.id LEFT JOIN (SELECT reservation_id,SUM(amount) paid_amount FROM payment_logs GROUP BY reservation_id) p ON p.reservation_id=r.id WHERE r.start_at<'2026-10-08 00:00:00' AND r.end_at>'2026-09-24 00:00:00' AND r.status!='cancelled' ORDER BY rb.bike_id,r.start_at";
    $old=db()->query($sql)->fetchAll();$start=microtime(true);for($i=0;$i<20;$i++) db()->query($sql)->fetchAll();$oldMs=(microtime(true)-$start)*1000;
    echo json_encode(['rows'=>$rows,'old'=>$old,'new_ms'=>$newMs,'old_ms'=>$oldMs,
      'plan'=>db()->query('EXPLAIN QUERY PLAN SELECT SUM(amount) FROM payment_logs WHERE reservation_id=1')->fetchAll()]);`)).text);
  assert.deepEqual(result.rows.map(r=>({id:r.id,bike_id:r.bike_id,paid_amount:r.paid_amount})),result.old);checks++;
  check(result.rows.length===3 && result.rows[0].paid_amount===55 && result.rows[2].paid_amount===0,'Multi-bike payments and unpaid/returned records preserved; cancelled excluded');
  check(JSON.stringify(result.plan).includes('idx_payment_logs_reservation'),'Payment lookup uses the existing reservation index');
  const session=await run("require '/test/app/bootstrap.php'; $_SESSION['user']=['id'=>1,'role'=>'admin','name'=>'Fixture','email'=>'test@example.test']; $_SESSION['flash']=[['type'=>'success','message'=>'Fixture flash']];");
  const cookie=session.headers['set-cookie'][0].split(';')[0];
  const opts={method:'GET',relativeUri:'/huur-module/planning.php?start=2026-09-24',$_SERVER:{SCRIPT_NAME:'/huur-module/planning.php'},headers:{Cookie:cookie}};
  const page=await run("require '/test/public/planning.php';",opts);
  check(page.httpStatusCode===200,'Planning loads');
  check((page.text.match(/<script src=/g)||[]).length===1 && page.text.includes('planning-hover.js'),'Planning loads only its tooltip script');
  check((page.text.match(/rel="stylesheet"/g)||[]).length===3,'Planning loads three required stylesheets');
  check(page.text.includes('Fixture customer') && page.text.includes('Fixture flash'),'Bookings and flash render');
  check(page.headers['server-timing'][0].includes('bootstrap;dur=') && page.headers['server-timing'][0].includes('data;dur='),'Real planning phase timings exposed');
  check(page.headers['cache-control'][0].includes('no-store'),'Customer planning is not cached');
  const saved=JSON.parse((await run("require '/test/app/bootstrap.php'; echo json_encode([$_SESSION['csrf_token']??null,$_SESSION['flash']??null]);",opts)).text);
  check(saved[0]?.length===64 && saved[1]===null,'Logout token persists and flash is consumed');
  const unlocked=await run("register_shutdown_function(function(){echo session_status()===PHP_SESSION_ACTIVE?'LOCKED':'UNLOCKED';}); require '/test/public/planning.php';",opts);
  check(unlocked.text.endsWith('UNLOCKED'),'Planning releases session');
  const anon=await run("require '/test/public/planning.php';",{method:'GET',$_SERVER:{SCRIPT_NAME:'/huur-module/planning.php'}});
  check(anon.httpStatusCode===302 && anon.text==='', 'Anonymous planning remains protected');
  const photo=await run("register_shutdown_function(function(){echo session_status()===PHP_SESSION_ACTIVE?'LOCKED':'UNLOCKED';}); require '/test/public/bike-photo.php';",{...opts,relativeUri:'/huur-module/bike-photo.php?id=1',$_SERVER:{SCRIPT_NAME:'/huur-module/bike-photo.php'}});
  check(photo.httpStatusCode===404 && photo.text.endsWith('UNLOCKED'),'Photo lookup releases session even without an image');
  const apiOpts={...opts,relativeUri:'/huur-module/api-bike-availability.php?start_date=2026-09-24&start_time=09%3A00&end_date=2026-09-25&end_time=17%3A00',$_SERVER:{SCRIPT_NAME:'/huur-module/api-bike-availability.php'}};
  const api=await run("register_shutdown_function(function(){if(session_status()===PHP_SESSION_ACTIVE)throw new RuntimeException('Session remains locked');}); require '/test/public/api-bike-availability.php';",apiOpts);
  const availability=JSON.parse(api.text);
  check(api.httpStatusCode===200 && availability.ok && availability.items.length===2 && availability.items.every(b=>!b.available),'API releases session and preserves multi-bike availability');
  const apiAnon=await run("require '/test/public/api-bike-availability.php';",{...apiOpts,headers:{}});
  check(apiAnon.httpStatusCode===302 && apiAnon.text==='', 'Availability API still requires authentication');
  php.writeFile('/test/send-source.php',fs.readFileSync(root+'/../mailing-system/send.php'));
  await run("token_get_all(file_get_contents('/test/send-source.php'), TOKEN_PARSE);");
  const defaultPage=await run("require '/test/app/bootstrap.php'; render_header('Default'); render_footer();",opts);
  check((defaultPage.text.match(/<script src=/g)||[]).length===8 && (defaultPage.text.match(/rel="stylesheet"/g)||[]).length===9,'Other pages retain all existing assets');
  console.log(`PASS ${checks} planning checks. Synthetic 20-query totals: scoped lookup ${result.new_ms.toFixed(1)} ms; previous all-payments aggregate ${result.old_ms.toFixed(1)} ms. Not a production benchmark.`);
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{try{php?.exit();}catch{}process.exit(process.exitCode||0);});
