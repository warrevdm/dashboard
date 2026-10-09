<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_auth();
header('Cache-Control: no-store, private');
require_once __DIR__ . '/../app/secret_game.php';
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
if(!in_array($method,['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');exit('Methode niet toegestaan.');}
// Re-check the database, so deactivated accounts cannot play on a stale login.
$userStmt=db()->prepare('SELECT id,role,active FROM users WHERE id=?');$userStmt->execute([(int)current_user()['id']]);$user=$userStmt->fetch();
if(!$user || !(int)$user['active']){http_response_code(403);exit('Geen toegang.');}
$pdo=sg_db();$players=$pdo->query('SELECT * FROM players WHERE id=1')->fetch();
$setup=!$players;
if(($setup && $user['role']!=='admin') || (!$setup && !in_array((int)$user['id'],[(int)$players['warre'],(int)$players['berten']],true))){http_response_code(403);exit('Geen toegang tot deze ruimte.');}
$token=csrf_token();
if($method==='POST') verify_csrf();
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
$day=sg_day();$game=sg_kind($day);
$names=['hangman'=>'Galgje','snake'=>'Snake','tetris'=>'Tetris','wordsearch'=>'Woordzoeker'];
if($method==='POST'){
    header('Content-Type: application/json; charset=utf-8');$transaction=false;
    try{
        $action=$_POST['action']??'';
        if($setup){
            if($action!=='setup')throw new InvalidArgumentException('Koppel eerst de twee accounts.');
            $warre=filter_var($_POST['warre']??null,FILTER_VALIDATE_INT);$berten=filter_var($_POST['berten']??null,FILTER_VALIDATE_INT);
            if(!$warre||!$berten||$warre===$berten)throw new InvalidArgumentException('Kies twee verschillende accounts.');
            $valid=db()->prepare("SELECT COUNT(*) FROM users WHERE id IN (?,?) AND active=1 AND role IN ('admin','staff')");$valid->execute([$warre,$berten]);
            if((int)$valid->fetchColumn()!==2)throw new InvalidArgumentException('Kies twee actieve verhuuraccounts.');
            $pdo->exec('BEGIN IMMEDIATE');$transaction=true;
            if($pdo->query('SELECT COUNT(*) FROM players')->fetchColumn())throw new InvalidArgumentException('De accounts zijn ondertussen gekoppeld. Herlaad de pagina.');
            $pdo->prepare('INSERT INTO players(id,warre,berten,secret) VALUES(1,?,?,?)')->execute([$warre,$berten,bin2hex(random_bytes(32))]);
            $pdo->exec('COMMIT');$transaction=false;echo json_encode(['ok'=>true]);exit;
        }
        if(!in_array($action,['start','finish','guess'],true))throw new InvalidArgumentException('Onbekende actie.');
        $pdo->exec('BEGIN IMMEDIATE');$transaction=true;$uid=(int)$user['id'];
        if($action==='start'){
            $count=$pdo->prepare('SELECT COUNT(*) FROM attempts WHERE day=? AND user_id=?');$count->execute([$day,$uid]);
            if((int)$count->fetchColumn()>=3)throw new InvalidArgumentException('Je drie pogingen voor vandaag zijn gebruikt. Morgen een nieuw spel!');
            $active=$pdo->prepare('SELECT id FROM attempts WHERE day=? AND user_id=? AND finished=0 AND started>?');$active->execute([$day,$uid,microtime(true)-150]);
            if($active->fetch())throw new InvalidArgumentException('Er loopt al een poging. Rond die af in het andere tabblad of wacht maximaal 150 seconden.');
            $seed=(int)(hexdec(substr(hash_hmac('sha256',$day,$players['secret']),0,7))+1);$id=bin2hex(random_bytes(24));
            $pdo->prepare('INSERT INTO attempts(id,user_id,day,game,seed,started) VALUES(?,?,?,?,?,?)')->execute([$id,$uid,$day,$game,$seed,microtime(true)]);
            $result=['ok'=>true,'id'=>$id,'day'=>$day,'game'=>$game,'seed'=>$game==='hangman'?null:$seed,'challenge'=>sg_public_challenge($game,$seed),'limit'=>120];
        }elseif($action==='guess'){
            $id=$_POST['id']??'';$letter=$_POST['letter']??'';
            if(!is_string($id)||!preg_match('/^[a-f0-9]{48}$/D',$id)||!is_string($letter)||!preg_match('/^[A-Z]$/D',$letter))throw new InvalidArgumentException('Kies één letter.');
            $stmt=$pdo->prepare('SELECT * FROM attempts WHERE id=? AND user_id=? AND day=?');$stmt->execute([$id,$uid,$day]);$attempt=$stmt->fetch();
            if(!$attempt||$attempt['game']!=='hangman'||(int)$attempt['finished']||microtime(true)-(float)$attempt['started']>120)throw new InvalidArgumentException('Deze ronde is gesloten of verlopen.');
            $letters=json_decode($attempt['guesses'],true,32,JSON_THROW_ON_ERROR);$state=sg_hangman_state((int)$attempt['seed'],$letters);
            if(!$state['ended']&&!in_array($letter,$letters,true)){$letters[]=$letter;$pdo->prepare('UPDATE attempts SET guesses=? WHERE id=?')->execute([json_encode($letters),$id]);$state=sg_hangman_state((int)$attempt['seed'],$letters);}
            $result=['ok'=>true,'state'=>$state];
        }else{
            $id=$_POST['id']??'';$raw=$_POST['moves']??'';
            if(!is_string($id)||!preg_match('/^[a-f0-9]{48}$/D',$id)||!is_string($raw)||strlen($raw)>32000)throw new InvalidArgumentException('Ongeldige ronde.');
            $stmt=$pdo->prepare('SELECT * FROM attempts WHERE id=? AND user_id=? AND day=?');$stmt->execute([$id,$uid,$day]);$attempt=$stmt->fetch();
            if(!$attempt)throw new InvalidArgumentException('Deze ronde bestaat niet of hoort bij een andere dag.');
            if(!(int)$attempt['finished']){
                $elapsed=(int)round((microtime(true)-(float)$attempt['started'])*1000);
                if($elapsed>150000)throw new InvalidArgumentException('Deze ronde is verlopen. Start een nieuwe poging.');
                $moves=json_decode($attempt['game']==='hangman'?$attempt['guesses']:$raw,true,32,JSON_THROW_ON_ERROR);
                if(!is_array($moves))throw new InvalidArgumentException('Ongeldige spelgegevens.');
                $step=$attempt['game']==='snake'?150:($attempt['game']==='tetris'?500:0);
                if($step && count($moves)*$step>$elapsed+2000)throw new InvalidArgumentException('De rondetijd klopt niet.');
                $score=sg_score($attempt['game'],(int)$attempt['seed'],$moves);
                $pdo->prepare('UPDATE attempts SET finished=1,score=?,elapsed=? WHERE id=?')->execute([$score,min(120000,$elapsed),$id]);
            }else $score=(int)$attempt['score'];
            $result=['ok'=>true,'score'=>$score];
        }
        $pdo->exec('COMMIT');$transaction=false;$result['board']=sg_board($pdo,$players,$day);echo json_encode($result,JSON_THROW_ON_ERROR);
    }catch(InvalidArgumentException|JsonException $error){if($transaction)$pdo->exec('ROLLBACK');http_response_code(422);echo json_encode(['ok'=>false,'error'=>$error->getMessage()]);}
    catch(Throwable $error){if($transaction)$pdo->exec('ROLLBACK');error_log('Secret game: '.$error->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Opslaan lukt tijdelijk niet. Probeer opnieuw.']);}
    exit;
}
if(isset($_GET['board']) && !$setup){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'day'=>$day,'board'=>sg_board($pdo,$players,$day)]);exit;}
$boot=['setup'=>$setup,'day'=>$day,'game'=>$game,'user'=>(int)$user['id'],'board'=>$setup?null:sg_board($pdo,$players,$day)];
$accounts=$setup?db()->query("SELECT id,name,email FROM users WHERE active=1 AND role IN ('admin','staff') ORDER BY name")->fetchAll():[];
?>
<!doctype html><html lang="nl-BE"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>After Hours · Aerts Action Bike</title><link rel="icon" href="assets/aerts-action-bike-logo.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/secret-game.css?v=1"><script src="assets/secret-game.js?v=1" defer></script></head>
<body><main id="arcade" data-boot="<?= e(json_encode($boot,JSON_THROW_ON_ERROR)) ?>" data-token="<?= e($token) ?>">
<header class="arcade-top"><a href="planning.php">← Terug naar planning</a><span>AERTS / PRIVATE ARCADE</span></header>
<section class="intro"><p class="eyebrow">ALLEEN VOOR DE CREW · WARRE × BERTEN</p><h1>AFTER <em>HOURS.</em></h1><p>Even geen dossiers. Wel de hoogste score.</p></section>
<?php if($setup): ?>
<section class="panel setup"><h2>Wie neemt het tegen elkaar op?</h2><p>Koppel eenmalig jullie bestaande accounts. Alleen die twee accounts krijgen toegang. Kies zorgvuldig; dit formulier verdwijnt na opslaan.</p><form id="setup-form">
<?php foreach(['warre'=>'Warre','berten'=>'Berten'] as $field=>$label): ?><label><?= e($label) ?><select name="<?= e($field) ?>" required><option value="">Kies een account</option><?php foreach($accounts as $account): ?><option value="<?= (int)$account['id'] ?>"><?= e($account['name'].' — '.$account['email']) ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
<button type="submit">Open de competitie →</button></form></section>
<?php else: ?>
<div class="arcade-layout"><section class="panel arena"><div class="arena-heading"><div><p class="eyebrow" id="day-label"><?= e($day) ?> / DAILY CHALLENGE</p><h2 id="game-name"><?= e($names[$game]) ?></h2></div><div class="timer" id="timer" aria-label="Resterende tijd">02:00</div></div>
<p id="instructions"></p><div class="game-screen" id="game-screen"><div class="standby"><span class="pixel-star">✦</span><h3>Jouw moment om te winnen.</h3><p>Dezelfde uitdaging. Drie kansen. Eén dagwinnaar.</p></div></div>
<div class="game-actions"><button id="start-game">Start poging →</button><button id="finish-game" class="secondary" hidden>Stop & bewaar</button><button id="retry-save" class="secondary" hidden>Opnieuw opslaan</button><span id="live-score">0 punten</span></div>
<p class="fine">Maximaal 2 minuten. Je beste poging telt; bij gelijke punten wint de kortste rondetijd. Een gestart spel telt als poging. Een ander tabblad openen stopt en bewaart de ronde.</p></section>
<aside><section class="panel"><p class="eyebrow">HEAD TO HEAD</p><h2>Vandaag</h2><div id="leaderboard"></div><button class="text-button" id="refresh-board">Scores vernieuwen ↻</button></section><section class="panel"><p class="eyebrow">LAATSTE 30 AFGELOPEN DAGEN</p><h2>Dagzeges</h2><div id="wins"></div><p class="fine">Vandaag telt pas mee na middernacht. Bij exact gelijke score én tijd is het gelijkspel.</p><div id="history"></div></section></aside></div>
<footer class="rotation"><span>01 / GALGJE</span><span>02 / SNAKE</span><span>03 / TETRIS</span><span>04 / WOORDZOEKER</span><p>Elke dag een volgend spel en een nieuwe uitdaging · Europe/Brussels</p></footer>
<?php endif; ?>
<p id="message" class="message" role="status" aria-live="polite"></p></main></body></html>
