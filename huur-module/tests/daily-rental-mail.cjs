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
 php.writeFile('/daily.php',fs.readFileSync(path.resolve(__dirname,'../app/daily_rental_mail.php')));
 const r = await php.run({code:`<?php
 require '/daily.php';
 $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $p->exec("CREATE TABLE reservations(id INTEGER,customer_id INTEGER,start_at TEXT,end_at TEXT,rental_kind TEXT,status TEXT); CREATE TABLE customers(id INTEGER,name TEXT); CREATE TABLE bikes(id INTEGER,code TEXT,name TEXT); CREATE TABLE reservation_bikes(reservation_id INTEGER,bike_id INTEGER,returned_at TEXT);
 INSERT INTO customers VALUES(1,'<Test & klant>'); INSERT INTO bikes VALUES(1,'A','Bike A'),(2,'B','Bike B');
 INSERT INTO reservations VALUES(1,1,'2026-10-05 10:00:00','2026-10-06 16:00:00','replacement','picked_up'),(2,1,'2026-10-05 10:00:00','2026-10-06 16:00:00','rental','returned'); INSERT INTO reservation_bikes(reservation_id,bike_id) VALUES(1,1),(1,2),(2,1);");
 $checks=0; function ok($x){global $checks;if(!$x)throw new Exception('Check '.($checks+1).' failed');$checks++;}
 $at=fn($s)=>new DateTimeImmutable($s,new DateTimeZone('UTC'));
 $sent=[];$sender=function($to,$m)use(&$sent){$sent[]=$to;};
 $m=daily_rental_message($p,$at('2026-10-06 15:00:00'));
 ok(str_contains($m['html'],'1 dossiers · 2 fietsen'));ok(str_contains($m['html'],'&lt;Test &amp; klant&gt;'));ok(str_contains($m['plain'],'TE LAAT TERUG'));ok(str_contains($m['plain'],'Vervang'));
 run_daily_rental_mail($p,$at('2026-10-06 14:59:00'),$sender);ok(count($sent)===0);
 run_daily_rental_mail($p,$at('2026-10-06 15:00:00'),$sender);ok($sent===['werkplaats@aertsactionbike.be','marketing@aertsactionbike.be','verkoop@aertsactionbike.be']);
 run_daily_rental_mail($p,$at('2026-10-06 20:00:00'),$sender);ok(count($sent)===3);
 run_daily_rental_mail($p,$at('2026-12-06 15:59:00'),$sender);ok(count($sent)===3);
 run_daily_rental_mail($p,$at('2026-12-06 16:00:00'),$sender);ok(count($sent)===6);
 $p->exec("UPDATE reservations SET status='returned'");ok(str_contains(daily_rental_message($p,$at('2026-12-07 16:00:00'))['html'],'geen verhuren onderweg'));
 $calls=0;$failure=function($to,$m)use(&$calls){$calls++;if($calls===1)throw new Exception('fixture');};
 try{run_daily_rental_mail($p,$at('2026-12-07 16:00:00'),$failure);}catch(RuntimeException $e){}ok($calls===3);
 try{run_daily_rental_mail($p,$at('2026-12-07 16:05:00'),$failure);}catch(RuntimeException $e){}ok($calls===3);
 ok($p->query("SELECT status FROM daily_rental_mail_runs WHERE day='2026-12-07' AND recipient='marketing@aertsactionbike.be'")->fetchColumn()==='sent');
 $manual=str_repeat('b',64);
 $count=count($sent);
 run_daily_rental_mail($p,$at('2026-12-08 10:00:00'),$sender,$manual);ok(count($sent)===$count+3);
 run_daily_rental_mail($p,$at('2026-12-08 10:00:00'),$sender,$manual);ok(count($sent)===$count+3);
 run_daily_rental_mail($p,$at('2026-12-08 16:00:00'),$sender);ok(count($sent)===$count+6);
 run_daily_rental_mail($p,$at('2026-12-08 16:01:00'),$sender,str_repeat('c',64));ok(count($sent)===$count+9);
 echo "PASS: $checks daily mail checks; synthetic data and fake sender only.";
 `});
 assert.equal(r.exitCode,0,r.text+' '+r.errors);assert.match(r.text,/PASS: 17/);console.log(r.text);
 php.mkdirTree('/web/app'); php.mkdirTree('/web/public');
 for (const name of ['env.php','daily_rental_mail.php']) php.writeFile('/web/app/'+name,fs.readFileSync(path.resolve(__dirname,'../app/'+name)));
 php.writeFile('/web/public/daily-rental-mail.php',fs.readFileSync(path.resolve(__dirname,'../public/daily-rental-mail.php')));
 const key='a'.repeat(64);
 async function request(query,extra={}) {
   return php.run({scriptPath:'/web/public/daily-rental-mail.php',method:'GET',relativeUri:'/daily-rental-mail.php'+query,
     $_SERVER:{HTTPS:'on'},env:{DAILY_RENTAL_CRON_KEY:key,DAILY_RENTAL_MAIL_ENABLED:'0'},...extra});
 }
 for (const query of ['', '?key=wrong', '?key[]=invalid']) {
   const result=await request(query);assert.equal(result.httpStatusCode,403);assert.equal(result.text,'Geen toegang.');
 }
 const valid=await request('?key='+key);assert.equal(valid.httpStatusCode,200);assert.equal(valid.text,'Dagmail uitgeschakeld.');
 assert.ok(JSON.stringify(valid.headers).includes('no-store'));
 const empty=await request('?key=',{env:{DAILY_RENTAL_CRON_KEY:'',DAILY_RENTAL_MAIL_ENABLED:'0'}});assert.equal(empty.httpStatusCode,403);
 const http=await php.run({code:"<?php $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['HTTPS']='off'; unset($_SERVER['HTTP_X_FORWARDED_PROTO']); require '/web/public/daily-rental-mail.php';"});assert.equal(http.httpStatusCode,403);
 const head=await request('?key='+key,{method:'HEAD'});assert.equal(head.httpStatusCode,405);
 console.log('PASS: web cron rejects absent, wrong, array and empty keys, HTTP and HEAD; valid key works without DB/session.');
 } finally {php.exit();}
})().catch(e=>{console.error(e);process.exitCode=1;});
