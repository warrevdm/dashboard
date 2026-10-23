const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {createRequire} = require('node:module');
const req = createRequire(path.resolve(__dirname,'../../lease/tests/package.json'));
const {PHP,FileLockManagerInMemory} = req('@php-wasm/universal');
const {loadNodeRuntime} = req('@php-wasm/node');
(async()=>{
 const php = new PHP(await loadNodeRuntime('8.3',{fileLockManager:new FileLockManagerInMemory(),emscriptenOptions:{processId:process.pid}}));
 try {
 const root=path.resolve(__dirname,'..');
 for(const d of ['app','public/assets','storage','sessions']) php.mkdirTree('/test/'+d);
 for(const f of fs.readdirSync(root+'/app').filter(f=>f.endsWith('.php'))) php.writeFile('/test/app/'+f,fs.readFileSync(root+'/app/'+f));
 for(const f of ['workshop.php','assets/workshop.css','assets/workshop.js']) php.writeFile('/test/public/'+f,fs.readFileSync(root+'/public/'+f));
 php.writeFile('/test/schema.sql',fs.readFileSync(root+'/database/schema.sql'));
 async function run(code,opts={}){
  const r=await php.run({code:"<?php ini_set('session.save_path','/test/sessions');"+code,env:{DB_PATH:'/test/storage/test.sqlite',APP_ENV:'test',APP_DEBUG:'1'},...opts});
  assert.equal(r.exitCode,0,r.text+r.errors);assert.equal(r.errors,'');return r;
 }
 await run(`require '/test/app/bootstrap.php'; db()->exec(file_get_contents('/test/schema.sql'));
 db()->exec("INSERT INTO users(id,name,email,password_hash,role,active) VALUES(1,'Staff','staff@example.test','x','staff',1);
 INSERT INTO customers(id,name) VALUES(1,'<Fixture>');
 INSERT INTO bikes(id,code,name,category) VALUES(1,'A','Bike A','City'),(2,'B','Bike B','City');
 INSERT INTO reservations(id,bike_id,customer_id,start_at,end_at,status) VALUES
 (1,1,1,'2026-10-09 09:00:00','2026-10-09 15:00:00','confirmed'),
 (2,1,1,'2026-10-08 09:00:00','2026-10-09 10:00:00','picked_up'),
 (3,2,1,'2026-10-08 09:00:00','2026-10-09 09:00:00','returned'),
 (4,2,1,'2026-10-09 09:00:00','2026-10-09 16:00:00','cancelled'),
 (5,2,1,'2026-10-08 09:00:00','2026-10-09 12:00:00','picked_up'),
 (6,2,1,'2026-10-09 23:30:00','2026-10-10 12:00:00','reserved');
 INSERT INTO reservation_bikes(reservation_id,bike_id) VALUES(1,1),(1,2),(2,1),(3,2),(4,2),(5,2),(6,2);");`);
 const data=JSON.parse((await run(`require '/test/app/bootstrap.php'; require '/test/app/workshop_board.php'; echo json_encode(workshop_board_data(db(),new DateTimeImmutable('2026-10-09 10:00:00',new DateTimeZone('UTC'))));`)).text);
 assert.deepEqual(data.pickups.map(x=>x.id),[1,6]);assert.deepEqual(data.returns.map(x=>x.id),[5,1]);assert.deepEqual(data.overdue.map(x=>x.id),[2]);assert.equal(data.pickups[0].bikes.length,2);
 const session=await run("require '/test/app/bootstrap.php'; $_SESSION['user']=['id'=>1,'role'=>'staff','name'=>'Staff'];");
 const opts={method:'GET',relativeUri:'/huur-module/workshop.php',$_SERVER:{SCRIPT_NAME:'/huur-module/workshop.php'},headers:{Cookie:session.headers['set-cookie'][0].split(';')[0]}};
 const page=await run("require '/test/public/workshop.php';",opts);assert.equal(page.httpStatusCode,200);assert.ok(page.text.includes('Vandaag onderweg'));assert.ok(page.text.includes('&lt;Fixture&gt;'));assert.ok(page.headers['cache-control'][0].includes('no-store'));
 const refresh={...opts,relativeUri:'/huur-module/workshop.php?refresh=1'};
 const json=await run("require '/test/public/workshop.php';",refresh);assert.ok(JSON.parse(json.text).html.includes('board-column'));
 await run("require '/test/app/bootstrap.php'; db()->exec(\"UPDATE users SET active=0 WHERE id=1\");");
 const revoked=await run("require '/test/public/workshop.php';",refresh);assert.equal(revoked.httpStatusCode,403);assert.ok(!revoked.text.includes('Fixture'));
 await run("require '/test/app/bootstrap.php'; db()->exec(\"UPDATE users SET active=1,role='finance' WHERE id=1\");");
 assert.equal((await run("require '/test/public/workshop.php';",refresh)).httpStatusCode,403);
 const anon=await run("require '/test/public/workshop.php';",{...refresh,headers:{}});assert.equal(anon.httpStatusCode,403);
 console.log('PASS workshop grouping, day boundaries, multi-bike dossiers, escaped output, HTML/JSON routes, no-cache and revoked/finance/anonymous access.');
 }finally{php.exit();}
})().catch(e=>{console.error(e);process.exitCode=1;});
