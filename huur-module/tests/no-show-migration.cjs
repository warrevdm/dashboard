// SQLite schema upgrade with synthetic dependent data only.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {createRequire} = require('node:module');
const req = createRequire(path.resolve(__dirname, '../../lease/tests/package.json'));
const {PHP, FileLockManagerInMemory} = req('@php-wasm/universal');
const {loadNodeRuntime} = req('@php-wasm/node');
let php;
(async () => {
  php = new PHP(await loadNodeRuntime('8.3', {fileLockManager:new FileLockManagerInMemory(), emscriptenOptions:{processId:process.pid}}));
  php.writeFile('/database.php', fs.readFileSync(path.resolve(__dirname,'../app/database.php')));
  php.writeFile('/schema.sql', fs.readFileSync(path.resolve(__dirname,'../database/schema.sql')));
  const response = await php.run({code:`<?php
    require '/database.php';
    $checks=0; function ok($condition){global $checks;if(!$condition)throw new RuntimeException('Migration check '.($checks+1));$checks++;}
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $schema=str_replace([", 'no_show'",'    no_show_reason TEXT,' . "\n"],['',''],file_get_contents('/schema.sql'));
    $pdo->exec($schema);
    $pdo->exec("INSERT INTO users(id,name,email,password_hash,role) VALUES(1,'Fixture','staff@example.test','hash','staff');
      INSERT INTO bikes(id,code,name,category) VALUES(1,'B1','Fixture bike','City');
      INSERT INTO customers(id,name) VALUES(1,'Fixture rider');
      INSERT INTO reservations(id,bike_id,customer_id,start_at,end_at,status,created_by,total_price,notes)
        VALUES(1,1,1,'2026-11-04 09:00:00','2026-11-05 17:00:00','confirmed',1,90,'Kept note');
      INSERT INTO reservation_bikes(reservation_id,bike_id,daily_rate) VALUES(1,1,30);
      INSERT INTO payment_logs(id,reservation_id,amount,method,recorded_by) VALUES(1,1,25,'cash',1);
      INSERT INTO rental_contracts(id,reservation_id,contract_number,contract_html,contract_hash) VALUES(1,1,'C1','fixture','hash');
      INSERT INTO reservations(id,bike_id,customer_id,start_at,end_at,status) VALUES(99,1,1,'2026-01-01','2026-01-02','cancelled');
      DELETE FROM reservations WHERE id=99;
      CREATE INDEX custom_reservation_note ON reservations(notes);
      CREATE TABLE custom_audit(note TEXT);
      CREATE TRIGGER custom_reservation_update AFTER UPDATE ON reservations BEGIN INSERT INTO custom_audit(note) VALUES('changed'); END;
      CREATE VIEW custom_reservation_view AS SELECT id,notes FROM reservations;
      CREATE TABLE external_events(reservation_id INTEGER REFERENCES reservations(id));
      CREATE TRIGGER external_reservation_read AFTER INSERT ON external_events BEGIN INSERT INTO custom_audit(note) SELECT notes FROM reservations WHERE id=NEW.reservation_id; END;
      ALTER TABLE reservations ADD COLUMN custom_payload TEXT;
      ALTER TABLE reservations ADD COLUMN end_day TEXT GENERATED ALWAYS AS (substr(end_at,1,10)) VIRTUAL;
      UPDATE reservations SET custom_payload='kept extension' WHERE id=1;");
    $before=$pdo->query('SELECT * FROM reservations WHERE id=1')->fetch();
    $auditBefore=(int)$pdo->query('SELECT COUNT(*) FROM custom_audit')->fetchColumn();
    $externalSql=$pdo->query("SELECT sql FROM sqlite_master WHERE name='external_reservation_read'")->fetchColumn();
    ensure_reservation_no_show_schema($pdo);
    ok((int)$pdo->query('PRAGMA foreign_keys')->fetchColumn()===1);
    $after=$pdo->query('SELECT * FROM reservations WHERE id=1')->fetch();
    ok(array_diff_assoc($before,$after)===[]);ok(array_key_exists('no_show_reason',$after)&&$after['no_show_reason']===null);
    ok($after['custom_payload']==='kept extension');ok($after['end_day']==='2026-11-05');
    foreach(['reservation_bikes','payment_logs','rental_contracts'] as $table)ok((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===1);
    ok(!$pdo->query('PRAGMA foreign_key_check')->fetch());
    ok((int)$pdo->query('SELECT COUNT(*) FROM custom_audit')->fetchColumn()===$auditBefore);
    ok((bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE name='custom_reservation_note'")->fetchColumn());
    ok($pdo->query("SELECT sql FROM sqlite_master WHERE name='external_reservation_read'")->fetchColumn()===$externalSql);
    ok($pdo->query('SELECT notes FROM custom_reservation_view WHERE id=1')->fetchColumn()==='Kept note');
    $pdo->exec("UPDATE reservations SET status='no_show',no_show_reason='Customer did not attend' WHERE id=1");
    ok($pdo->query('SELECT status FROM reservations WHERE id=1')->fetchColumn()==='no_show');
    ok((int)$pdo->query('SELECT COUNT(*) FROM custom_audit')->fetchColumn()===$auditBefore+1);
    $pdo->exec('INSERT INTO external_events(reservation_id) VALUES(1)');
    ok($pdo->query('SELECT note FROM custom_audit ORDER BY rowid DESC LIMIT 1')->fetchColumn()==='Kept note');
    try{$pdo->exec("UPDATE reservations SET status='invalid' WHERE id=1");ok(false);}catch(PDOException $e){ok(true);}
    $pdo->exec("INSERT INTO reservations(bike_id,customer_id,start_at,end_at,status) VALUES(1,1,1,2,'reserved')");
    ok((int)$pdo->lastInsertId()===100);
    $currentSql=$pdo->query("SELECT sql FROM sqlite_master WHERE name='reservations'")->fetchColumn();
    ensure_reservation_no_show_schema($pdo);
    ok($pdo->query("SELECT sql FROM sqlite_master WHERE name='reservations'")->fetchColumn()===$currentSql);
    ok((int)$pdo->query('SELECT COUNT(*) FROM reservations')->fetchColumn()===2);
    $bad=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $bad->exec("PRAGMA foreign_keys=ON;CREATE TABLE reservations(id INTEGER PRIMARY KEY,status TEXT CHECK(status!='invalid'));INSERT INTO reservations VALUES(1,'confirmed')");
    $badSql=$bad->query("SELECT sql FROM sqlite_master WHERE name='reservations'")->fetchColumn();
    try{ensure_reservation_no_show_schema($bad);ok(false);}catch(RuntimeException $e){ok(true);}
    ok($bad->query("SELECT sql FROM sqlite_master WHERE name='reservations'")->fetchColumn()===$badSql);
    ok($bad->query('SELECT status FROM reservations')->fetchColumn()==='confirmed');
    ok((int)$bad->query('PRAGMA foreign_keys')->fetchColumn()===1);
    $empty=new PDO('sqlite::memory:');ensure_reservation_no_show_schema($empty);ok(!$empty->query("SELECT 1 FROM sqlite_master WHERE name='reservations'")->fetchColumn());
    echo "PASS $checks no-show schema migration checks.";
  `});
  assert.equal(response.exitCode,0,response.text+' '+response.errors);assert.equal(response.errors,'');console.log(response.text);
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{try{php?.exit();}catch{}process.exit(process.exitCode||0);});
