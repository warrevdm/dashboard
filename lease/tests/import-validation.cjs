// Synthetic-only import checks; no live database, credentials or customer fixtures.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {PHP, FileLockManagerInMemory} = require('@php-wasm/universal');
const {loadNodeRuntime} = require('@php-wasm/node');
const root = path.resolve(__dirname, '..');
let php;
(async () => {
  php = new PHP(await loadNodeRuntime('8.3', {fileLockManager: new FileLockManagerInMemory(), emscriptenOptions:{processId:process.pid}}));
  for(const dir of ['app','public/partials','storage/uploads']) php.mkdirTree('/lease/'+dir);
  for(const name of ['ImportFile','ImportValidation','ImportValues']) php.writeFile('/lease/app/'+name+'.php',fs.readFileSync(path.join(root,'app',name+'.php')));
  for(const name of ['import-review.php','import-process.php']) php.writeFile('/lease/public/'+name,fs.readFileSync(path.join(root,'public',name)));
  php.writeFile('/lease/public/partials/navigation.php','<?php // Navigation excluded from fixture.');
  php.writeFile('/lease/app/bootstrap.php',`<?php class Auth {public static function requirePost():void {if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('POST required');}public static function csrfField():string {return '<input name="csrf_token" value="synthetic">';}}`);
  let checks=0;
  async function run(code,opts={}) {const r=await php.run({code:"<?php ini_set('error_log','/lease/test-errors.log'); "+code,...opts});assert.equal(r.exitCode,0,r.text+r.errors);assert.equal(r.errors,'');return r;}
  const basics=await run(`require '/lease/app/ImportValidation.php';$checks=0;function ok($v){global $checks;if(!$v)throw new Exception('Validation '.($checks+1));$checks++;}
    foreach(['24/10/2026'=>'2026-10-24','2026-10-24'=>'2026-10-24','01-02-26'=>'2026-02-01','29/02/2024'=>'2024-02-29'] as $input=>$expected)ok(ImportValidation::date($input)===$expected);
    ok(ImportValidation::date(null)===null);ok(ImportValidation::date('   ')===null);ok(ImportValidation::date(1)==='1900-01-01');ok(ImportValidation::date(61)==='1900-03-01');
    foreach(['31/02/2026','29/02/2026','2026-13-01','not a date','20261027','2026-10-24 trailing'] as $bad){try{ImportValidation::date($bad);ok(false);}catch(InvalidArgumentException $e){ok(true);}}
    foreach(['1.234,56'=>1234.56,'1,234.56'=>1234.56,'1234,56'=>1234.56,'1234.56'=>1234.56,'1.234'=>1234.0,'0.00'=>0.0,'-12,50'=>-12.5,'€ 1 234,56'=>1234.56] as $input=>$expected)ok(ImportValidation::money($input)===$expected);
    ok(ImportValidation::money(null)===null);ok(ImportValidation::money('')===null);
    foreach(['wrong','1.23.4,56','1,234,56','1,234.567','1e3','100000000','-','NaN'] as $bad){try{ImportValidation::money($bad);ok(false);}catch(InvalidArgumentException $e){ok(true);}}
    $mapping=['so_number'=>'0','lease_start_date'=>'1','lease_end_date'=>'2','maintenance_budget'=>'3'];
    ok(ImportValidation::errors([['SO1','','','0'],['so1','','','1']],$mapping,4)!==[]);
    ok(ImportValidation::errors([['SO1','31/02/2026','','0']],$mapping,4)!==[]);
    ok(ImportValidation::errors([['SO1','2026-10-24','2026-10-23','0']],$mapping,4)!==[]);
    ok(ImportValidation::errors([['SO1','','','not a number']],$mapping,4)!==[]);
    ok(ImportValidation::errors([['','','','0']],$mapping,4)!==[]);
    ok(ImportValidation::mappingErrors(['so_number'=>''],4)!==[]);ok(ImportValidation::mappingErrors(['so_number'=>'4'],4)!==[]);ok(ImportValidation::mappingErrors(['so_number'=>['0']],4)!==[]);
    ok(ImportValidation::errors([['SO1','','','0'],['SO2','','','-1']],$mapping,4)===[]);
    echo $checks;`);
  checks+=Number(basics.text);
  php.writeFile('/lease/storage/uploads/valid.csv','SO-number;Start;End;Budget\nSO1;24/10/2026;23/10/2029;0.00\nSO2;24/10/2026;23/10/2029;-12.50\n');
  php.writeFile('/lease/storage/uploads/duplicate.csv','SO-number;Start;End;Budget\nSO1;24/10/2026;23/10/2029;0.00\nso1;24/10/2026;23/10/2029;1\n');
  php.writeFile('/lease/storage/uploads/invalid.csv','SO-number;Start;End;Budget\nSO1;31/02/2026;23/10/2029;wrong\n');
  php.writeFile('/lease/storage/uploads/header.csv','SO-number;Start;End;Budget\n');
  const post=file=>`$_POST=['uploaded_file'=>'${file}','mapping_name'=>'Cyclis','mapping'=>['so_number'=>'0','lease_start_date'=>'1','lease_end_date'=>'2','maintenance_budget'=>'3']];`;
  for(const file of ['duplicate.csv','invalid.csv']) {
    const r=await run(post(file)+"require '/lease/public/import-review.php';",{method:'POST'});
    assert.equal(r.httpStatusCode,422);assert.ok(r.text.includes('Rij 3:')||r.text.includes('Rij 2:'));assert.ok(!r.text.includes('Definitief importeren'));assert.ok(r.text.includes('Terug naar kolomkoppeling'));checks++;
  }
  const header=await run("require '/lease/app/ImportFile.php';try{ImportFile::rows('/lease/storage/uploads/header.csv');throw new Exception('Header-only accepted');}catch(RuntimeException $e){echo 'rejected';}");assert.equal(header.text,'rejected');checks++;
  php.writeFile('/lease/app/Database.php',`<?php
  class TestStatement extends PDOStatement {
    public function __construct(private TestPDO $db,private string $sql){}
    public function execute(?array $params=null):bool {
      $this->db->calls++;
      if(str_contains($this->sql,'INSERT INTO lease_orders')) {
        $this->db->upserts++;
        if(($GLOBALS['failSecond']??false)&&$this->db->upserts===2)throw new RuntimeException('PRIVATE connection secret');
      }
      if(str_starts_with(trim($this->sql),'INSERT')||str_starts_with(trim($this->sql),'UPDATE'))$this->db->writes[]=$this->sql;
      return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return false;}
  }
  class TestPDO extends PDO {
    public array $writes=[];public int $calls=0;public int $upserts=0;public bool $active=false;public bool $rolled=false;public bool $committed=false;
    public function __construct(){}
    public function beginTransaction():bool{$this->active=true;return true;}
    public function inTransaction():bool{return $this->active;}
    public function commit():bool{$this->active=false;$this->committed=true;return true;}
    public function rollBack():bool{$this->active=false;$this->rolled=true;$this->writes=[];return true;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new TestStatement($this,$query);}
    public function lastInsertId(?string $name=null):string|false{return '1';}
  }
  class Database {public static function connect():PDO{return $GLOBALS['testPDO']??=new TestPDO;}}
  `);
  const state=`register_shutdown_function(function(){echo '\\nSTATE:'.json_encode(isset($GLOBALS['testPDO'])?['writes'=>count($GLOBALS['testPDO']->writes),'rolled'=>$GLOBALS['testPDO']->rolled,'committed'=>$GLOBALS['testPDO']->committed,'upserts'=>$GLOBALS['testPDO']->upserts]:['none'=>true]);});`;
  const failed=await run("$GLOBALS['failSecond']=true;"+state+post('valid.csv')+"require '/lease/public/import-process.php';",{method:'POST'});
  assert.equal(failed.httpStatusCode,500);assert.ok(failed.text.includes('geen wijzigingen opgeslagen'));assert.ok(!failed.text.includes('PRIVATE'));assert.ok(failed.text.includes('"writes":0,"rolled":true,"committed":false,"upserts":2'));checks++;
  const success=await run(state+post('valid.csv')+"require '/lease/public/import-process.php';",{method:'POST'});
  assert.equal(success.httpStatusCode,200);assert.ok(success.text.includes('Import succesvol afgerond'));assert.ok(success.text.includes('"committed":true'));checks++;
  const calendar=await run(post('valid.csv')+"require '/lease/public/import-process.php'; echo '\\nCALENDAR:'.json_encode([addMonthsToDate('2024-02-29',12),addMonthsToDate('2024-01-31',1),addMonthsToDate('2026-12-31',1),calculateNextAnnualMaintenanceEndDate('2024-02-29',null,new DateTimeImmutable('2028-02-28',new DateTimeZone('Europe/Brussels'))),normalizeBikeTypeValue('elektrische gezinsfiets')]);",{method:'POST'});
  assert.ok(calendar.text.includes('["2025-02-28","2024-02-29","2027-01-31","2028-02-29","elektrische gezinsfiets"]'));checks+=5;
  const rejected=await run(state+post('duplicate.csv')+"require '/lease/public/import-process.php';",{method:'POST'});
  assert.equal(rejected.httpStatusCode,422);assert.ok(rejected.text.includes('"none":true'));checks++;
  console.log('PASS '+checks+' strict parsing, full-file validation, preview blocking and atomic processing checks.');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{try{php?.exit();}catch{}process.exit(process.exitCode||0);});
