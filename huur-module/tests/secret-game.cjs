// Synthetic accounts and private in-memory PHP/Wasm storage only. No live services.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {createRequire}=require('node:module');
const req=createRequire(path.resolve(__dirname,'../../lease/tests/package.json'));
const {PHP,FileLockManagerInMemory}=req('@php-wasm/universal');const {loadNodeRuntime}=req('@php-wasm/node');
const {snakeEngine,tetrisEngine}=require('../public/assets/secret-game.js');
const root=path.resolve(__dirname,'..');let php,checks=0;
function check(value,label){assert.ok(value,label);checks++;}
(async()=>{
 php=new PHP(await loadNodeRuntime('8.3',{fileLockManager:new FileLockManagerInMemory(),emscriptenOptions:{processId:process.pid}}));
 for(const d of ['app','public','storage','sessions'])php.mkdirTree('/game-test/'+d);
 for(const f of fs.readdirSync(root+'/app').filter(f=>f.endsWith('.php')))php.writeFile('/game-test/app/'+f,fs.readFileSync(root+'/app/'+f));
 php.writeFile('/game-test/public/game.php',fs.readFileSync(root+'/public/game.php'));
 php.writeFile('/game-test/game.php',fs.readFileSync(root+'/game.php'));
 php.writeFile('/game-test/schema.sql',fs.readFileSync(root+'/database/schema.sql'));
 async function run(code,options={}){const r=await php.run({code:"<?php ini_set('session.save_path','/game-test/sessions');"+code,env:{APP_ENV:'test',APP_DEBUG:'1',DB_PATH:'/game-test/storage/rental.sqlite'},$_SERVER:{SCRIPT_NAME:'/huur-module/game.php'},...options});assert.equal(r.errors,'',r.errors);assert.equal(r.exitCode,0,r.text);return r;}
 async function helper(expression){return JSON.parse((await run("require '/game-test/app/bootstrap.php';require '/game-test/app/secret_game.php';echo json_encode("+expression+");")).text);}
 async function request(actor=null,action=null,data={}){return run("require '/game-test/game.php';",{method:action?'POST':'GET',relativeUri:'/huur-module/game.php',headers:{Cookie:actor?.cookie||'','Content-Type':'application/x-www-form-urlencoded'},body:action?Buffer.from(new URLSearchParams({_token:actor?.token||'',action,...data}).toString()):undefined});}
 async function post(actor,action,data={}){const r=await request(actor,action,data);return {status:r.httpStatusCode,data:JSON.parse(r.text)};}
 await run(`require '/game-test/app/bootstrap.php';db()->exec(file_get_contents('/game-test/schema.sql'));db()->exec("INSERT INTO users(id,name,email,password_hash,role) VALUES(1,'Warre','warre@example.test','fixture','admin'),(2,'Berten','berten@example.test','fixture','staff'),(3,'Other admin','admin@example.test','fixture','admin'),(4,'Staff','staff@example.test','fixture','staff'),(5,'Finance','finance@example.test','fixture','finance');");`);
 async function actor(id){const r=await run(`require '/game-test/app/bootstrap.php';$_SESSION['user']=db()->query('SELECT id,name,email,role FROM users WHERE id=${id}')->fetch();echo csrf_token();`);return{cookie:r.headers['set-cookie'][0].split(';')[0],token:r.text};}
 const warre=await actor(1),berten=await actor(2),other=await actor(3),staff=await actor(4),finance=await actor(5);
 check((await request()).httpStatusCode===302,'Anonymous login required');
 check((await request(berten)).httpStatusCode===403,'Unconfigured game closed to staff');
 const setup=await request(warre);check(setup.httpStatusCode===200&&setup.text.includes('setup-form'),'Admin gets account picker');
 check(setup.headers['cache-control'][0].includes('no-store'),'Private game not cached');
 check((await request(warre,'setup',{warre:1,berten:2,_token:'wrong'})).httpStatusCode===419,'Setup protected by CSRF');
 check((await post(warre,'setup',{warre:1,berten:1})).status===422,'Duplicate players rejected');
 check((await post(warre,'setup',{warre:1,berten:5})).status===422,'Finance cannot be selected');
 check((await post(warre,'setup',{warre:1,berten:2})).status===200,'Two real accounts can be configured');
 check((await request(other)).httpStatusCode===403,'Unselected admins cannot enter configured game');
 check((await request(staff)).httpStatusCode===403,'Unselected staff cannot enter');
 check((await request(finance)).httpStatusCode===302,'Existing finance boundary retained');
 check((await request(berten)).httpStatusCode===200,'Selected staff can enter');
 await run("require '/game-test/app/bootstrap.php';db()->exec('UPDATE users SET active=0 WHERE id=2');");
 check((await request(berten)).httpStatusCode===403,'Deactivated account with stale session rejected');
 await run("require '/game-test/app/bootstrap.php';db()->exec('UPDATE users SET active=1 WHERE id=2');");
 const a=await post(warre,'start'),b=await post(berten,'start');
 check(a.status===200&&b.status===200&&a.data.seed===b.data.seed,'Same daily challenge for both players');
 check((await post(warre,'start')).status===422,'Concurrent unfinished attempt blocked');
 check((await post(berten,'finish',{id:a.data.id,moves:'[]'})).status===422,'Attempt ownership checked');
 check((await post(warre,'finish',{id:a.data.id,moves:'not json'})).status===422,'Malformed replay rejected');
 const finish=await post(warre,'finish',{id:a.data.id,moves:'[]',score:999999});
 check(finish.status===200&&finish.data.score===0,'Client supplied score ignored');
 check((await post(warre,'finish',{id:a.data.id,moves:'[]'})).data.score===0,'Finish is idempotent');
 for(let i=0;i<2;i++){const attempt=await post(warre,'start');check(attempt.status===200,'Remaining daily attempt available');await post(warre,'finish',{id:attempt.data.id,moves:'[]'});}
 check((await post(warre,'start')).status===422,'Fourth attempt rejected');
 const day=await helper('sg_day()');
 await run(`require '/game-test/app/bootstrap.php';require '/game-test/app/secret_game.php';sg_db()->exec("UPDATE attempts SET started=started-200 WHERE user_id=2");`);
 check((await post(berten,'finish',{id:b.data.id,moves:'[]'})).status===422,'Expired round cannot score');
 const fast=await post(berten,'start');
 await run(`require '/game-test/app/bootstrap.php';require '/game-test/app/secret_game.php';sg_db()->exec("UPDATE attempts SET game='snake' WHERE id='${fast.data.id}'");`);
 check((await post(berten,'finish',{id:fast.data.id,moves:JSON.stringify(Array(600).fill(0))})).status===422,'Impossible realtime replay rejected');
 await run(`require '/game-test/app/bootstrap.php';require '/game-test/app/secret_game.php';sg_db()->exec("UPDATE attempts SET day='2020-01-01' WHERE id='${fast.data.id}'");`);
 check((await post(berten,'finish',{id:fast.data.id,moves:'[]'})).status===422,'Old-day attempt cannot score');
 const kinds=await helper("array_map('sg_kind',['2026-09-24','2026-09-25','2026-09-26','2026-09-27','2026-09-28'])");
 check(new Set(kinds.slice(0,4)).size===4&&kinds[0]===kinds[4],'Four-game daily rotation');
 const challenge=await helper("sg_challenge('hangman',123)");
 const letters=[...new Set(challenge.word)];
 const encoded=Buffer.from(JSON.stringify(letters)).toString('base64');
 check(await helper(`sg_score('hangman',123,json_decode(base64_decode('${encoded}'),true))`)===1000,'Solved hangman server score');
 check(await helper("sg_score('hangman',123,[]) ")===0,'Unsolved hangman scores zero');
 for(const seed of [1,17,123,123456]){
  const puzzle=await helper(`sg_challenge('wordsearch',${seed})`),lines=[];
  for(const word of puzzle.words){let found=null;for(let y=0;y<12;y++)for(let x=0;x<12;x++)for(const [dy,dx]of [[0,1],[0,-1],[1,0],[-1,0]]){let text='';for(let i=0;i<word.length;i++)text+=puzzle.grid[y+i*dy]?.[x+i*dx]||'';if(text===word)found=[y,x,y+(word.length-1)*dy,x+(word.length-1)*dx];}assert.ok(found);lines.push(found);}
  const data=Buffer.from(JSON.stringify([...lines,lines[0]])).toString('base64');check(await helper(`sg_score('wordsearch',${seed},json_decode(base64_decode('${data}'),true))`)===800,'All wordsearch words present and duplicate selections not scored');
 }
 for(let seed=1;seed<=12;seed++){
  const engine=snakeEngine(seed),moves=[];
  while(!engine.over&&moves.length<100){
   const target=engine.food,queue=[[engine.body[0],[]]],seen=new Set();let pathToFood=null;
   while(queue.length){const [pos,trace]=queue.shift(),key=pos.join(',');if(seen.has(key))continue;seen.add(key);if(pos[0]===target[0]&&pos[1]===target[1]){pathToFood=trace;break;}for(const [dir,d]of [[0,[1,0]],[1,[0,1]],[2,[-1,0]],[3,[0,-1]]]){const p=[pos[0]+d[0],pos[1]+d[1]];if(Math.min(...p)<0||Math.max(...p)>=16||engine.body.slice(0,-1).some(b=>b[0]===p[0]&&b[1]===p[1]))continue;queue.push([p,[...trace,dir]]);}}
   const move=pathToFood?.[0]??engine.direction;moves.push(move);engine.tick(move);
  }
  check(engine.score>0,'Snake fixture eats food');
  const data=Buffer.from(JSON.stringify(moves)).toString('base64');check(await helper(`sg_score('snake',${seed},json_decode(base64_decode('${data}'),true))`)===engine.score,'Snake browser and server agree');
 }
 // Exercise rotations, wall collisions and hard drops with deterministic traces.
 for(let seed=1;seed<=20;seed++){
  const engine=tetrisEngine(seed),moves=[];let state=seed;
  for(let i=0;i<180&&!engine.over;i++){state=(state*48271)%2147483647;const input=Array.from({length:state%5},(_,j)=>(state+j)%4);moves.push(input);engine.tick(input);}
  const data=Buffer.from(JSON.stringify(moves)).toString('base64');check(await helper(`sg_score('tetris',${seed},json_decode(base64_decode('${data}'),true))`)===engine.score,'Tetris browser and server agree');
 }
 // Deliberately build complete rows, so positive Tetris scoring is covered too.
 const blocks=tetrisEngine(42),blockMoves=[];
 while(!blocks.over&&blockMoves.length<180){
  let shape=blocks.shape,best=null;
  for(let rotation=0;rotation<4;rotation++){
   for(let x=0;x<=10-shape[0].length;x++){
    const fits=y=>shape.every((row,dy)=>row.every((v,dx)=>!v||(y+dy<20&&!blocks.board[y+dy][x+dx])));
    if(!fits(0))continue;let y=0;while(fits(y+1))y++;
    const board=blocks.board.map(row=>row.slice());shape.forEach((row,dy)=>row.forEach((v,dx)=>{if(v)board[y+dy][x+dx]=1;}));
    const remaining=board.filter(row=>row.includes(0)),cleared=20-remaining.length;let holes=0,height=0;
    for(let col=0;col<10;col++){let hit=false;for(let row=0;row<remaining.length;row++){if(remaining[row][col]){if(!hit)height+=remaining.length-row;hit=true;}else if(hit)holes++;}}
    const cost=holes*100+height-cleared*40;if(!best||cost<best.cost)best={cost,x,rotation};
   }shape=shape[0].map((_,x)=>shape.map(row=>row[x]).reverse());
  }
  if(!best)break;const commands=[...Array(best.rotation).fill(2),...Array(Math.abs(best.x-blocks.x)).fill(best.x<blocks.x?0:1),3];
  while(commands.length&&!blocks.over&&blockMoves.length<180){const tick=commands.splice(0,4);blockMoves.push(tick);blocks.tick(tick);}
 }
 check(blocks.score>0,'Tetris fixture clears rows');
 const blockData=Buffer.from(JSON.stringify(blockMoves)).toString('base64');check(await helper(`sg_score('tetris',42,json_decode(base64_decode('${blockData}'),true))`)===blocks.score,'Positive Tetris scores match server replay');
 check(Object.keys(await helper("sg_public_challenge('hangman',123)")).join(',')==='length','Hangman start never exposes the answer');
 const forged=await post(berten,'start');
 await run(`require '/game-test/app/bootstrap.php';require '/game-test/app/secret_game.php';sg_db()->exec("UPDATE attempts SET game='hangman',seed=123 WHERE id='${forged.data.id}'");`);
 check((await post(berten,'finish',{id:forged.data.id,moves:JSON.stringify(letters)})).data.score===0,'Forged hangman letters cannot bypass recorded guesses');
 const actual=await post(berten,'start');
 await run(`require '/game-test/app/bootstrap.php';require '/game-test/app/secret_game.php';sg_db()->exec("UPDATE attempts SET game='hangman',seed=123 WHERE id='${actual.data.id}'");`);
 check((await post(warre,'guess',{id:actual.data.id,letter:'A'})).status===422,'Guesses enforce ownership');
 check((await post(berten,'guess',{id:actual.data.id,letter:'AB'})).status===422,'Guess is one letter');
 const first=await post(berten,'guess',{id:actual.data.id,letter:letters[0]});
 check(first.data.state.mask.includes('_')&&!Object.hasOwn(first.data.state,'word'),'Unsolved response hides remaining answer');
 const repeat=await post(berten,'guess',{id:actual.data.id,letter:letters[0]});check(repeat.data.state.letters.length===1,'Retrying a guess is idempotent');
 for(const letter of letters.slice(1))await post(berten,'guess',{id:actual.data.id,letter});
 check((await post(berten,'finish',{id:actual.data.id,moves:'[]'})).data.score===1000,'Stored guesses determine solved score');
 const historic=await helper(`(function(){ $pdo=sg_db();$players=$pdo->query('SELECT * FROM players')->fetch();$today=sg_day();$yesterday=(new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');$before=(new DateTimeImmutable($today))->modify('-2 days')->format('Y-m-d');$insert=$pdo->prepare('INSERT INTO attempts(id,user_id,day,game,seed,started,finished,score,elapsed) VALUES(?,?,?,"snake",1,1,1,?,?)');foreach([['hist1',1,$yesterday,100,5000],['hist2',2,$yesterday,100,6000],['hist3',1,$before,50,10000],['hist4',2,$before,50,10000]] as $row)$insert->execute($row);return sg_board($pdo,$players,$today);})()`);
 check(historic.today[0].wins===1&&historic.today[1].wins===0,'Day wins use score then time and exclude today');
 check(historic.history[1].winner==='Gelijkspel','Exact ties do not award a win');
 const invalid=JSON.parse((await run(`require '/game-test/app/bootstrap.php';require '/game-test/app/secret_game.php';$bad=0;foreach([['snake',[9]],['snake',array_fill(0,601,0)],['tetris',[[8]]],['tetris',array_fill(0,181,[])],['wordsearch',[[99,0,1,1]]],['hangman',['AB']]] as [$kind,$moves]){try{sg_score($kind,1,$moves);}catch(InvalidArgumentException){$bad++;}}echo json_encode($bad);`)).text);
 check(invalid===6,'Invalid and oversized replays rejected');
 check(!php.fileExists('/game-test/storage/private/mail'),'No mail produced');
 console.log(`PASS ${checks} secret-game checks: access, setup, attempts, score replay, ownership, expiry and PHP/JS parity.`);
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(()=>{try{php?.exit();}catch{}process.exit(process.exitCode||0);});
