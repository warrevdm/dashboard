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
 php.writeFile('/overdue.php',fs.readFileSync(path.resolve(__dirname,'../app/overdue_rentals.php')));
 php.writeFile('/schema.sql',fs.readFileSync(path.resolve(__dirname,'../database/schema.sql')));
 const r=await php.run({code:`<?php
 require '/overdue.php';
 function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
 $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $p->exec(file_get_contents('/schema.sql'));
 $p->exec("INSERT INTO customers(id,name,phone) VALUES(1,'<Fixture>','+32 123');
 INSERT INTO bikes(id,code,name,category) VALUES(1,'A','Bike A','City'),(2,'B','Bike B','City');
 INSERT INTO reservations(id,bike_id,customer_id,start_at,end_at,status) VALUES
 (1,1,1,'2026-10-08 09:00:00','2026-10-09 10:00:00','picked_up'),
 (2,1,1,'2026-10-09 14:00:00','2026-10-10 10:00:00','confirmed'),
 (3,2,1,'2026-10-08 09:00:00','2026-10-09 09:00:00','returned'),
 (4,2,1,'2026-10-08 09:00:00','2026-10-09 09:00:00','cancelled'),
 (5,2,1,'2026-10-09 09:00:00','2026-10-09 12:00:00','picked_up'),
 (6,1,1,'2026-10-09 11:00:00','2026-10-10 10:00:00','cancelled');
 INSERT INTO reservation_bikes(reservation_id,bike_id) VALUES(1,1),(1,2),(2,1),(3,2),(4,2),(5,2),(6,1);");
 $checks=0;function ok($x){global $checks;if(!$x)throw new Exception('Check '.($checks+1));$checks++;}
 $now=new DateTimeImmutable('2026-10-09 10:00:00',new DateTimeZone('UTC'));
 $rows=overdue_rentals($p,$now);
 ok(count($rows)===1);ok(count($rows[0]['bikes'])===2);
 ok((int)$rows[0]['bikes'][0]['next_id']===2);ok($rows[0]['bikes'][1]['next_id']===null);
 ok(overdue_rentals($p,$now,2)===[]);ok(count(overdue_rentals($p,$now,1))===1);
 ob_start();render_overdue_rentals($rows);$html=ob_get_clean();
 ok(str_contains($html,'&lt;Fixture&gt;'));ok(str_contains($html,'tel:+32123'));ok(str_contains($html,'reservation.php?id=2'));
 $p->exec("UPDATE reservations SET status='returned' WHERE id=1");ok(overdue_rentals($p,$now)===[]);
 ob_start();render_overdue_rentals([]);ok(ob_get_clean()==='');
 echo "PASS $checks overdue checks.";
 `});
 assert.equal(r.exitCode,0,r.text+' '+r.errors);assert.equal(r.errors,'');console.log(r.text);
 }finally{php.exit();}
})().catch(e=>{console.error(e);process.exitCode=1;});
