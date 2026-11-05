// Isolated export route with synthetic SQLite data and PHP 8.3.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {PHP, FileLockManagerInMemory} = require('@php-wasm/universal');
const {loadNodeRuntime} = require('@php-wasm/node');
const root = path.resolve(__dirname, '..');
let php;
(async () => {
  php = new PHP(await loadNodeRuntime('8.3', {fileLockManager:new FileLockManagerInMemory(), emscriptenOptions:{processId:process.pid}}));
  for (const dir of ['app','public']) php.mkdirTree('/lease/'+dir);
  php.writeFile('/lease/app/ExpiringContracts.php',fs.readFileSync(root+'/app/ExpiringContracts.php'));
  php.writeFile('/lease/app/CsvExport.php',fs.readFileSync(root+'/app/CsvExport.php'));
  php.writeFile('/lease/public/export-contracts.php',fs.readFileSync(root+'/public/export-contracts.php'));
  // Native MySQL prepared statements reject repeated named placeholders. The
  // fixture explicitly checks this even though SQLite itself accepts them.
  php.writeFile('/lease/app/Database.php',`<?php
  class ExportPDO extends PDO {
    public function prepare(string $query, array $options=[]):PDOStatement|false {
      preg_match_all('/:([a-z_]+)/i',$query,$m);
      if(count($m[1])!==count(array_unique($m[1])))throw new RuntimeException('Repeated named parameter');
      return parent::prepare($query,$options);
    }
  }
  class Database { public static function connect():PDO {return new ExportPDO('sqlite:/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}}
  `);
  async function run(code,opts={}) {
    const r=await php.run({code:'<?php '+code,...opts});
    assert.equal(r.exitCode,0,r.text+r.errors);assert.equal(r.errors,'');return r;
  }
  let checks=0;function check(value,label){assert.ok(value,label);checks++;}
  const cells=JSON.parse((await run(`require '/lease/app/CsvExport.php';
    $values=['=HYPERLINK("https://example.test")','+321234','-7','@SUM(1)','  =1+1',"\\t=1+1","\\r+1","\\n@A1",'Normal name','0000123','Model; with delimiter',null];
    echo json_encode(['cells'=>array_map([CsvExport::class,'cell'],$values),'negative'=>CsvExport::cell('-12.50',true),'zero'=>CsvExport::cell('0.00',true),'unsafeNumeric'=>CsvExport::cell('=1+1',true)]);`)).text);
  check(cells.cells.slice(0,8).every(s=>s.startsWith("'")),'Formula/control prefixes neutralized as literal text');
  check(cells.cells[8]==='Normal name' && cells.cells[9]==='0000123' && cells.cells[10]==='Model; with delimiter' && cells.cells[11]==='', 'Ordinary text, leading zeros and empty values preserved');
  check(cells.negative==='-12.50' && cells.zero==='0.00','True numeric budgets remain numeric including negatives and zero');
  check(cells.unsafeNumeric==="'=1+1",'Numeric opt-in never bypasses formula protection');
  await run(`require '/lease/app/Database.php';$p=Database::connect();
    $p->exec("CREATE TABLE lease_orders(so_number TEXT,customer_name TEXT,email TEXT,phone TEXT,company TEXT,lease_partner TEXT,bike_type TEXT,bike_name TEXT,frame_number TEXT,lease_start_date TEXT,lease_end_date TEXT,maintenance_budget TEXT,yearly_maintenance_end_date TEXT,source_file TEXT,last_imported_at TEXT,created_at TEXT,updated_at TEXT,archived INTEGER,id INTEGER PRIMARY KEY AUTOINCREMENT)");
    $q=$p->prepare('INSERT INTO lease_orders VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL)');
    $q->execute(['SO-ONE',' =1+1','@SUM(1)','+321234','+Company','Cyclis','elektrisch','Normal; bike','0000123','2026-01-01','2029-01-01','-12.50',null,'export.csv','2026-10-27','2026-01-01','2026-10-27',0]);
    $q->execute(['SO-TWO','Second customer','second@example.test','123','Other','O2O','racefiets','Second bike','0000456','2026-01-01','2029-01-01','0.00',null,'export.csv','2026-10-27','2026-01-01','2026-10-27',0]);
    $q->execute(['SO-ARCHIVE','Archived','archive@example.test','123','Other','Cyclis','racefiets','Archived bike','0000789','2026-01-01','2029-01-01','0.00',null,'export.csv','2026-10-27','2026-01-01','2026-10-27',1]);`);
  const response=await run(`$_GET=['search'=>'SO-ONE','lease_partner'=>'Cyclis'];require '/lease/public/export-contracts.php';`);
  check(response.httpStatusCode===200 && response.headers['content-type'][0].includes('text/csv'),'Actual export route returns CSV');
  check(response.bytes[0]===239 && response.bytes[1]===187 && response.bytes[2]===191,'Excel UTF-8 BOM preserved');
  php.writeFile('/export.csv',response.bytes);
  const rows=JSON.parse((await run(`$h=fopen('/export.csv','rb');fread($h,3);$rows=[];while(($r=fgetcsv($h,0,';','"',''))!==false)$rows[]=$r;echo json_encode($rows);`)).text);
  check(rows.length===2 && rows[1][0]==='SO-ONE','Search uses unique placeholders and returns matching active record only');
  check(rows[1][1]==="' =1+1" && rows[1][2]==="'@SUM(1)" && rows[1][3]==="'+321234",'Actual route neutralizes names/email/phone');
  check(rows[1][8]==='0000123' && rows[1][11]==='-12.50' && rows[1][7]==='Normal; bike','Route retains frame IDs, budget values and delimiters');
  const malformed=await run(`$_GET=['search'=>['bad'],'lease_partner'=>['bad'],'bike_type'=>['bad'],'contract_status'=>['bad']];require '/lease/public/export-contracts.php';`);
  check(malformed.httpStatusCode===200 && !malformed.text.includes('SO-ARCHIVE'),'Array filters cause no fatal error and archived rows remain excluded');
  // The CSV and overview must use the same Brussels day and calendar-month window.
  await run(`require '/lease/app/Database.php';require '/lease/app/ExpiringContracts.php';$p=Database::connect();$p->exec('DELETE FROM lease_orders');
    [$today,$end]=ExpiringContracts::window(new DateTimeImmutable('now',new DateTimeZone('Europe/Brussels')));
    $q=$p->prepare('INSERT INTO lease_orders(so_number,customer_name,lease_end_date,lease_start_date,archived) VALUES(?,?,?,?,?)');
    foreach([['TODAY',$today->format('Y-m-d'),0],['CUTOFF',$end->format('Y-m-d'),0],['LATER',$end->modify('+1 day')->format('Y-m-d'),0],['EXPIRED',$today->modify('-1 day')->format('Y-m-d'),0],['UNKNOWN',null,0],['ARCHIVED',$today->format('Y-m-d'),1]] as $row)$q->execute([$row[0],'Fixture',$row[1],'2020-01-01',$row[2]]);`);
  for (const [filter,expected] of Object.entries({active:['CUTOFF','LATER','TODAY'],expiring_soon:['CUTOFF','TODAY'],expired:['EXPIRED'],no_end_date:['UNKNOWN']})) {
    const csv=await run(`$_GET=['contract_status'=>'${filter}'];require '/lease/public/export-contracts.php';`);
    php.writeFile('/filtered.csv',csv.bytes);
    const ids=JSON.parse((await run(`$h=fopen('/filtered.csv','rb');fread($h,3);fgetcsv($h,0,';','"','');$ids=[];while(($r=fgetcsv($h,0,';','"',''))!==false)$ids[]=$r[0];echo json_encode($ids);`)).text);
    assert.deepEqual(ids.sort(),expected,'Consistent date window for '+filter);checks++;
  }
  php.writeFile('/lease/app/bootstrap.php', '<?php class Auth {public static function requirePost():void{}}');
  php.writeFile('/lease/public/rollback-import.php',fs.readFileSync(root+'/public/rollback-import.php'));
  const failedRollback=await run(`ini_set('error_log','/rollback.log');$_POST=['batch_id'=>1];require '/lease/public/rollback-import.php';`,{method:'POST'});
  check(failedRollback.httpStatusCode===500 && failedRollback.text.includes('geen wijzigingen opgeslagen') && !failedRollback.text.includes('no such table'),'Rollback failures show a generic message');
  check(php.readFileAsText('/rollback.log').includes('no such table'),'Rollback technical details logged only on server');
  console.log('PASS '+checks+' export checks: native-compatible search, formula-safe text, numeric budgets and actual CSV route.');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{try{php?.exit();}catch{}process.exit(process.exitCode||0);});
