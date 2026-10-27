// Isolated synthetic fixtures. Optional argv[2] reads a local CSV without retaining it.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {PHP, FileLockManagerInMemory} = require('@php-wasm/universal');
const {loadNodeRuntime} = require('@php-wasm/node');
const root = path.resolve(__dirname, '..');
let php;
(async () => {
  php = new PHP(await loadNodeRuntime('8.3', {fileLockManager: new FileLockManagerInMemory(), emscriptenOptions: {processId: process.pid}}));
  for (const folder of ['app', 'public/partials', 'storage/uploads']) php.mkdirTree('/lease/' + folder);
  for (const name of ['ImportFile', 'ImportColumns', 'ImportValues']) php.writeFile('/lease/app/' + name + '.php', fs.readFileSync(path.join(root, 'app', name + '.php')));
  const headers = ['SO-number','Name','Bike','Bike Type','Frame Number','Pickup Date','End date','Dealer','Available Maintenance Budget','lease maatschap'];
  const synthetic = '\ufeff' + headers.join(';') + '\r\n' + 'SO000123;"Fixture; rider";"Bike\nmodel";Elektrische fiets (25km/h);0000123;24/10/2026;23/10/2029;Aerts Action Bike;0.00;cyclis\r\n' + 'SO000124;Fixture two;=SUM(1+1);Speedpedelec;0000456;;;Aerts Action Bike;-12.50;cyclis\r\n\r\n';
  php.writeFile('/lease/storage/uploads/synthetic.csv', synthetic);
  php.writeFile('/comma.csv', 'SO-number,Name,Bike\n0001,"Name, Example",Model\n');
  php.writeFile('/tab.csv', 'SO-number\tName\tBike\n0002\tFixture\tModel\n');
  php.writeFile('/empty.csv', '\ufeff\r\n');
  php.writeFile('/malformed.csv', 'SO-number;Name\nSO1;Name;Unexpected\n');
  let checks = 0;
  async function run(code, opts = {}) {
    const result = await php.run({code: '<?php ' + code, ...opts});
    assert.equal(result.exitCode, 0, result.text + result.errors);
    assert.equal(result.errors, '');
    return result;
  }
  const result = JSON.parse((await run(`require '/lease/app/ImportFile.php'; require '/lease/app/ImportColumns.php'; require '/lease/app/ImportValues.php';
    $rows=ImportFile::rows('/lease/storage/uploads/synthetic.csv');
    $fields=['so_number','customer_name','bike_name','bike_type','frame_number','lease_start_date','lease_end_date','maintenance_budget','lease_partner','company'];
    $mapping=[];foreach($fields as $field)$mapping[$field]=ImportColumns::guess($field,$rows[0]);
    $existing=['email'=>'kept@example.test','phone'=>'01234','company'=>'Kept employer','lease_start_date'=>'2020-01-01','maintenance_budget'=>100,'order_status_raw'=>'5 delivered'];
    $preserved=ImportValues::preserveExisting(['email'=>'','phone'=>null,'maintenance_budget'=>'0.00'], $existing);
    $negative=ImportValues::preserveExisting(['maintenance_budget'=>'-12.50'], $existing);
    $errors=[];foreach(['/empty.csv','/malformed.csv'] as $file){try{ImportFile::rows($file);$errors[]=false;}catch(RuntimeException $e){$errors[]=true;}}
    echo json_encode(['rows'=>$rows,'mapping'=>$mapping,'preserved'=>$preserved,'negative'=>$negative,'errors'=>$errors,
      'comma'=>ImportFile::rows('/comma.csv'),'tab'=>ImportFile::rows('/tab.csv'),
      'new'=>ImportValues::preserveExisting(['email'=>'','maintenance_budget'=>'0'],null),
      'bom'=>ImportColumns::guess('so_number',["\\xEF\\xBB\\xBFSO-number"])]);`)).text);
  function check(value, label) { assert.ok(value, label); checks++; }
  check(result.rows.length === 3 && result.rows.every(row => row.length === 10), 'BOM/semicolon and blank lines parse correctly');
  check(result.rows[1][1] === 'Fixture; rider' && result.rows[1][2] === 'Bike\nmodel', 'Quoted delimiter and newline preserved');
  check(result.rows[1][4] === '0000123' && result.rows[2][2] === '=SUM(1+1)', 'Identifiers and formula-looking text remain literal');
  assert.deepEqual(result.mapping, {so_number:0,customer_name:1,bike_name:2,bike_type:3,frame_number:4,lease_start_date:5,lease_end_date:6,maintenance_budget:8,lease_partner:9,company:null}); checks++;
  check(result.bom === 0, 'BOM stripped in header normalization');
  check(result.preserved.email === 'kept@example.test' && result.preserved.phone === '01234' && result.preserved.company === 'Kept employer', 'Missing contact fields do not erase existing values');
  check(result.preserved.lease_start_date === '2020-01-01' && result.preserved.order_status === '5 delivered', 'Unmapped dates and status retained');
  check(result.preserved.maintenance_budget === '0.00' && result.negative.maintenance_budget === '-12.50', 'Zero and negative amounts remain real updates');
  check(result.new.email === '' && result.new.maintenance_budget === '0', 'New record not populated with invented values');
  check(result.errors.every(Boolean), 'Empty and malformed-width data rejected');
  check(result.comma[1][0] === '0001' && result.comma[1][1] === 'Name, Example' && result.tab[1][0] === '0002', 'Comma and tab delimiters supported');

  // Exercise the actual two rendering routes, with an isolated auth/DB shell.
  php.writeFile('/lease/app/bootstrap.php', '<?php class Auth {public static function requirePost():void {if(($_SERVER["REQUEST_METHOD"]??"")!=="POST")throw new RuntimeException("Expected POST");}public static function csrfField():string{return "<input name=csrf_token value=synthetic>";}}');
  php.writeFile('/lease/app/Database.php', '<?php class Database {public static function connect():PDO {$p=new PDO("sqlite::memory:");$p->exec("CREATE TABLE import_mappings(id INTEGER,mapping_name TEXT,mapping_json TEXT,updated_at TEXT)");return $p;}}');
  php.writeFile('/lease/public/partials/navigation.php', '<?php // Navigation excluded from this rendering fixture.');
  for (const name of ['import-preview.php', 'import-review.php']) php.writeFile('/lease/public/' + name, fs.readFileSync(path.join(root, 'public', name)));
  const preview = await run(`$_POST=['uploaded_file'=>'synthetic.csv']; require '/lease/public/import-preview.php';`, {method:'POST'});
  function selectedColumn(html, field) {
    const select = html.match(new RegExp('<select name="mapping\\[' + field + '\\]">([\\s\\S]*?)</select>'));
    assert.ok(select, 'Mapping selector for ' + field);
    return [...select[1].matchAll(/<option\s+value="([^"]*)"([^>]*)>/g)].filter(m => /\bselected\b/.test(m[2])).map(m => m[1]);
  }
  assert.deepEqual(selectedColumn(preview.text, 'lease_start_date'), ['5']); checks++;
  assert.deepEqual(selectedColumn(preview.text, 'lease_partner'), ['9']); checks++;
  assert.deepEqual(selectedColumn(preview.text, 'maintenance_budget'), ['8']); checks++;
  check(selectedColumn(preview.text, 'company').length === 0, 'Dealer not automatically selected as employer');
  const review = await run(`$_POST=['uploaded_file'=>'synthetic.csv','mapping_name'=>'Cyclis','mapping'=>['so_number'=>'0','customer_name'=>'1','bike_name'=>'2','bike_type'=>'3','frame_number'=>'4','lease_start_date'=>'5','lease_end_date'=>'6','maintenance_budget'=>'8','lease_partner'=>'9']]; require '/lease/public/import-review.php';`, {method:'POST'});
  check(review.text.includes('2026-10-24') && review.text.includes('2029-10-23') && review.text.includes('cyclis'), 'Actual review renders Cyclis dates and partner without vendor CSV dependency');
  check(review.text.includes('SO000123') && review.text.includes('0000123') && review.text.includes('-12.5'), 'Review keeps IDs and negative budgets');
  check(!review.text.includes('Warning') && !preview.text.includes('Warning'), 'Routes render without PHP warnings');

  if (process.argv[2]) {
    php.writeFile('/actual.csv', fs.readFileSync(path.resolve(process.argv[2])));
    const actual = JSON.parse((await run(`require '/lease/app/ImportFile.php'; require '/lease/app/ImportColumns.php';$rows=ImportFile::rows('/actual.csv');$header=array_shift($rows);$map=[];foreach(['so_number','lease_start_date','lease_end_date','lease_partner','maintenance_budget'] as $field)$map[$field]=ImportColumns::guess($field,$header);$ids=[];foreach($rows as $row)$ids[]=trim($row[$map['so_number']]);echo json_encode(['count'=>count($rows),'columns'=>count($header),'unique'=>count(array_unique($ids)),'blank_ids'=>count(array_filter($ids,static fn($id)=>$id==='')),'mapping'=>$map]);`)).text);
    assert.ok(actual.count > 0 && actual.blank_ids === 0 && actual.unique === actual.count);
    assert.ok(Object.values(actual.mapping).every(index => index !== null));
    console.log(`Uploaded CSV: ${actual.count} data rows, ${actual.columns} columns, ${actual.unique} unique IDs; required Cyclis mappings recognized. Personal row data not printed.`);
  }
  console.log(`PASS ${checks} Cyclis import checks.`);
})().catch(error => {console.error(error); process.exitCode=1;}).finally(() => {try {php?.exit();} catch {} process.exit(process.exitCode||0);});
