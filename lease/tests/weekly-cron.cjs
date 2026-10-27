// Private settings and HTTP scheduler checks with isolated PHP/Wasm fixtures.
// No real credentials, database or SMTP connections are used.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {PHP, FileLockManagerInMemory} = require('@php-wasm/universal');
const {loadNodeRuntime} = require('@php-wasm/node');
const root = path.resolve(__dirname, '..');
let php;
(async () => {
  php = new PHP(await loadNodeRuntime('8.3', {fileLockManager:new FileLockManagerInMemory(),emscriptenOptions:{processId:process.pid}}));
  for (const dir of ['app','public','config']) php.mkdirTree('/lease/'+dir);
  for (const name of ['WeeklyMailAutomation','WeeklyMailState','WeeklyMailConfig','WeeklyMailRunner','WeeklyMailMessage','ExpiringContracts']) {
    php.writeFile('/lease/app/'+name+'.php',fs.readFileSync(root+'/app/'+name+'.php'));
  }
  php.writeFile('/lease/config/weekly-mail.php',fs.readFileSync(root+'/config/weekly-mail.php'));
  php.writeFile('/lease/public/weekly-mail-cron.php',fs.readFileSync(root+'/public/weekly-mail-cron.php'));
  let checks=0;
  const check=(condition,label)=>{assert.ok(condition,label);checks++;};
  async function run(code,options={}) {
    const r=await php.run({code:"<?php ini_set('error_log','/cron-error.log');"+code,...options});
    assert.equal(r.exitCode,0,r.text+r.errors);assert.equal(r.errors,'');return r;
  }
  const settingsCode="require '/lease/app/WeeklyMailConfig.php'; require '/lease/app/WeeklyMailAutomation.php';";
  const original=JSON.parse((await run(settingsCode+"echo json_encode(WeeklyMailConfig::load());")).text);
  check(original.enabled===false && original.send_hour===9,'Default schedule disabled at Tuesday09');
  assert.deepEqual(original.recipients,['info@aertsactionbike.be','marketing@aertsactionbike.be']);checks++;
  check(!php.fileExists('/lease/storage/weekly-mail/automation.json'),'Loading defaults never writes settings');
  const settings=JSON.parse((await run(settingsCode+"echo json_encode(WeeklyMailAutomation::initialize(WeeklyMailConfig::load()));")).text);
  check(/^[a-f0-9]{64}$/.test(settings.key) && settings.enabled===false && settings.version===1,'Initialization creates random scheduler key and respects disabled default');
  const again=JSON.parse((await run(settingsCode+"echo json_encode(WeeklyMailAutomation::initialize(WeeklyMailConfig::load()));")).text);
  check(again.key===settings.key,'Reopening settings preserves scheduler URL');
  const settingsPath='/lease/storage/weekly-mail/automation.json';
  const permission=JSON.parse((await run("echo json_encode(fileperms('"+settingsPath+"')&0777);")).text);
  check(permission===384,'Private automation file mode0600');
  const auth=JSON.parse((await run(settingsCode+"$s=WeeklyMailAutomation::read('/lease/storage/weekly-mail');echo json_encode([WeeklyMailAutomation::authorized($s['key'],$s),WeeklyMailAutomation::authorized('', $s),WeeklyMailAutomation::authorized(['invalid'],$s),WeeklyMailAutomation::authorized(str_repeat('a',64),$s),WeeklyMailAutomation::authorized(null,$s),WeeklyMailAutomation::authorized($s['key'],null)]);")).text);
  assert.deepEqual(auth,[true,false,false,false,false,false]);checks++;
  await run(settingsCode+"WeeklyMailAutomation::setEnabled(WeeklyMailConfig::load(),true);");
  const enabled=JSON.parse((await run(settingsCode+"echo json_encode(WeeklyMailConfig::load());")).text);
  check(enabled.enabled===true && enabled.send_hour===9,'Dashboard activation loaded by shared mail config');
  check(JSON.parse(php.readFileAsText(settingsPath)).key===settings.key,'Activation preserves scheduler key');
  await run(settingsCode+"WeeklyMailAutomation::setEnabled(WeeklyMailConfig::load(),false);");
  check(JSON.parse((await run(settingsCode+"echo json_encode(WeeklyMailConfig::load());")).text).enabled===false,'Dashboard can disable scheduler');

  // These sentinels make any unintended pre-authentication DB/SMTP access visible.
  php.writeFile('/lease/app/DatabaseConnection.php',"<?php file_put_contents('/forbidden-db','reached'); throw new RuntimeException('DB sentinel');");
  php.writeFile('/lease/app/WeeklySmtpMailer.php',"<?php file_put_contents('/forbidden-smtp','reached'); throw new RuntimeException('SMTP sentinel');");
  const https={HTTPS:'on',HTTP_HOST:'localhost'};
  async function request(query='',options={}) {
    return run("require '/lease/public/weekly-mail-cron.php';",{method:'GET',relativeUri:'/lease/public/weekly-mail-cron.php'+query,$_SERVER:https,...options});
  }
  for (const query of ['', '?key=', '?key=wrong', '?key[]=wrong', '?key='+'a'.repeat(64)]) {
    const r=await request(query);check(r.httpStatusCode===403 && JSON.parse(r.text).status==='forbidden','Missing or malformed cron key rejected');
  }
  for (const method of ['HEAD','POST','PUT']) {
    const r=await request('?key='+settings.key,{method});check(r.httpStatusCode===405 && r.headers.allow[0]==='GET','Only GET can invoke cron');
  }
  const http=await run("$_SERVER['REQUEST_METHOD']='GET';$_SERVER['HTTPS']='off';unset($_SERVER['HTTP_X_FORWARDED_PROTO']);$_GET=['key'=>'"+settings.key+"'];require '/lease/public/weekly-mail-cron.php';");
  check(http.httpStatusCode===403 && JSON.parse(http.text).status==='https_required','Plain HTTP blocked');
  const before=php.readFileAsText(settingsPath);
  const disabled=await request('?key='+settings.key);
  check(disabled.httpStatusCode===200 && JSON.parse(disabled.text).status==='disabled','Valid cron credential works without browser session');
  check(disabled.headers['cache-control'][0].includes('no-store') && disabled.headers['referrer-policy'][0]==='no-referrer' && !disabled.headers.location,'Cron response uncached, private and never redirected to login');
  check(!php.fileExists('/forbidden-db') && !php.fileExists('/forbidden-smtp') && php.readFileAsText(settingsPath)===before,'Denied and disabled calls never connect DB/SMTP or modify settings');
  const headerKey=await request('',{$_SERVER:{...https,HTTP_X_CRON_KEY:settings.key}});
  check(headerKey.httpStatusCode===200 && JSON.parse(headerKey.text).status==='disabled','Scheduler header credential supported');
  const wrongHeader=await request('?key='+settings.key,{$_SERVER:{...https,HTTP_X_CRON_KEY:'wrong'}});
  check(wrongHeader.httpStatusCode===403,'Explicit wrong header cannot be bypassed with query key');

  // Exercise HTTP wiring at controlled schedule branches. Runner/DST/deduplication
  // itself is tested by weekly-mail.php; no clock/force overrides are exposed here.
  php.writeFile('/lease/app/WeeklyMailRunner.php',`<?php final class WeeklyMailRunner {
    public function __construct(private array $config,private Closure $load,private Closure $send){}
    public static function isDue(DateTimeImmutable $now,int $hour):bool {if($hour!==9)throw new RuntimeException('Wrong hour'); return getenv('FIXTURE_DUE')==='1';}
    public function run(DateTimeImmutable $now):array {if(func_num_args()!==1)throw new RuntimeException('HTTP retry exposed');$rows=($this->load)($now);foreach($this->config['recipients'] as $to)($this->send)($to,[]);return ['status'=>'sent','sent'=>count($this->config['recipients']),'contracts'=>count($rows)];}
  }`);
  await run(settingsCode+"WeeklyMailAutomation::setEnabled(WeeklyMailConfig::load(),true);");
  const notDue=await request('?key='+settings.key+'&force=1&retry_failed=1&now=2030-01-01',{env:{FIXTURE_DUE:'0'}});
  check(JSON.parse(notDue.text).status==='not_due' && !php.fileExists('/forbidden-db') && !php.fileExists('/forbidden-smtp'),'Out-of-schedule route skips dependencies; force/retry/time query ignored');
  php.writeFile('/lease/app/DatabaseConnection.php',`<?php final class DatabaseConnection {public static function open(string $path):PDO {file_put_contents('/db-path',$path);return new PDO('sqlite::memory:');}}`);
  php.writeFile('/lease/app/ExpiringContracts.php',`<?php final class ExpiringContracts {public static function find(PDO $pdo,DateTimeImmutable $now,bool $withoutLogbook):array {if(!$withoutLogbook)throw new RuntimeException('Logbook filter missing');file_put_contents('/query-zone',$now->getTimezone()->getName());return [];}}`);
  php.writeFile('/lease/app/WeeklySmtpMailer.php',`<?php final class WeeklySmtpMailer {public function __construct(array $config){} public static function assertAvailable():void{} public function send(string $to,array $message):void {file_put_contents('/sent-recipients',$to."\\n",FILE_APPEND);}}`);
  const due=await request('?key='+settings.key+'&retry_failed=1',{env:{FIXTURE_DUE:'1'}});
  check(due.httpStatusCode===200 && JSON.parse(due.text).status==='sent' && JSON.parse(due.text).sent===2,'Due route uses scheduled runner and internal recipients');
  assert.deepEqual(php.readFileAsText('/sent-recipients').trim().split('\n'),original.recipients);checks++;
  check(php.readFileAsText('/db-path')==='/lease/public/../config/config.production.php' && php.readFileAsText('/query-zone')==='Europe/Brussels','HTTP_HOST never selects development DB; selection uses Belgian timezone and no-logbook flag');
  // Auth/CSRF behavior is covered by security.cjs. Here exercise the actual
  // settings POST handler's persistence and redirect with private synthetic SMTP.
  php.writeFile('/lease/app/bootstrap.php',`<?php class Auth {public static function requirePost():void {if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('Expected POST');}}`);
  php.writeFile('/lease/public/update-weekly-mail.php',fs.readFileSync(root+'/public/update-weekly-mail.php'));
  const privateText=`<?php return ['enabled'=>false,'send_hour'=>14,'from_address'=>'lease@example.test','smtp'=>['host'=>'smtp.example.test','port'=>587,'encryption'=>'tls','username'=>'fixture@example.test','password'=>'synthetic-mail-password']];`;
  php.writeFile('/private-mail.php',privateText);
  const privateEnv={AAB_LEASE_WEEKLY_MAIL_FILE:'/private-mail.php'};
  async function update(enabled) {
    return run("$_POST=['enabled'=>"+JSON.stringify(enabled)+"];require '/lease/public/update-weekly-mail.php';",{method:'POST',env:privateEnv});
  }
  const off=await update('0');
  check(off.httpStatusCode===303 && off.headers.location[0]==='weekly-mail.php' && JSON.parse(php.readFileAsText(settingsPath)).enabled===false,'POST disables schedule and redirects');
  const on=await update('1');
  check(on.httpStatusCode===303 && on.headers.location[0]==='weekly-mail.php' && JSON.parse(php.readFileAsText(settingsPath)).enabled===true,'POST enables valid SMTP schedule and redirects');
  const afterEnable=JSON.parse((await run(settingsCode+"echo json_encode(WeeklyMailConfig::load());",{env:privateEnv})).text);
  check(afterEnable.enabled===true && afterEnable.send_hour===9 && afterEnable.smtp.password==='synthetic-mail-password','Shared config reflects enabled09 while retaining SMTP credentials');
  const kept=php.readFileAsText(settingsPath);
  const invalid=await update('2');
  check(invalid.httpStatusCode===303 && php.readFileAsText(settingsPath)===kept,'Invalid activation value rejected without changing settings');
  check(php.readFileAsText('/private-mail.php')===privateText,'Settings handler never rewrites private SMTP configuration');
  await update('0');
  check(JSON.parse(php.readFileAsText(settingsPath)).enabled===false && JSON.parse(php.readFileAsText(settingsPath)).key===settings.key,'Disabling again keeps existing scheduler credential');
  php.writeFile('/invalid-private.php','<?php return [];');
  const disabledSettings=php.readFileAsText(settingsPath);
  const invalidSmtp=await run("$_POST=['enabled'=>'1'];require '/lease/public/update-weekly-mail.php';",{method:'POST',env:{AAB_LEASE_WEEKLY_MAIL_FILE:'/invalid-private.php'}});
  check(invalidSmtp.httpStatusCode===303 && php.readFileAsText(settingsPath)===disabledSettings,'Incomplete mail configuration cannot activate scheduler');
  php.writeFile(settingsPath,'broken');
  const broken=await request('?key='+settings.key);
  check(broken.httpStatusCode===500 && JSON.parse(broken.text).status==='error' && !broken.text.includes(settings.key),'Corrupt scheduler settings fail closed with generic response');
  console.log('PASS '+checks+' scheduler settings and HTTP cron checks; real delivery never contacted.');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{try{php?.exit();}catch{}process.exit(process.exitCode||0);});
