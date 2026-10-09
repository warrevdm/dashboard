const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const {createRequire}=require('module');
const req=createRequire(path.resolve(__dirname,'../../lease/tests/package.json'));
const {PHP,FileLockManagerInMemory}=req('@php-wasm/universal');
const {loadNodeRuntime}=req('@php-wasm/node');
(async()=>{
 const php=new PHP(await loadNodeRuntime('8.3',{fileLockManager:new FileLockManagerInMemory(),emscriptenOptions:{processId:process.pid}}));
 function copy(src,dst){php.mkdirTree(dst);for(const e of fs.readdirSync(src,{withFileTypes:true})){if(e.isDirectory())copy(src+'/'+e.name,dst+'/'+e.name);else php.writeFile(dst+'/'+e.name,fs.readFileSync(src+'/'+e.name));}}
 copy(root+'/app','/demo/app');copy(root+'/public','/demo/public');php.mkdirTree('/demo/storage');php.mkdirTree('/demo/sessions');php.writeFile('/demo/schema.sql',fs.readFileSync(root+'/database/schema.sql'));
 const env={DB_PATH:'/demo/storage/database.sqlite',APP_ENV:'test',APP_DEBUG:'1',MAIL_TRANSPORT:'log'};
 async function run(code){const r=await php.run({code:"<?php ini_set('session.save_path','/demo/sessions'); "+code,env,$_SERVER:{SCRIPT_NAME:'/huur-module/reservation.php',REMOTE_ADDR:'192.0.2.1'}});if(r.errors)console.log(r.errors);return r;}
 await run(`require '/demo/app/bootstrap.php'; db()->exec(file_get_contents('/demo/schema.sql'));
 db()->exec("INSERT INTO users(id,name,email,password_hash,role) VALUES(1,'Demo medewerker','demo@example.test','demo','admin');
 INSERT INTO bikes(id,code,name,category,usage_type,daily_rate,status) VALUES(1,'E01','E-bike 01','E-bike','rental',30,'active'),(2,'E02','E-bike 02','E-bike','rental',30,'active'),(3,'S01','Stadsfiets 01','Stadsfiets','replacement_rental',15,'active'),(4,'T01','Testfiets 01','E-bike','test',30,'active');
 INSERT INTO customers(id,name,email,phone,address) VALUES(1,'Voorbeeldklant','klant@example.test','0400 00 00 00','Voorbeeldstraat 1'),(2,'Demo klant','demo@example.test','','');");
 $today=date('Y-m-d'); $end=date('Y-m-d',strtotime('+2 days')); $past=date('Y-m-d',strtotime('-1 day'));
 db()->exec("INSERT INTO reservations(id,bike_id,customer_id,start_at,end_at,status,rental_kind,total_price,created_by) VALUES(1,1,1,'$today 09:00:00','$end 17:00:00','confirmed','rental',180,1),(2,3,2,'$past 09:00:00','$today 17:00:00','picked_up','replacement',0,1); INSERT INTO reservation_bikes VALUES(1,1,30),(1,2,30),(2,3,15);");`);

 await run("require '/demo/app/bootstrap.php'; create_contract_for_reservation(1);");
 let checks=0;
 function check(v,label){assert.ok(v,label);checks++;}
 async function request(page,query={},options={}){
   const {role='admin',id=1,post}=options;
   const r=await php.run({code:"<?php ini_set('session.save_path','/demo/sessions'); require '/demo/app/bootstrap.php'; $_SESSION['user']="+(role===null?'null':"['id'=>"+id+",'name'=>'Demo','role'=>'"+role+"']")+"; $_SESSION['csrf_token']='testcsrf'; $_GET=json_decode('"+JSON.stringify(query)+"',true); require '/demo/public/"+page+"';",env,method:post?'POST':'GET',body:post?Buffer.from(new URLSearchParams(post).toString()):undefined,headers:{'Content-Type':'application/x-www-form-urlencoded'},$_SERVER:{SCRIPT_NAME:'/huur-module/'+page,REMOTE_ADDR:'192.0.2.1'}});
   assert.equal(r.errors,'',r.errors);check(!r.text.includes('Fatal error'),'no PHP fatal error');return r;
 }
 check((await request('contracts.php',{}, {role:null})).httpStatusCode===302,'anonymous overview denied');
 check((await request('sign.php',{contract_id:1},{role:null})).httpStatusCode===302,'anonymous internal signing denied');
 await run("require '/demo/app/bootstrap.php'; db()->exec(\"INSERT INTO users(id,name,email,password_hash,role) VALUES(2,'Finance','finance@example.test','test','finance'),(3,'Inactive','inactive@example.test','test','staff'); UPDATE users SET active=0 WHERE id=3\");");
 check((await request('contracts.php',{}, {role:'admin',id:2})).httpStatusCode===403,'fresh finance role denied even with stale admin session');
 check((await request('sign.php',{contract_id:1}, {role:'staff',id:3})).httpStatusCode===403,'inactive account denied');
 let list=await request('contracts.php'); if(process.env.TABLET_PREVIEW_DIR) {fs.mkdirSync(process.env.TABLET_PREVIEW_DIR,{recursive:true});fs.writeFileSync(path.join(process.env.TABLET_PREVIEW_DIR,'contracts.html'),list.text);fs.cpSync(root+'/public/assets',path.join(process.env.TABLET_PREVIEW_DIR,'assets'),{recursive:true});}check(list.text.includes('Voorbeeldklant')&&list.text.includes('Laat klant ondertekenen'),'pending list');
 check(!(await request('contracts.php',{status:'signed'})).text.includes('Voorbeeldklant'),'unsigned absent from signed list');
 check((await request('contracts.php',{q:'E02'})).text.includes('Voorbeeldklant'),'search second bike');
 check(!(await request('contracts.php',{q:'absent'})).text.includes('Voorbeeldklant'),'search no result');
 const sign=await request('sign.php',{contract_id:1});check(sign.text.includes('signature-canvas')&&!sign.text.includes('href="users.php"'),'customer view has signature and no staff navigation');
 if(process.env.TABLET_PREVIEW_DIR) fs.writeFileSync(path.join(process.env.TABLET_PREVIEW_DIR,'sign.html'),sign.text);
 const hash=sign.text.match(/name="contract_hash" value="([a-f0-9]+)"/)[1];
 check((await request('sign.php',{contract_id:1},{post:{}})).httpStatusCode===419,'CSRF required');
 check((await request('sign.php',{contract_id:1},{post:{_token:'testcsrf',contract_hash:'wrong'}})).httpStatusCode===409,'stale contract blocked');
 const fields={_token:'testcsrf',contract_hash:hash,accept_contract:'1',signer_name:'Demo Klant',signature_data:'invalid'};
 check((await request('sign.php',{contract_id:1},{post:fields})).httpStatusCode===302,'invalid signature handled');
 const png='data:image/png;base64,'+Buffer.concat([Buffer.from([137,80,78,71,13,10,26,10]),Buffer.alloc(110)]).toString('base64');
 check((await request('sign.php',{contract_id:1},{post:{...fields,signature_data:png}})).httpStatusCode===302,'signing redirects after save');
 const signed=await request('sign.php',{contract_id:1});check(signed.text.includes('De huurovereenkomst is ondertekend.')&&!signed.text.includes('id="signature-form"'),'signed document read-only');
 check((await request('contracts.php',{status:'signed'})).text.includes('Voorbeeldklant'),'signed list updated');
 check(!(await request('contracts.php')).text.includes('Voorbeeldklant'),'pending list updated');
 await run("require '/demo/app/bootstrap.php'; create_contract_for_reservation(2); db()->exec(\"UPDATE reservations SET status='cancelled' WHERE id=2\");");
 check((await request('sign.php',{contract_id:2})).httpStatusCode===404,'cancelled unsigned contract unavailable');
 check(!(await request('contracts.php')).text.includes('Demo klant'),'cancelled unsigned contract excluded');
 const before=(await run("require '/demo/app/bootstrap.php'; echo json_encode(find_contract_by_id(1));")).text;
 await request('sign.php',{contract_id:1},{post:{...fields,signer_name:'Replacement',signature_data:png}});
 check(before===(await run("require '/demo/app/bootstrap.php'; echo json_encode(find_contract_by_id(1));")).text,'signed document cannot be overwritten');
 check((await request('sign.php',{contract_id:999})).httpStatusCode===404,'missing contract');
 console.log('PASS: '+checks+' tablet contract checks');php.exit();
})();
