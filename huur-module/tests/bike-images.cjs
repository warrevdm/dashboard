// Synthetic images and isolated PHP only; no hosting or customer data.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {createRequire} = require('node:module');
const req = createRequire(path.resolve(__dirname,'../../lease/tests/package.json'));
const {PHP,FileLockManagerInMemory} = req('@php-wasm/universal');
const {loadNodeRuntime} = req('@php-wasm/node');
let php;
(async()=>{
 php = new PHP(await loadNodeRuntime('8.3',{fileLockManager:new FileLockManagerInMemory(),emscriptenOptions:{processId:process.pid}}));
 for(const d of ['app','public/assets/bike-cache','storage/private/bikes','sessions'])php.mkdirTree('/test/'+d);
 const root=path.resolve(__dirname,'..');
 for(const f of fs.readdirSync(root+'/app').filter(f=>f.endsWith('.php')))php.writeFile('/test/app/'+f,fs.readFileSync(root+'/app/'+f));
 php.writeFile('/test/public/bike-photo.php',fs.readFileSync(root+'/public/bike-photo.php'));
 php.writeFile('/test/schema.sql',fs.readFileSync(root+'/database/schema.sql'));
 async function run(code,options={}){const r=await php.run({code:"<?php ini_set('session.save_path','/test/sessions');"+code,env:{DB_PATH:'/test/storage/test.sqlite',APP_ENV:'test'},...options});assert.equal(r.errors,'');assert.equal(r.exitCode,0,r.text);return r;}
 await run(`require '/test/app/bootstrap.php'; db()->exec(file_get_contents('/test/schema.sql')); db()->exec("INSERT INTO bikes(id,code,name,category,photo_stored_name) VALUES(1,'TEST','Fixture','City','test.png')");`);
 php.writeFile('/test/storage/private/bikes/test.png',Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9N8AAAAASUVORK5CYII=','base64'));
 const session=await run("require '/test/app/bootstrap.php'; $_SESSION['user']=['id'=>1,'role'=>'admin','name'=>'Fixture'];");
 const opts={method:'GET',relativeUri:'/huur-module/bike-photo.php?id=1&size=480',$_SERVER:{SCRIPT_NAME:'/huur-module/bike-photo.php'},headers:{Cookie:session.headers['set-cookie'][0].split(';')[0]}};
 const anon=await run("require '/test/public/bike-photo.php';",{...opts,headers:{}});assert.equal(anon.httpStatusCode,302);
 const info=JSON.parse((await run("require '/test/app/bootstrap.php'; $bike=find_bike(1); echo json_encode(['variant'=>bike_image_variant_info($bike,480),'url'=>bike_photo_src($bike,240),'gd'=>function_exists('imagewebp')]);")).text);
 assert.match(info.url,/thumb=2/);
 // Valid tiny WebP fixture: cache serving, conditional requests, and static URL reuse.
 php.writeFile(info.variant.cache_path,Buffer.from('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA','base64'));
 const cached=await run("require '/test/public/bike-photo.php';",opts);
 assert.equal(cached.httpStatusCode,200);assert.equal(cached.headers['content-type'][0],'image/webp');assert.equal(cached.headers['x-bike-image-mode'][0],'webp');
 const again=await run("require '/test/public/bike-photo.php';",{...opts,headers:{...opts.headers,'If-None-Match':cached.headers.etag[0]}});assert.equal(again.httpStatusCode,304);assert.equal(again.bytes.length,0);
 const url=(await run("require '/test/app/bootstrap.php';echo bike_photo_src(find_bike(1),240);")).text;assert.match(url,/^assets\/bike-cache\/.*\.webp$/);
 const large=await run("require '/test/public/bike-photo.php';",{...opts,relativeUri:'/huur-module/bike-photo.php?id=1&size=1200'});
 assert.equal(large.httpStatusCode,200);assert.notEqual(large.headers.etag[0],cached.headers.etag[0]);
 if(info.gd){
  await run("$im=imagecreatetruecolor(1800,1200); imagepng($im,'/test/storage/private/bikes/test.png'); imagedestroy($im);");
  const resized=await run("require '/test/public/bike-photo.php';",opts);
  assert.equal(resized.headers['x-bike-image-mode'][0],'webp');
  php.writeFile('/test/result.webp',resized.bytes);
  const dimensions=JSON.parse((await run("echo json_encode(getimagesize('/test/result.webp'));")).text);
  assert.equal(Math.max(dimensions[0],dimensions[1]),480);assert.ok(dimensions[0]>0 && dimensions[1]>0);
 }


 console.log('PASS image authentication, cached WebP response, 304, static cache URL, cache busting and distinct size ETags. GD/WebP available: '+info.gd);
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{try{php?.exit();}catch{}process.exit(process.exitCode||0);});
