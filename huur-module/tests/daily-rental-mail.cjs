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
 $p->exec("CREATE TABLE reservations(id INTEGER,customer_id INTEGER,start_at TEXT,end_at TEXT,rental_kind TEXT,status TEXT); CREATE TABLE customers(id INTEGER,name TEXT); CREATE TABLE bikes(id INTEGER,code TEXT,name TEXT); CREATE TABLE reservation_bikes(reservation_id INTEGER,bike_id INTEGER);
 INSERT INTO customers VALUES(1,'<Test & klant>'); INSERT INTO bikes VALUES(1,'A','Bike A'),(2,'B','Bike B');
 INSERT INTO reservations VALUES(1,1,'2026-10-05 10:00:00','2026-10-06 16:00:00','replacement','picked_up'),(2,1,'2026-10-05 10:00:00','2026-10-06 16:00:00','rental','returned'); INSERT INTO reservation_bikes VALUES(1,1),(1,2),(2,1);");
 $checks=0; function ok($x){global $checks;if(!$x)throw new Exception('Check '.($checks+1).' failed');$checks++;}
 $at=fn($s)=>new DateTimeImmutable($s,new DateTimeZone('UTC'));
 $sent=[];$sender=function($to,$m)use(&$sent){$sent[]=$to;};
 $m=daily_rental_message($p,$at('2026-10-06 15:00:00'));
 ok(str_contains($m['html'],'1 dossiers · 2 fietsen'));ok(str_contains($m['html'],'&lt;Test &amp; klant&gt;'));ok(str_contains($m['plain'],'TE LAAT TERUG'));ok(str_contains($m['plain'],'Vervang'));
 run_daily_rental_mail($p,$at('2026-10-06 14:59:00'),$sender);ok(count($sent)===0);
 run_daily_rental_mail($p,$at('2026-10-06 15:00:00'),$sender);ok($sent===['werkplaats@aertsactionbike.be','marketing@aertsactionbike.be']);
 run_daily_rental_mail($p,$at('2026-10-06 20:00:00'),$sender);ok(count($sent)===2);
 run_daily_rental_mail($p,$at('2026-12-06 15:59:00'),$sender);ok(count($sent)===2);
 run_daily_rental_mail($p,$at('2026-12-06 16:00:00'),$sender);ok(count($sent)===4);
 $p->exec("UPDATE reservations SET status='returned'");ok(str_contains(daily_rental_message($p,$at('2026-12-07 16:00:00'))['html'],'geen verhuren onderweg'));
 $calls=0;$failure=function($to,$m)use(&$calls){$calls++;if($calls===1)throw new Exception('fixture');};
 try{run_daily_rental_mail($p,$at('2026-12-07 16:00:00'),$failure);}catch(RuntimeException $e){}ok($calls===2);
 try{run_daily_rental_mail($p,$at('2026-12-07 16:05:00'),$failure);}catch(RuntimeException $e){}ok($calls===2);
 ok($p->query("SELECT status FROM daily_rental_mail_runs WHERE day='2026-12-07' AND recipient='marketing@aertsactionbike.be'")->fetchColumn()==='sent');
 echo "PASS: $checks daily mail checks; synthetic data and fake sender only.";
 `});
 assert.equal(r.exitCode,0,r.text+' '+r.errors);assert.match(r.text,/PASS: 13/);console.log(r.text);
 } finally {php.exit();}
})().catch(e=>{console.error(e);process.exitCode=1;});
