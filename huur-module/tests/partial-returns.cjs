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

 const result = await run(`require '/test/app/bootstrap.php'; require '/test/app/bike_returns.php'; require '/test/app/workshop_board.php'; require '/test/app/overdue_rentals.php'; require '/test/app/daily_rental_mail.php';
 $_SESSION['user']=['id'=>1,'role'=>'staff','name'=>'Staff'];
 $checks=0; function ok($v){global $checks;if(!$v)throw new Exception('Check '.($checks+1));$checks++;}
 $now=new DateTimeImmutable('now',new DateTimeZone('Europe/Brussels'));
 $start=$now->modify('-1 day')->format('Y-m-d H:i:s');$end=$now->modify('+1 day')->format('Y-m-d H:i:s');
 db()->exec("UPDATE reservations SET status='cancelled' WHERE id!=1");
 $q=db()->prepare("UPDATE reservations SET status='picked_up', start_at=?,end_at=?,total_price=100 WHERE id=1");$q->execute([$start,$end]);
 ok(reservation_conflicts(1,$now->format('Y-m-d H:i:s'),$end));
 return_reservation_bike(1,1);
 $r=find_reservation(1);ok($r['status']==='picked_up');ok(!empty($r['bikes'][0]['returned_at']));ok((float)$r['total_price']===100.0);
 ok(!reservation_conflicts(1,$now->modify('+2 seconds')->format('Y-m-d H:i:s'),$end));
 ok(reservation_conflicts(2,$now->format('Y-m-d H:i:s'),$end));
 $availability=bike_availability($now->modify('+2 seconds')->format('Y-m-d H:i:s'),$end);
 ok($availability[1]['available'] && !$availability[2]['available']);
 $mail=daily_rental_message(db(),$now);ok(!str_contains($mail['plain'],'Bike A') && str_contains($mail['plain'],'Bike B'));
 $range=reservations_for_range($now->modify('-2 days'),$now->modify('+2 days'));
 ok($range[0]['status']==='returned' && $range[0]['end_at']!==$end);
 $q=db()->prepare('UPDATE reservations SET end_at=? WHERE id=1');$q->execute([$now->modify('-1 hour')->format('Y-m-d H:i:s')]);
 $late=overdue_rentals(db(),$now);ok(count($late[0]['bikes'])===1 && (int)$late[0]['bikes'][0]['bike_id']===2);
 $board=workshop_board_data(db(),$now);ok(count($board['overdue'][0]['bikes'])===1);
 return_reservation_bike(1,1);ok(find_reservation(1)['status']==='picked_up');
 try{return_reservation_bike(1,999);ok(false);}catch(DomainException $e){ok(true);}
 db()->exec("UPDATE users SET role='finance' WHERE id=1");
 try{return_reservation_bike(1,2);ok(false);}catch(DomainException $e){ok(true);}
 db()->exec("UPDATE users SET role='staff' WHERE id=1");
 return_reservation_bike(1,2);$r=find_reservation(1);ok($r['status']==='returned' && !empty($r['closed_at']));ok(overdue_rentals(db(),$now)===[]);
 ok((int)db()->query("SELECT COUNT(*) FROM audit_logs WHERE action='return_bike'")->fetchColumn()===2);
 $legacy=new PDO('sqlite::memory:');$legacy->exec('CREATE TABLE reservation_bikes(reservation_id INTEGER,bike_id INTEGER,daily_rate REAL); INSERT INTO reservation_bikes VALUES(1,2,30)');
 ensure_bike_return_schema($legacy);ensure_bike_return_schema($legacy);ok($legacy->query('SELECT returned_at FROM reservation_bikes')->fetchColumn()===null);ok((int)$legacy->query('SELECT COUNT(*) FROM reservation_bikes')->fetchColumn()===1);
 echo "PASS $checks partial return checks.";
 `);console.log(result.text);
 }finally{php.exit();}
})().catch(e=>{console.error(e);process.exitCode=1;});
