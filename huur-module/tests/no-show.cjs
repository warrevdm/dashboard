// Isolated synthetic rental data; no live database or messages.
const assert=require('node:assert/strict');const fs=require('node:fs');const path=require('node:path');const {createRequire}=require('node:module');
const req=createRequire(path.resolve(__dirname,'../../lease/tests/package.json'));
const {PHP,FileLockManagerInMemory}=req('@php-wasm/universal');const {loadNodeRuntime}=req('@php-wasm/node');let php;
(async()=>{
 php=new PHP(await loadNodeRuntime('8.3',{fileLockManager:new FileLockManagerInMemory(),emscriptenOptions:{processId:process.pid}}));
 const root=path.resolve(__dirname,'..');for(const d of ['app','public/assets','storage','sessions'])php.mkdirTree('/test/'+d);
 for(const f of fs.readdirSync(root+'/app').filter(f=>f.endsWith('.php')))php.writeFile('/test/app/'+f,fs.readFileSync(root+'/app/'+f));
 for(const f of fs.readdirSync(root+'/public').filter(f=>f.endsWith('.php')))php.writeFile('/test/public/'+f,fs.readFileSync(root+'/public/'+f));
 for(const f of fs.readdirSync(root+'/public/assets').filter(f=>fs.statSync(root+'/public/assets/'+f).isFile()))php.writeFile('/test/public/assets/'+f,fs.readFileSync(root+'/public/assets/'+f));
 php.writeFile('/test/schema.sql',fs.readFileSync(root+'/database/schema.sql'));
 async function run(code,opts={}){const r=await php.run({code:"<?php ini_set('session.save_path','/test/sessions');"+code,env:{DB_PATH:'/test/storage/test.sqlite',APP_ENV:'test',APP_DEBUG:'1',MAIL_TRANSPORT:'log'},...opts});assert.equal(r.exitCode,0,r.text+r.errors);assert.equal(r.errors,'');return r;}
 await run(`require '/test/app/bootstrap.php';db()->exec(file_get_contents('/test/schema.sql'));
 db()->exec("INSERT INTO users(id,name,email,password_hash,role,active) VALUES(1,'Fixture staff','staff@example.test','x','staff',1),(2,'Finance','finance@example.test','x','finance',1);
 INSERT INTO customers(id,name,email) VALUES(1,'Fixture customer','customer@example.test');
 INSERT INTO bikes(id,code,name,category) VALUES(1,'A','Bike A','City'),(2,'B','Bike B','City');
 INSERT INTO reservations(id,bike_id,customer_id,start_at,end_at,status,rental_kind,total_price,created_by) VALUES(1,1,1,'2000-01-01 09:00:00','2099-12-31 17:00:00','confirmed','rental',100,1),(2,1,1,'2000-01-01 09:00:00','2099-12-31 17:00:00','cancelled','test',0,1);
 INSERT INTO reservation_bikes(reservation_id,bike_id) VALUES(1,1),(1,2),(2,1);
 INSERT INTO payment_logs(reservation_id,amount,method,recorded_by) VALUES(1,25,'cash',1);
 INSERT INTO rental_contracts(id,reservation_id,contract_number,public_token_hash,public_token_expires_at,contract_html,contract_hash) VALUES(1,1,'FIXTURE','old-token','2099-12-31','<p>Fixture</p>','hash');");`);
 const results=await run(`require '/test/app/bootstrap.php';$_SESSION['user']=['id'=>1,'role'=>'staff'];$checks=0;function ok($v){global $checks;if(!$v)throw new Exception('No-show check '.($checks+1));$checks++;}
 $r=find_reservation(1);ok(reservation_can_mark_no_show($r));ok(!reservation_can_mark_no_show(array_replace($r,['start_at'=>'2099-01-01'])));
 ok(!reservation_can_mark_no_show(array_replace($r,['rental_kind'=>'replacement'])));ok(!reservation_can_mark_no_show(array_replace($r,['status'=>'picked_up'])));
 try{change_reservation_no_show(1,'no_show');ok(false);}catch(DomainException $e){ok(true);}
 $_SESSION['user']=['id'=>2,'role'=>'finance'];try{change_reservation_no_show(1,'no_show','',true);ok(false);}catch(DomainException $e){ok(true);}$_SESSION['user']=['id'=>1,'role'=>'staff'];
 change_reservation_no_show(1,'no_show','<not arrived>',true);$r=find_reservation(1);ok($r['status']==='no_show' && (int)$r['closed_by']===1 && !empty($r['closed_at']));
 ok((float)$r['total_price']===100.0 && (float)reservation_payments(1)[0]['amount']===25.0);
 ok(!reservation_conflicts(1,'2027-01-01','2027-01-02'));ok(bike_availability('2027-01-01','2027-01-02')[2]['available']);
 ok(!reservations_for_range(new DateTimeImmutable('2027-01-01'),new DateTimeImmutable('2027-01-02')));
 ok(find_contract_by_id(1)['public_token_hash']===null);ok(count(reservation_no_shows(new DateTimeImmutable('1999-01-01'),new DateTimeImmutable('2100-01-01')))===1);
 change_reservation_no_show(1,'no_show','repeat',true);ok((int)db()->query("SELECT COUNT(*) FROM audit_logs WHERE action='reservation_no_show'")->fetchColumn()===1);
 db()->exec("UPDATE reservations SET status='confirmed' WHERE id=2");try{change_reservation_no_show(1,'confirmed');ok(false);}catch(DomainException $e){ok(true);}ok(find_reservation(1)['status']==='no_show');
 db()->exec("UPDATE reservations SET status='cancelled' WHERE id=2;UPDATE bikes SET status='maintenance' WHERE id=1");try{change_reservation_no_show(1,'confirmed');ok(false);}catch(DomainException $e){ok(true);}
 db()->exec("UPDATE bikes SET status='active' WHERE id=1");change_reservation_no_show(1,'confirmed');ok(find_reservation(1)['status']==='confirmed');ok(reservation_conflicts(1,'2027-01-01','2027-01-02'));
 db()->exec("UPDATE reservations SET rental_kind='test' WHERE id=1");change_reservation_no_show(1,'no_show','Fixture test',true);ok(find_reservation(1)['status']==='no_show');
 try{change_reservation_no_show(1,'picked_up');ok(false);}catch(DomainException $e){ok(true);}
 db()->exec("UPDATE reservations SET end_at='2001-01-01' WHERE id=1");try{change_reservation_no_show(1,'confirmed');ok(false);}catch(DomainException $e){ok(true);}
 db()->exec("UPDATE reservations SET end_at='2099-12-31' WHERE id=1");$_SESSION['_fixture']=true;echo "PASS $checks no-show service checks.";
 `);console.log(results.text);
 const session=await run("require '/test/app/bootstrap.php';$_SESSION['user']=['id'=>1,'role'=>'staff','name'=>'Fixture','email'=>'staff@example.test'];");
 const opts={method:'GET',relativeUri:'/huur-module/reservation.php?id=1',$_SERVER:{SCRIPT_NAME:'/huur-module/reservation.php'},headers:{Cookie:session.headers['set-cookie'][0].split(';')[0]}};
 const page=await run("require '/test/public/reservation.php';",opts);assert.ok(page.text.includes('Niet opgedaagd (no-show)'));assert.ok(page.text.includes('Opnieuw bevestigen en beschikbaarheid controleren'));assert.ok(!page.text.includes('name="action" value="update-details"'));assert.ok(!page.text.includes('name="action" value="add-payment"'));assert.ok(!page.text.includes('Naar ondertekening'));
 const token=page.text.match(/name="_token" value="([a-f0-9]+)"/)[1];
 const statusOpts={...opts,method:'POST',relativeUri:'/huur-module/index.php?route=reservation-status&id=1',$_SERVER:{SCRIPT_NAME:'/huur-module/index.php'}};
 const denied=await run("$_POST=['status'=>'picked_up','_token'=>'bad'];require '/test/public/index.php';",statusOpts);assert.equal(denied.httpStatusCode,419);
 const blocked=await run(`$_POST=['status'=>'picked_up','_token'=>'${token}'];require '/test/public/index.php';`,statusOpts);assert.equal(blocked.httpStatusCode,302);
 assert.equal((await run("require '/test/app/bootstrap.php';echo find_reservation(1)['status'];",opts)).text,'no_show');
 await run("require '/test/app/bootstrap.php';db()->exec(\"UPDATE reservations SET no_show_reason='<check>' WHERE id=1\");",opts);
 const planning=await run("require '/test/public/planning.php';",{...opts,relativeUri:'/huur-module/planning.php?start=2000-01-01',$_SERVER:{SCRIPT_NAME:'/huur-module/planning.php'}});
 assert.ok(planning.text.includes('id="no-shows"')&&planning.text.includes('&lt;check&gt;'));
 assert.ok(!planning.text.includes('class="booking-block booking-type-'));
 await run("require '/test/app/bootstrap.php';db()->exec(\"UPDATE reservations SET status='reserved' WHERE id=2\");",opts);
 const mark=await run(`$_POST=['status'=>'no_show','_token'=>'${token}','confirm_no_show'=>'1','no_show_reason'=>'Route fixture'];require '/test/public/index.php';`,{...statusOpts,relativeUri:'/huur-module/index.php?route=reservation-status&id=2'});
 assert.equal(mark.httpStatusCode,302);assert.equal((await run("require '/test/app/bootstrap.php';echo find_reservation(2)['status'];",opts)).text,'no_show');
 console.log('PASS real dossier read-only/no-show actions, CSRF, generic status bypass, escaped planning history and staff mark-route checks.');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{try{php?.exit();}catch{}process.exit(process.exitCode||0);});
