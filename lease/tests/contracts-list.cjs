// Real list rendering against isolated synthetic SQLite data; no live service.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {PHP, FileLockManagerInMemory} = require('@php-wasm/universal');
const {loadNodeRuntime} = require('@php-wasm/node');
const root = path.resolve(__dirname, '..');
let php;
(async () => {
  php = new PHP(await loadNodeRuntime('8.3', {fileLockManager:new FileLockManagerInMemory(), emscriptenOptions:{processId:process.pid}}));
  for (const folder of ['app','public/partials']) php.mkdirTree('/list/'+folder);
  php.writeFile('/list/public/index.php',fs.readFileSync(root+'/public/index.php'));
  php.writeFile('/list/app/ExpiringContracts.php',fs.readFileSync(root+'/app/ExpiringContracts.php'));
  php.writeFile('/list/app/Database.php',`<?php class Database { public static function connect():PDO {return new PDO('sqlite:/list/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}}`);
  php.writeFile('/list/public/partials/navigation.php','<?php // Authenticated navigation tested separately.');
  async function run(code) {
    const r=await php.run({code:'<?php '+code});
    assert.equal(r.exitCode,0,r.text+r.errors);assert.equal(r.errors,'');return r.text;
  }
  const dates=JSON.parse(await run(`require '/list/app/Database.php';require '/list/app/ExpiringContracts.php';
    $p=Database::connect();$p->exec('CREATE TABLE lease_orders(id INTEGER PRIMARY KEY,so_number TEXT,customer_name TEXT,email TEXT,phone TEXT,company TEXT,lease_partner TEXT,bike_type TEXT,bike_name TEXT,frame_number TEXT,lease_start_date TEXT,lease_end_date TEXT,maintenance_budget REAL,yearly_maintenance_end_date TEXT,order_status_raw TEXT,order_status_code TEXT,created_at TEXT,archived INTEGER);CREATE TABLE import_batches(created_at TEXT)');
    [$start,$end]=ExpiringContracts::window(new DateTimeImmutable('now',new DateTimeZone('Europe/Brussels')));
    $stmt=$p->prepare("INSERT INTO lease_orders VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    for($i=1;$i<=126;$i++)$stmt->execute([$i,'SO'.$i,$i===1?'<Synthetic>':'Fixture '.$i,'','','','Cyclis','elektrisch','Bike','FRAME'.$i,'2025-01-01',$start->modify('+2 years')->format('Y-m-d'),20,null,'5 delivered','5','2025-01-01',$i===126?1:0]);
    $p->prepare('UPDATE lease_orders SET lease_end_date=? WHERE id=1')->execute([$start->modify('-1 day')->format('Y-m-d')]);
    $p->prepare('UPDATE lease_orders SET lease_end_date=? WHERE id=2')->execute([$start->format('Y-m-d')]);
    $p->prepare('UPDATE lease_orders SET lease_end_date=? WHERE id=3')->execute([$end->format('Y-m-d')]);
    $p->exec('UPDATE lease_orders SET lease_end_date=NULL WHERE id=5');
    $p->prepare('UPDATE lease_orders SET yearly_maintenance_end_date=? WHERE id=6')->execute([$end->format('Y-m-d')]);
    echo json_encode(['today'=>$start->format('Y-m-d'),'yesterday'=>$start->modify('-1 day')->format('Y-m-d'),'limit'=>$end->format('Y-m-d'),'window_days'=>(int)$start->diff($end)->days]);`));
  let checks=0;
  function check(value,label){assert.ok(value,label);checks++;}
  async function page(query={}) {
    php.writeFile('/query.json',JSON.stringify(query));
    return run(`$_GET=json_decode(file_get_contents('/query.json'),true);require '/list/public/index.php';`);
  }
  const ids=html=>[...html.matchAll(/href="contract-detail\.php\?id=(\d+)"/g)].map(m=>Number(m[1]));
  const first=await page();
  assert.deepEqual(ids(first),Array.from({length:50},(_,i)=>i+1));checks++;
  check(first.includes('125</strong> resultaat')&&first.includes('Getoond: 1–50.'),'Total is filtered total, not page length');
  check(first.includes('&lt;Synthetic&gt;')&&!first.includes('<Synthetic>'),'Customer output escaped');
  assert.deepEqual(ids(await page({page:'2'})),Array.from({length:50},(_,i)=>i+51));checks++;
  assert.deepEqual(ids(await page({page:'99999999999999'})),Array.from({length:25},(_,i)=>i+101));checks++;
  check(ids(await page({per_page:'100'})).length===100,'Valid page size used');
  check(ids(await page({per_page:'99999'})).length===50,'Unsupported page size bounded');
  check(ids(await page({search:[],sort:[],contract_status:[],page:[]})).length===50,'Malformed query arrays fall back safely');
  const expired=await page({contract_status:'expired',lease_partner:'Cyclis',sort:'oldest_end'});
  assert.deepEqual(ids(expired),[1]);checks++;
  check(expired.includes('Leasingcontract is verlopen.'),'Yesterday shows expired icon');
  assert.deepEqual(ids(await page({contract_status:'expiring_soon'})),[2,3]);checks++;
  assert.deepEqual(ids(await page({contract_status:'no_end_date'})),[5]);checks++;
  const search=await page({search:'Fixture',lease_partner:'Cyclis',sort:'name_az',per_page:'50'});
  check(search.includes('search=Fixture&amp;lease_partner=Cyclis&amp;sort=name_az&amp;per_page=50&amp;page=2'),'Page links keep active search/filter/sort');
  check(search.includes('export-contracts.php?search=Fixture&amp;lease_partner=Cyclis&amp;sort=name_az&amp;per_page=50'),'Export keeps filters and does not receive current page');
  const noResults=await page({search:'no matching fixture'});
  check(ids(noResults).length===0&&noResults.includes('Geen leasingcontracten gevonden'),'Empty state remains clear');
  // Execute actual date helper bodies used by the detail screen.
  const detail=fs.readFileSync(root+'/public/contract-detail.php','utf8');
  const functions=detail.slice(detail.indexOf('function formatDateValue'),detail.indexOf('$leasePartnerNormalized ='));
  php.writeFile('/detail-dates.php','<?php '+functions);
  const result=JSON.parse(await run(`require '/list/app/ExpiringContracts.php';require '/detail-dates.php';echo json_encode([daysUntil('${dates.yesterday}'),daysUntil('${dates.today}'),daysUntil('2026-02-30'),threeMonthWindowDays(),statusText(${dates.window_days}),statusText(${dates.window_days+1})]);`));
  assert.deepEqual(result,[-1,0,null,dates.window_days,'Binnen 3 maanden','Actief']);checks++;
  console.log('PASS '+checks+' list/pagination/filter/date-status checks using synthetic data.');
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(()=>{try{php?.exit();}catch{}process.exit(process.exitCode||0);});
