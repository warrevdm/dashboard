// Synthetic contract/cashbook routes; no live services or customer data.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {createRequire}=require('node:module');
const req=createRequire(path.resolve(__dirname,'../../lease/tests/package.json'));
const {PHP,FileLockManagerInMemory}=req('@php-wasm/universal');const {loadNodeRuntime}=req('@php-wasm/node');
const root=path.resolve(__dirname,'..');let php;
(async()=>{
 php=new PHP(await loadNodeRuntime('8.3',{fileLockManager:new FileLockManagerInMemory(),emscriptenOptions:{processId:process.pid}}));
 for(const d of ['app','public','storage','sessions'])php.mkdirTree('/demo/'+d);
 for(const f of fs.readdirSync(root+'/app').filter(n=>n.endsWith('.php')))php.writeFile('/demo/app/'+f,fs.readFileSync(root+'/app/'+f));
 for(const f of ['sign.php','contracts.php','cashbook.php'])php.writeFile('/demo/public/'+f,fs.readFileSync(root+'/public/'+f));
 php.writeFile('/demo/schema.sql',fs.readFileSync(root+'/database/schema.sql','utf8').replace("'returned', 'cancelled')","'returned', 'cancelled', 'no_show')"));
 // Emulate a stale signature page whose reservation changes just before its
 // final write. This isolates the SQL guard rather than relying on the early read.
 php.writeFile('/demo/app/database.php',`<?php
 class RacePDO extends PDO {public function prepare(string $query,array $options=[]):PDOStatement|false {
 if(!empty($GLOBALS['race_sign']) && str_contains($query,'UPDATE rental_contracts SET signer_name')) {
 $GLOBALS['race_sign']=false;$this->exec("UPDATE reservations SET status='no_show' WHERE id=1");}
 return parent::prepare($query,$options);}}
 function db():PDO {static $p;if(!$p)$p=new RacePDO('sqlite:/demo/storage/test.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);return $p;}`);
 const env={APP_ENV:'test',APP_DEBUG:'1',MAIL_TRANSPORT:'log'};
 async function run(code,options={}){const r=await php.run({code:"<?php ini_set('session.save_path','/demo/sessions');"+code,env,$_SERVER:{SCRIPT_NAME:'/huur-module/reservation.php',REMOTE_ADDR:'192.0.2.1'},...options});assert.equal(r.exitCode,0,r.text+r.errors);assert.equal(r.errors,'');return r;}
 await run(`require '/demo/app/bootstrap.php';db()->exec(file_get_contents('/demo/schema.sql'));
 db()->exec("INSERT INTO users(id,name,email,password_hash,role) VALUES(1,'Fixture staff','staff@example.test','test','admin');
 INSERT INTO customers(id,name,email) VALUES(1,'No show fixture','one@example.test'),(2,'Signed history fixture','two@example.test'),(3,'Unused fixture','three@example.test'),(4,'Active fixture','four@example.test');
 INSERT INTO bikes(id,code,name,category) VALUES(1,'A','Fixture bike','City');");
 $today=date('Y-m-d');$end=date('Y-m-d',strtotime('+2 days'));
 $q=db()->prepare("INSERT INTO reservations(id,bike_id,customer_id,start_at,end_at,status,total_price) VALUES(?,1,?,? ,?,'confirmed',100)");
 foreach([1,2,3,4] as $id){$q->execute([$id,$id,$today.' 09:00:00',$end.' 17:00:00']);db()->exec("INSERT INTO reservation_bikes(reservation_id,bike_id,daily_rate) VALUES($id,1,30)");}
 $_SESSION['user']=['id'=>1,'role'=>'admin','name'=>'Fixture staff'];create_contract_for_reservation(1);create_contract_for_reservation(2);`);
 let checks=0;function check(v,label){assert.ok(v,label);checks++;}
 const png='data:image/png;base64,'+Buffer.concat([Buffer.from([137,80,78,71,13,10,26,10]),Buffer.alloc(110)]).toString('base64');
 const signed=JSON.parse((await run(`require '/demo/app/bootstrap.php';$GLOBALS['race_sign']=true;$before=find_contract_by_id(1);try{sign_contract($before,'Fixture rider',${JSON.stringify(png)});$blocked=false;}catch(RuntimeException $e){$blocked=true;}echo json_encode(['blocked'=>$blocked,'signed'=>find_contract_by_id(1)['signed_at'],'signatures'=>glob(ROOT_PATH.'/storage/private/signatures/*')]);`)).text);
 check(signed.blocked&&signed.signed===null,'Final SQL condition rejects stale page after no-show race');
 check(signed.signatures.length===0,'Failed stale signing removes transient signature');
 await run(`require '/demo/app/bootstrap.php';sign_contract(find_contract_by_id(2),'Fixture rider',${JSON.stringify(png)});db()->exec("UPDATE reservations SET status='no_show' WHERE id IN(1,2,3)");`);
 const guards=JSON.parse((await run(`require '/demo/app/bootstrap.php';$c=find_contract_by_id(1);$token=str_repeat('a',64);$q=db()->prepare('UPDATE rental_contracts SET public_token_hash=?,public_token_expires_at=? WHERE id=1');$q->execute([hash('sha256',$token),'2099-01-01 00:00:00']);$_SESSION['contract_tokens'][1]=$token;
 $blocked=[];foreach(['create-existing','create-new','issue-token','sign'] as $case){try{match($case){'create-existing'=>create_contract_for_reservation(1),'create-new'=>create_contract_for_reservation(3),'issue-token'=>contract_issue_token(1),'sign'=>sign_contract($c,'Fixture rider',${JSON.stringify(png)})};$blocked[]=false;}catch(Throwable $e){$blocked[]=true;}}
 echo json_encode(['blocked'=>$blocked,'lookup'=>find_contract_by_token($token),'cached'=>contract_token_for_staff($c),'signed_history'=>create_contract_for_reservation(2)['signed_at']]);`)).text);
 check(guards.blocked.every(Boolean),'No-show cannot create/retrieve unsigned agreement or issue/reuse signing token');
 check(guards.cached===null,'Staff GET dossier gets no token without throwing');
 check(guards.lookup===null,'Public token lookup rejects no-show even if token persisted');
 check(Boolean(guards.signed_history),'Existing signed history preserved');
 async function page(name,get={}){return run(`require '/demo/app/bootstrap.php';$_SESSION['user']=['id'=>1,'role'=>'admin','name'=>'Fixture staff'];$_GET=json_decode(${JSON.stringify(JSON.stringify(get))},true);require '/demo/public/${name}';`,{method:'GET',$_SERVER:{SCRIPT_NAME:'/huur-module/'+name,REMOTE_ADDR:'192.0.2.1'}});}
 check((await page('sign.php',{contract_id:1})).httpStatusCode===404,'Internal pending no-show link unavailable');
 check((await page('sign.php',{token:'a'.repeat(64)})).httpStatusCode===404,'External pending no-show link unavailable');
 check(!(await page('contracts.php')).text.includes('No show fixture'),'No-show absent from pending tablet list');
 check((await page('contracts.php',{status:'signed'})).text.includes('Signed history fixture'),'Signed no-show remains in signed history');
 check((await page('sign.php',{contract_id:2})).text.includes('De huurovereenkomst is ondertekend.'),'Signed no-show remains read-only view');
 await run(`require '/demo/app/bootstrap.php';db()->exec("INSERT INTO payment_logs(reservation_id,amount,method,paid_at) VALUES(1,10,'cash',CURRENT_TIMESTAMP)");`);
 const cash=(await run(`require '/demo/app/bootstrap.php';$_SESSION['user']=['id'=>1,'role'=>'admin','name'=>'Fixture staff'];register_shutdown_function(function(){echo '__TOTALS__'.json_encode(['received'=>$GLOBALS['receivedTotal'],'value'=>$GLOBALS['rentalValue'],'outstanding'=>$GLOBALS['periodOutstanding']]);});require '/demo/public/cashbook.php';`,{method:'GET',$_SERVER:{SCRIPT_NAME:'/huur-module/cashbook.php'}})).text;
 const totals=JSON.parse(cash.split('__TOTALS__')[1]);
 check(totals.received===10,'Actual no-show payment remains in received cash totals');
 check(totals.value===100&&totals.outstanding===100,'No-show excluded from theoretical rental value and outstanding');
 console.log('PASS '+checks+' no-show contract/signing/cashbook checks.');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{try{php?.exit();}catch{}process.exit(process.exitCode||0);});
