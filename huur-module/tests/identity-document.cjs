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


 let checks=0;function check(v,m){assert.ok(v,m);checks++;}
 const boundary='AAB-document-test';
 async function request({role='staff',id=1,post=null,bytes=null,filename='id.png',token='testcsrf'}={}){
   const fields=post?{action:'upload-identity-document',id:String(id),_token:token,retention_until:'2099-01-01',...post}:{};
   const parts=[];
   for(const [key,value] of Object.entries(fields))parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${key}"\r\n\r\n${value}\r\n`));
   if(bytes)parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="identity_document"; filename="${filename}"\r\nContent-Type: image/png\r\n\r\n`),bytes,Buffer.from('\r\n'));
   parts.push(Buffer.from(`--${boundary}--\r\n`));
   const r=await php.run({code:"<?php ini_set('session.save_path','/demo/sessions'); require '/demo/app/bootstrap.php'; $_SESSION['user']="+(role===null?'null':"['id'=>1,'name'=>'Demo','role'=>'"+role+"']")+"; $_SESSION['csrf_token']='testcsrf'; $_GET['id']="+id+"; require '/demo/public/reservation.php';",env,method:post?'POST':'GET',headers:{'Content-Type':`multipart/form-data; boundary=${boundary}`},body:post?Buffer.concat(parts):undefined,$_SERVER:{SCRIPT_NAME:'/huur-module/reservation.php',REMOTE_ADDR:'192.0.2.1'}});
   assert.equal(r.errors,'',r.errors);check(!r.text.includes('Fatal error'),'no PHP errors');return r;
 }
 async function state(){return JSON.parse((await run("require '/demo/app/bootstrap.php'; echo json_encode([db()->query('SELECT * FROM identity_documents')->fetchAll(),db()->query('SELECT identity_document_id FROM reservations WHERE id=1')->fetchColumn()]);")).text);}
 const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a8VQAAAAASUVORK5CYII=','base64');
 check((await request()).text.includes('Identiteitsdocument uploaden'),'staff sees upload');
 check((await request({id:2})).text.includes('Identiteitsdocument uploaden'),'replacement dossier shows upload');
 check(!(await request({role:'finance'})).text.includes('Identiteitsdocument uploaden'),'finance has no upload');
 check((await request({role:null,post:{},bytes:png})).httpStatusCode===302,'anonymous denied');
 check((await request({role:'finance',post:{},bytes:png})).httpStatusCode===403,'finance POST denied');
 check((await request({post:{},bytes:png,token:'wrong'})).httpStatusCode===419,'CSRF denied');
 await request({post:{}});check((await state())[0].length===0,'missing file leaves no record');
 await request({post:{retention_until:'2020-01-01'},bytes:png});check((await state())[0].length===0,'past retention rejected');
 await request({post:{},bytes:Buffer.from('<?php echo 1; ?>')});check((await state())[0].length===0,'spoofed PNG rejected');
 const saved=await request({post:{},bytes:png});check(saved.httpStatusCode===302,'successful upload redirects');
 const data=await state();check(data[0].length===1 && Number(data[1])===Number(data[0][0].id),'document attached to existing dossier');
 check(data[0][0].mime_type==='image/png'&&data[0][0].retention_until==='2099-01-01','MIME and retention stored');
 check(php.readFileAsBuffer('/demo/storage/private/ids/'+data[0][0].stored_name).length===png.length,'private file stored');
 check((await request()).text.includes('Veilig openen'),'document can be opened through protected route');
 await request({post:{},bytes:png});check(JSON.stringify(await state())===JSON.stringify(data),'existing document not overwritten');
 console.log('PASS: '+checks+' identity upload checks');php.exit();
})();
