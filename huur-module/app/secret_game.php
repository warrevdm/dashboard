<?php

declare(strict_types=1);

// Loaded only by the hidden game route; ordinary rental pages do no game work.
function sg_db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $directory = ROOT_PATH . '/storage/private';
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('De spelopslag is niet beschikbaar.');
    }
    $pdo = new PDO('sqlite:' . $directory . '/secret-game.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS players (id INTEGER PRIMARY KEY CHECK(id=1), warre INTEGER NOT NULL, berten INTEGER NOT NULL, secret TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS attempts (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, day TEXT NOT NULL, game TEXT NOT NULL, seed INTEGER NOT NULL, started REAL NOT NULL, finished INTEGER NOT NULL DEFAULT 0, score INTEGER NOT NULL DEFAULT 0, elapsed INTEGER NOT NULL DEFAULT 120000, guesses TEXT NOT NULL DEFAULT \'[]\');
        CREATE INDEX IF NOT EXISTS attempts_day_user ON attempts(day,user_id)');
    return $pdo;
}

function sg_day(): string { return (new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels')))->format('Y-m-d'); }
function sg_kind(string $day): string
{
    $days = (new DateTimeImmutable('2026-01-01'))->diff(new DateTimeImmutable($day))->days;
    return ['hangman', 'snake', 'tetris', 'wordsearch'][$days % 4];
}
function sg_random(int &$seed, int $max): int { $seed = ($seed * 48271) % 2147483647; return $seed % $max; }
function sg_words(): array { return ['FIETS', 'KETTING', 'PEDAAL', 'ZADEL', 'STUUR', 'BAND', 'REM', 'WIEL', 'FRAME', 'HELM', 'TRAPPER', 'SPAAK', 'BEL', 'POMP', 'TRAPPEN', 'WERKPLAATS', 'VERSNELLING', 'BAGAGEDRAGER', 'FIETSPAD', 'VOORVORK', 'ACHTERLICHT', 'RACEFIETS', 'MOUNTAINBIKE', 'FIETSTAS']; }
function sg_challenge(string $kind, int $seed): array
{
    $words = sg_words();
    if ($kind === 'hangman') return ['word' => $words[sg_random($seed, count($words))]];
    if ($kind !== 'wordsearch') return [];
    // Shuffle, then place eight words in distinct rows with random direction/offset.
    // Remaining letters camouflage the words; validation also accepts other true occurrences.
    for ($i=count($words)-1; $i>0; $i--) { $j=sg_random($seed,$i+1); [$words[$i],$words[$j]]=[$words[$j],$words[$i]]; }
    $words=array_slice($words,0,8); $grid=[];
    for ($y=0;$y<12;$y++) { $grid[$y]=[]; for ($x=0;$x<12;$x++) $grid[$y][$x]=chr(65+sg_random($seed,26)); }
    $rows=range(0,11);
    for ($i=11;$i>0;$i--) { $j=sg_random($seed,$i+1); [$rows[$i],$rows[$j]]=[$rows[$j],$rows[$i]]; }
    foreach ($words as $i=>$word) { if(sg_random($seed,2)) $word=strrev($word); $x=sg_random($seed,13-strlen($word)); foreach(str_split($word) as $k=>$letter) $grid[$rows[$i]][$x+$k]=$letter; }
    if (sg_random($seed,2)) { $transposed=[];for($y=0;$y<12;$y++)for($x=0;$x<12;$x++)$transposed[$y][$x]=$grid[$x][$y];$grid=$transposed; }
    return ['words'=>$words,'grid'=>$grid];
}
function sg_food(array $snake, int &$seed): array
{
    $free=[];for($y=0;$y<16;$y++)for($x=0;$x<16;$x++)if(!in_array([$x,$y],$snake,true))$free[]=[$x,$y];
    return $free ? $free[sg_random($seed,count($free))] : [-1,-1];
}
function sg_shapes(): array { return [[[1,1,1,1]],[[1,1],[1,1]],[[0,1,0],[1,1,1]],[[0,1,1],[1,1,0]],[[1,1,0],[0,1,1]],[[1,0,0],[1,1,1]],[[0,0,1],[1,1,1]]]; }
function sg_rotate(array $shape): array
{
    $result=[];for($x=0;$x<count($shape[0]);$x++){ $result[$x]=[];for($y=count($shape)-1;$y>=0;$y--)$result[$x][]=$shape[$y][$x]; }return $result;
}
function sg_fits(array $board, array $shape, int $x, int $y): bool
{
    foreach($shape as $dy=>$row)foreach($row as $dx=>$cell)if($cell && ($x+$dx<0 || $x+$dx>=10 || $y+$dy<0 || $y+$dy>=20 || $board[$y+$dy][$x+$dx]))return false;
    return true;
}
function sg_score(string $kind, int $seed, array $moves): int
{
    if (!array_is_list($moves)) throw new InvalidArgumentException('Ongeldige spelgegevens.');
    $challenge=sg_challenge($kind,$seed);
    if ($kind==='hangman') {
        if(count($moves)>26)throw new InvalidArgumentException('Te veel letters.');
        $seen=[];$wrong=0;
        foreach($moves as $letter){
            if(!is_string($letter)||!preg_match('/^[A-Z]$/D',$letter)||in_array($letter,$seen,true))throw new InvalidArgumentException('Ongeldige letter.');
            $seen[]=$letter;if(!str_contains($challenge['word'],$letter))$wrong++;
            if(!array_diff(str_split($challenge['word']),$seen))return 1000-100*$wrong;
            if($wrong>=6)return 0;
        }return 0;
    }
    if ($kind==='wordsearch') {
        if(count($moves)>100)throw new InvalidArgumentException('Te veel selecties.');$found=[];
        foreach($moves as $line){
            if(!is_array($line)||!array_is_list($line)||count($line)!==4)throw new InvalidArgumentException('Ongeldige selectie.');
            foreach($line as $n)if(!is_int($n)||$n<0||$n>=12)throw new InvalidArgumentException('Ongeldig vakje.');
            [$y,$x,$ey,$ex]=$line;$dy=$ey-$y;$dx=$ex-$x;
            if($dy!==0 && $dx!==0 && abs($dy)!==abs($dx))continue;
            $word='';$length=max(abs($dy),abs($dx));for($i=0;$i<=$length;$i++)$word.=$challenge['grid'][$y+$i*($dy<=>0)][$x+$i*($dx<=>0)];
            foreach([$word,strrev($word)] as $candidate)if(in_array($candidate,$challenge['words'],true))$found[$candidate]=true;
        }return count($found)*100;
    }
    if ($kind==='snake') {
        if(count($moves)>600)throw new InvalidArgumentException('Te lange ronde.');
        $snake=[[6,8],[5,8],[4,8]];$direction=0;$food=sg_food($snake,$seed);$score=0;$delta=[[1,0],[0,1],[-1,0],[0,-1]];
        foreach($moves as $move){
            if(!is_int($move)||$move<0||$move>3)throw new InvalidArgumentException('Ongeldige richting.');
            if($move!==($direction+2)%4)$direction=$move;
            $head=[$snake[0][0]+$delta[$direction][0],$snake[0][1]+$delta[$direction][1]];$eat=$head===$food;
            $body=$snake;if(!$eat)array_pop($body);
            if(min($head)<0||max($head)>=16||in_array($head,$body,true))break;
            array_unshift($body,$head);$snake=$body;
            if($eat){$score+=10;$food=sg_food($snake,$seed);if($food===[-1,-1])break;}
        }return $score;
    }
    if ($kind==='tetris') {
        if(count($moves)>180)throw new InvalidArgumentException('Te lange ronde.');
        $board=array_fill(0,20,array_fill(0,10,0));$shapes=sg_shapes();$shape=$shapes[sg_random($seed,7)];$x=3;$y=0;$score=0;
        foreach($moves as $tick){
            if(!is_array($tick)||!array_is_list($tick)||count($tick)>4)throw new InvalidArgumentException('Ongeldige bewegingen.');
            foreach($tick as $cmd)if(!is_int($cmd)||$cmd<0||$cmd>3)throw new InvalidArgumentException('Ongeldig commando.');
            $drop=false;
            foreach($tick as $cmd){
                if($cmd===0 && sg_fits($board,$shape,$x-1,$y))$x--;
                if($cmd===1 && sg_fits($board,$shape,$x+1,$y))$x++;
                if($cmd===2){$turned=sg_rotate($shape);if(sg_fits($board,$turned,$x,$y))$shape=$turned;}
                if($cmd===3){while(sg_fits($board,$shape,$x,$y+1))$y++;$drop=true;break;}
            }
            if(!$drop && sg_fits($board,$shape,$x,$y+1)){$y++;continue;}
            foreach($shape as $dy=>$row)foreach($row as $dx=>$cell)if($cell)$board[$y+$dy][$x+$dx]=1;
            $remaining=array_values(array_filter($board,static fn($row)=>in_array(0,$row,true)));$lines=20-count($remaining);
            $score += [0,100,300,500,800][$lines];
            $board=array_merge(array_fill(0,$lines,array_fill(0,10,0)),$remaining);
            $shape=$shapes[sg_random($seed,7)];$x=3;$y=0;if(!sg_fits($board,$shape,$x,$y))break;
        }return $score;
    }
    throw new InvalidArgumentException('Onbekend spel.');
}
function sg_board(PDO $pdo, array $players, string $day): array
{
    $stmt=$pdo->prepare('SELECT user_id,day,score,elapsed FROM attempts WHERE day>=:since AND day<=:day AND finished=1 ORDER BY score DESC,elapsed ASC');
    $stmt->execute([':since'=>(new DateTimeImmutable($day))->modify('-30 days')->format('Y-m-d'),':day'=>$day]);
    $best=[];$ids=[(int)$players['warre'],(int)$players['berten']];
    foreach($stmt as $r)if(in_array((int)$r['user_id'],$ids,true) && !isset($best[$r['day']][$r['user_id']]))$best[$r['day']][$r['user_id']]=$r;
    $wins=array_fill_keys($ids,0);$history=[];krsort($best);
    foreach($best as $date=>$scores){
        $a=$scores[$ids[0]]??null;$b=$scores[$ids[1]]??null;$winner=null;
        if($a && !$b)$winner=$ids[0];elseif($b && !$a)$winner=$ids[1];elseif($a && $b){$cmp=($a['score']<=>$b['score'])?:($b['elapsed']<=>$a['elapsed']);if($cmp)$winner=$cmp>0?$ids[0]:$ids[1];}
        if($date<$day){if($winner!==null)$wins[$winner]++;if(count($history)<7)$history[]=['day'=>$date,'game'=>sg_kind($date),'winner'=>$winner===null?'Gelijkspel':($winner===$ids[0]?'Warre':'Berten')];}
    }
    $today=[];foreach($ids as $i=>$id){$count=$pdo->prepare('SELECT COUNT(*) FROM attempts WHERE day=? AND user_id=?');$count->execute([$day,$id]);$today[]=['id'=>$id,'name'=>$i===0?'Warre':'Berten','best'=>$best[$day][$id]??null,'attempts'=>(int)$count->fetchColumn(),'wins'=>$wins[$id]];}
    return ['today'=>$today,'history'=>$history];
}

function sg_public_challenge(string $kind, int $seed): array
{
    $challenge=sg_challenge($kind,$seed);
    return $kind==='hangman'?['length'=>strlen($challenge['word'])]:$challenge;
}
function sg_hangman_state(int $seed, array $letters): array
{
    $word=sg_challenge('hangman',$seed)['word'];
    $wrong=count(array_filter($letters,static fn($letter)=>!str_contains($word,$letter)));
    $won=!array_diff(str_split($word),$letters);$ended=$won||$wrong>=6;
    $mask=implode(' ',array_map(static fn($letter)=>($ended||in_array($letter,$letters,true))?$letter:'_',str_split($word)));
    return ['letters'=>$letters,'wrong'=>$wrong,'mask'=>$mask,'ended'=>$ended,'score'=>$won?1000-100*$wrong:0];
}
