// Synthetic PHP/SQLite fixture. No production configuration or live services.
const assert=require('node:assert/strict');
const fs=require('node:fs');const path=require('node:path');
const {PHP,FileLockManagerInMemory}=require('@php-wasm/universal');
const {loadNodeRuntime}=require('@php-wasm/node');
let php;
(async()=>{
 php=new PHP(await loadNodeRuntime('8.3',{fileLockManager:new FileLockManagerInMemory(),emscriptenOptions:{processId:process.pid}}));
 for(const d of ['app','public','sessions'])php.mkdirTree('/lease/'+d);
 for(const f of ['ContractUpdate','ImportValidation'])php.writeFile('/lease/app/'+f+'.php',fs.readFileSync(path.resolve(__dirname,'../app/'+f+'.php')));
 php.writeFile('/lease/app/bootstrap.php',`<?php ini_set('session.save_path','/lease/sessions');session_id('fixture');session_start();class Auth{public static function requirePost():void{}public static function csrfField():string{return '<input name="csrf_token" value="fixture">';}}`);
 php.writeFile('/lease/app/Database.php',`<?php require_once __DIR__.'/bootstrap.php';class TestDb extends PDO{public function __construct(){parent::__construct('sqlite:/lease/test.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}public function prepare(string $query,array $options=[]):PDOStatement|false{return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);}}class Database{public static function connect():PDO{return new TestDb();}}`);
 php.mkdirTree('/lease/public/partials');php.writeFile('/lease/public/partials/navigation.php','<?php');
 for(const f of ['update-contract','edit-contract'])php.writeFile('/lease/public/'+f+'.php',fs.readFileSync(path.resolve(__dirname,'../public/'+f+'.php')));
 let checks=0;const check=(x,label)=>{assert.ok(x,label);checks++;};
 async function run(code,opts={}){const r=await php.run({code:'<?php '+code,...opts});assert.equal(r.exitCode,0,r.text+r.errors);assert.equal(r.errors,'');return r;}
 const output=JSON.parse((await run(`require '/lease/app/ContractUpdate.php';
 $base=['so_number'=>'SO1','customer_name'=>'Fixture','email'=>'fixture@example.test','bike_type'=>'elektrisch','lease_partner'=>'Cyclis','lease_start_date'=>'2024-02-29','lease_end_date'=>'2028-02-29','maintenance_budget'=>'1.234,56'];
 $v=ContractUpdate::values($base,[]);$o=ContractUpdate::values(array_replace($base,['lease_partner'=>'o2o','lease_start_date'=>'2024-01-31','lease_end_date'=>'']),[]);
 $errors=0;foreach([['lease_end_date'=>'2020-01-01'],['lease_start_date'=>'2026-02-31'],['maintenance_budget'=>'garbage'],['bike_type'=>'invalid'],['email'=>'invalid'],['customer_name'=>['array']]] as $patch){try{ContractUpdate::values(array_replace($base,$patch),[]);}catch(InvalidArgumentException $e){$errors++;}}
 echo json_encode(['cyclis'=>$v,'o2o'=>$o,'errors'=>$errors]);`)).text);
 check(output.cyclis.cyclis_budget_valid_until==='2029-02-28','Cyclis leap year date clamps');
 check(output.cyclis.yearly_maintenance_end_date===null&&output.cyclis.o2o_budget_valid_until===null,'Partner fields cleaned');
 check(output.cyclis.maintenance_budget===1234.56,'Comma budget accepted');
 check(output.o2o.lease_end_date==='2027-01-31'&&output.o2o.o2o_budget_valid_until==='2027-02-28','O2O calculated dates clamp');
 check(output.errors===6,'Invalid dates/order/amount/type/email/arrays rejected');
 await run(`require '/lease/app/Database.php';$p=Database::connect();$p->exec("CREATE TABLE lease_orders(id INTEGER PRIMARY KEY,so_number TEXT UNIQUE,customer_name TEXT,email TEXT,phone TEXT,company TEXT,lease_partner TEXT,bike_type TEXT,bike_name TEXT,frame_number TEXT,lease_start_date TEXT,lease_end_date TEXT,maintenance_budget REAL,yearly_maintenance_end_date TEXT,o2o_budget_grace_start_date TEXT,o2o_budget_valid_until TEXT,cyclis_budget_grace_start_date TEXT,cyclis_budget_valid_until TEXT);INSERT INTO lease_orders(id,so_number,customer_name,email,bike_type,lease_partner,lease_start_date,lease_end_date) VALUES(1,'SO1','Fixture','fixture@example.test','elektrisch','Cyclis','2024-02-29','2028-02-29');");`);
 async function current(){return JSON.parse((await run(`require '/lease/app/Database.php';echo json_encode(Database::connect()->query('SELECT * FROM lease_orders WHERE id=1')->fetch());`)).text);}
 async function version(){return (await run(`require '/lease/app/Database.php';require '/lease/app/ContractUpdate.php';echo ContractUpdate::version(Database::connect()->query('SELECT * FROM lease_orders WHERE id=1')->fetch());`)).text;}
 const base={id:1,so_number:'SO1',customer_name:'Changed fixture',email:'fixture@example.test',bike_type:'elektrisch',lease_partner:'Cyclis',lease_start_date:'2024-02-29',lease_end_date:'2028-02-29',maintenance_budget:'12,50'};
 let r=await run(`$_POST=${JSON.stringify(JSON.stringify({...base,version:await version()}))};$_POST=json_decode($_POST,true);require '/lease/public/update-contract.php';`,{method:'POST'});
 check(r.httpStatusCode===303&&r.headers.location[0].includes('updated=1'),'Valid update redirects to dossier');
 check((await current()).maintenance_budget===12.5&&(await current()).cyclis_budget_valid_until==='2029-02-28','Real update stores budget and derived date');
 const stale=await version();await run(`require '/lease/app/Database.php';Database::connect()->exec("UPDATE lease_orders SET customer_name='Concurrent change' WHERE id=1");`);
 r=await run(`$_POST=json_decode(${JSON.stringify(JSON.stringify({...base,version:stale}))},true);require '/lease/public/update-contract.php';`,{method:'POST'});
 check(r.headers.location[0].includes('edit-contract.php'),'Stale version redirects to edit');check((await current()).customer_name==='Concurrent change','Concurrent data not overwritten');
 const edit=await run(`$_GET=['id'=>1];require '/lease/public/edit-contract.php';`);
 check(edit.text.includes('intussen gewijzigd')&&edit.text.includes('Changed fixture'),'Failed edit retains entered values and message');
 r=await run(`$_POST=json_decode(${JSON.stringify(JSON.stringify({...base,version:await version(),maintenance_budget:'bad'}))},true);require '/lease/public/update-contract.php';`,{method:'POST'});
 check(r.headers.location[0].includes('edit-contract.php')&&(await current()).maintenance_budget===12.5,'Invalid amount makes no changes');
 console.log('PASS '+checks+' manual contract update checks.');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{try{php?.exit();}catch{}process.exit(process.exitCode||0);});
