'use strict';
(() => {
  const rng = seed => max => { seed = (seed * 48271) % 2147483647; return seed % max; };
  const shapes = [[[1,1,1,1]],[[1,1],[1,1]],[[0,1,0],[1,1,1]],[[0,1,1],[1,1,0]],[[1,1,0],[0,1,1]],[[1,0,0],[1,1,1]],[[0,0,1],[1,1,1]]];
  function snakeEngine(seed) {
    const random=rng(seed), s={body:[[6,8],[5,8],[4,8]],direction:0,food:null,score:0,over:false};
    function food(){const free=[];for(let y=0;y<16;y++)for(let x=0;x<16;x++)if(!s.body.some(p=>p[0]===x&&p[1]===y))free.push([x,y]);s.food=free.length?free[random(free.length)]:[-1,-1];}
    food();s.tick=move=>{
      if(s.over)return;if(move!==(s.direction+2)%4)s.direction=move;
      const d=[[1,0],[0,1],[-1,0],[0,-1]][s.direction],head=[s.body[0][0]+d[0],s.body[0][1]+d[1]];
      const eat=head[0]===s.food[0]&&head[1]===s.food[1],body=s.body.slice();if(!eat)body.pop();
      if(Math.min(...head)<0||Math.max(...head)>=16||body.some(p=>p[0]===head[0]&&p[1]===head[1])){s.over=true;return;}
      body.unshift(head);s.body=body;if(eat){s.score+=10;food();if(s.food[0]===-1)s.over=true;}
    };return s;
  }
  function tetrisEngine(seed) {
    const random=rng(seed),s={board:Array.from({length:20},()=>Array(10).fill(0)),shape:shapes[random(7)],x:3,y:0,score:0,over:false};
    const fits=(shape,x,y)=>shape.every((row,dy)=>row.every((cell,dx)=>!cell||(x+dx>=0&&x+dx<10&&y+dy>=0&&y+dy<20&&!s.board[y+dy][x+dx])));
    s.tick=commands=>{
      if(s.over)return;let drop=false;
      for(const cmd of commands){
        if(cmd===0&&fits(s.shape,s.x-1,s.y))s.x--;
        if(cmd===1&&fits(s.shape,s.x+1,s.y))s.x++;
        if(cmd===2){const turned=s.shape[0].map((_,x)=>s.shape.map(row=>row[x]).reverse());if(fits(turned,s.x,s.y))s.shape=turned;}
        if(cmd===3){while(fits(s.shape,s.x,s.y+1))s.y++;drop=true;break;}
      }
      if(!drop&&fits(s.shape,s.x,s.y+1)){s.y++;return;}
      s.shape.forEach((row,dy)=>row.forEach((cell,dx)=>{if(cell)s.board[s.y+dy][s.x+dx]=1;}));
      const remaining=s.board.filter(row=>row.includes(0)),lines=20-remaining.length;s.score+=[0,100,300,500,800][lines];
      s.board=[...Array.from({length:lines},()=>Array(10).fill(0)),...remaining];s.shape=shapes[random(7)];s.x=3;s.y=0;if(!fits(s.shape,s.x,s.y))s.over=true;
    };return s;
  }
  if(typeof module==='object')module.exports={snakeEngine,tetrisEngine};
  if(typeof document==='undefined')return;
  const root=document.getElementById('arcade');if(!root)return;
  const boot=JSON.parse(root.dataset.boot),token=root.dataset.token,$=id=>document.getElementById(id),message=$('message');
  const names={hangman:'Galgje',snake:'Snake',tetris:'Tetris',wordsearch:'Woordzoeker'};
  const instructions={hangman:'Raad het fietswoord met maximaal 6 foute letters. Typ of klik een letter. Opgelost: 1.000 punten, min 100 per fout.',snake:'Eet het rode blokje. Gebruik de pijltjestoetsen of de knoppen. Een botsing stopt de ronde. Elk hapje: 10 punten. Maximaal 90 seconden.',tetris:'Vul volledige rijen. ← → bewegen · ↑ draaien · spatie of ↓ laten vallen. Bewegingen worden elke halve seconde uitgevoerd. Rijen: 100 / 300 / 500 / 800 punten. Maximaal 90 seconden.',wordsearch:'Zoek 8 fietswoorden. Klik eerst de beginletter, dan de eindletter. Woorden kunnen ook achterstevoren staan. Elk woord: 100 punten.'};
  function say(text){message.textContent=text;}
  async function api(action,data={}){
    const response=await fetch('game.php',{method:'POST',credentials:'same-origin',keepalive:true,headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({_token:token,action,...data})});
    let value;try{value=await response.json();}catch{throw new Error('Je sessie is mogelijk verlopen. Herlaad de pagina.');}
    if(!response.ok||!value.ok){const error=new Error(value.error||'Deze actie kon niet worden uitgevoerd.');error.status=response.status;throw error;}return value;
  }
  if(boot.setup){$('setup-form').addEventListener('submit',async event=>{event.preventDefault();const button=event.target.querySelector('button');button.disabled=true;try{await api('setup',Object.fromEntries(new FormData(event.target)));location.reload();}catch(error){say(error.message);button.disabled=false;}});return;}
  let running=false,attempt=null,moves=[],engine=null,tickTimer=null,clockTimer=null,started=0,pending=[],direction=0,letters=[],selected=null,found=new Set(),pendingSave=null,hangmanRequest=null;
  const screen=$('game-screen'),startButton=$('start-game'),finishButton=$('finish-game'),retryButton=$('retry-save');
  function el(tag,text,className){const node=document.createElement(tag);if(text!==undefined)node.textContent=text;if(className)node.className=className;return node;}
  function board(data){
    boot.board=data;$('leaderboard').replaceChildren();$('wins').replaceChildren();
    data.today.forEach(player=>{const row=el('div',undefined,'player-row');row.append(el('strong',player.name+(player.id===boot.user?' / jij':'')),el('span',player.best?`${player.best.score} pt · ${(player.best.elapsed/1000).toFixed(1)}s`:'Nog niet gespeeld'),el('small',`${player.attempts}/3 pogingen`));$('leaderboard').append(row);const wins=el('div',undefined,'win-row');wins.append(el('span',player.name),el('strong',String(player.wins)));$('wins').append(wins);});
    $('history').replaceChildren();data.history.forEach(day=>$('history').append(el('p',`${day.day.slice(5)} · ${names[day.game]} · ${day.winner}`,'history-row')));
    startButton.disabled=running||Boolean(pendingSave)||data.today.find(p=>p.id===boot.user)?.attempts>=3;
  }
  board(boot.board);$('instructions').textContent=instructions[boot.game];
  $('refresh-board').addEventListener('click',async()=>{try{const r=await fetch('game.php?board=1',{credentials:'same-origin',cache:'no-store'});const data=await r.json();if(!r.ok||!data.ok)throw Error('Scores niet beschikbaar. Herlaad de pagina.');if(data.day!==boot.day&&!running){location.reload();return;}board(data.board);}catch(error){say(error.message);}});
  function score(value){$('live-score').textContent=`${value} punten`;}
  function stopTimers(){clearInterval(tickTimer);clearInterval(clockTimer);}
  async function save(){
    retryButton.hidden=true;
    try{const result=await api('finish',pendingSave);pendingSave=null;board(result.board);score(result.score);say(`Ronde opgeslagen: ${result.score} punten. Jouw beste poging telt.`);}
    catch(error){say(error.message);if(error.status===422){pendingSave=null;board(boot.board);}else retryButton.hidden=false;}
  }
  function finish(){if(!running)return;running=false;stopTimers();finishButton.hidden=true;startButton.disabled=true;say('Score wordt gecontroleerd en opgeslagen…');Promise.resolve(hangmanRequest).finally(()=>{pendingSave={id:attempt.id,moves:JSON.stringify(moves)};save();});}
  finishButton.addEventListener('click',finish);retryButton.addEventListener('click',save);
  function draw(){
    const canvas=$('arcade-canvas'),ctx=canvas.getContext('2d');ctx.fillStyle='#10251c';ctx.fillRect(0,0,canvas.width,canvas.height);
    const block=(x,y,color)=>{ctx.fillStyle=color;ctx.fillRect(x*20+1,y*20+1,18,18);};
    if(attempt.game==='snake'){engine.body.forEach((p,i)=>block(...p,i?'#64bf72':'#c5ff75'));block(...engine.food,'#ff826e');}
    else{engine.board.forEach((row,y)=>row.forEach((cell,x)=>{if(cell)block(x,y,'#66ad89');}));engine.shape.forEach((row,y)=>row.forEach((cell,x)=>{if(cell)block(engine.x+x,engine.y+y,'#c5ff75');}));}
    score(engine.score);
  }
  function controls(items){const box=el('div',undefined,'touch-controls');for(const [label,cmd]of items){const button=el('button',label,'secondary');button.type='button';button.addEventListener('click',()=>command(cmd));box.append(button);}screen.append(box);}
  function command(cmd){if(!running)return;if(attempt.game==='snake')direction=cmd;else if(attempt.game==='tetris'&&pending.length<4)pending.push(cmd);}
  function arcade(){
    const snake=attempt.game==='snake';engine=snake?snakeEngine(attempt.seed):tetrisEngine(attempt.seed);direction=0;pending=[];
    const canvas=el('canvas');canvas.id='arcade-canvas';canvas.width=snake?320:200;canvas.height=snake?320:400;canvas.setAttribute('aria-label',snake?'Snake speelveld':'Tetris speelveld');screen.append(canvas);
    controls(snake?[['←',2],['↑',3],['↓',1],['→',0]]:[['←',0],['Draai ↻',2],['→',1],['Laat vallen ↓',3]]);draw();
    tickTimer=setInterval(()=>{if(!running)return;const input=snake?direction:pending.splice(0,4);moves.push(input);engine.tick(input);draw();if(engine.over||moves.length>=(snake?600:180))finish();},snake?150:500);
  }
  function hangman(){
    letters=[];hangmanRequest=null;
    const lives=el('p','6 kansen over · ●●●●●●','lives'),display=el('div',Array(attempt.challenge.length).fill('_').join(' '),'word-display'),keyboard=el('div',undefined,'alphabet');screen.append(lives,display,keyboard);
    for(const letter of 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'){
      const button=el('button',letter,'letter');button.type='button';button.dataset.letter=letter;
      button.addEventListener('click',()=>{
        if(!running||hangmanRequest||letters.includes(letter))return;button.disabled=true;
        hangmanRequest=api('guess',{id:attempt.id,letter}).then(result=>{
          const state=result.state;letters=state.letters;moves=[...letters];display.textContent=state.mask;
          lives.textContent=`${6-state.wrong} kansen over · ${'●'.repeat(6-state.wrong)}${'○'.repeat(state.wrong)}`;
          for(const key of keyboard.children)key.disabled=letters.includes(key.dataset.letter);
          score(state.score);if(state.ended)finish();
        }).catch(error=>{say(error.message);button.disabled=false;}).finally(()=>{hangmanRequest=null;});
      });keyboard.append(button);
    }
  }
  function wordsearch(){
    found=new Set();selected=null;const grid=el('div',undefined,'word-grid'),list=el('div',undefined,'word-list');screen.append(grid,list);
    attempt.challenge.words.forEach(word=>{const label=el('span',word);label.dataset.word=word;list.append(label);});
    attempt.challenge.grid.forEach((row,y)=>row.forEach((letter,x)=>{const button=el('button',letter,'grid-letter');button.type='button';button.setAttribute('aria-label',`${letter}, rij ${y+1}, kolom ${x+1}`);button.addEventListener('click',()=>{
      if(!running)return;if(!selected){selected=[y,x,button];button.classList.add('selected');return;}
      const [sy,sx,previous]=selected;previous.classList.remove('selected');selected=null;const dy=y-sy,dx=x-sx;
      if(dy&&dx&&Math.abs(dy)!==Math.abs(dx)){say('Kies een rechte lijn.');return;}
      const line=[sy,sx,y,x];moves.push(line);let word='';const cells=[];for(let i=0;i<=Math.max(Math.abs(dy),Math.abs(dx));i++){const cy=sy+i*Math.sign(dy),cx=sx+i*Math.sign(dx);word+=attempt.challenge.grid[cy][cx];cells.push(grid.children[cy*12+cx]);}
      const match=attempt.challenge.words.find(w=>w===word||w===[...word].reverse().join(''));
      if(match){found.add(match);cells.forEach(cell=>cell.classList.add('found'));[...list.children].find(node=>node.dataset.word===match).classList.add('found');say(`${match} gevonden!`);}else say('Dat is geen woord uit de lijst.');
      score(found.size*100);if(found.size===8||moves.length>=100)finish();
    });grid.append(button);}));
  }
  startButton.addEventListener('click',async()=>{
    if(running||pendingSave)return;startButton.disabled=true;say('Uitdaging laden…');
    try{attempt=await api('start');running=true;moves=[];started=performance.now();boot.game=attempt.game;boot.day=attempt.day;board(attempt.board);$('game-name').textContent=names[attempt.game];$('day-label').textContent=`${attempt.day} / DAILY CHALLENGE`;$('instructions').textContent=instructions[attempt.game];screen.replaceChildren();finishButton.hidden=false;score(0);say('Succes. Maak er een topscore van!');
      if(attempt.game==='hangman')hangman();else if(attempt.game==='wordsearch')wordsearch();else arcade();
      const duration=['snake','tetris'].includes(attempt.game)?90:120;
      const clock=()=>{const left=Math.max(0,duration-Math.floor((performance.now()-started)/1000));$('timer').textContent=`${String(Math.floor(left/60)).padStart(2,'0')}:${String(left%60).padStart(2,'0')}`;if(!left)finish();};clock();clockTimer=setInterval(clock,200);if(document.hidden)finish();
    }catch(error){running=false;stopTimers();say(error.message);board(boot.board);}
  });
  document.addEventListener('keydown',event=>{
    if(!running||/^(INPUT|SELECT|TEXTAREA)$/.test(event.target.tagName))return;
    if(attempt.game==='hangman'){const key=event.key.toUpperCase();if(/^[A-Z]$/.test(key))screen.querySelector(`[data-letter="${key}"]`)?.click();return;}
    if(attempt.game==='wordsearch')return;
    const map=attempt.game==='snake'?{ArrowRight:0,ArrowDown:1,ArrowLeft:2,ArrowUp:3}:{ArrowLeft:0,ArrowRight:1,ArrowUp:2,ArrowDown:3,' ':3};
    if(Object.hasOwn(map,event.key)){event.preventDefault();command(map[event.key]);}
  });
  document.addEventListener('visibilitychange',()=>{if(document.hidden)finish();});
  window.addEventListener('pagehide',finish);
})();
