<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/tablet_contracts.php';
require_tablet_contract_access();
header('Cache-Control: no-store, private, max-age=0');
$status = ($_GET['status'] ?? '') === 'signed' ? 'signed' : 'pending';
$q = is_string($_GET['q'] ?? null) ? substr(trim($_GET['q']), 0, 100) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$base = " FROM rental_contracts c JOIN reservations r ON r.id=c.reservation_id JOIN customers u ON u.id=r.customer_id ";
$filter = $status === 'signed' ? 'c.signed_at IS NOT NULL' : "c.signed_at IS NULL AND r.status <> 'cancelled'";
$params = [];
if ($q !== '') {
    $filter .= " AND (u.name LIKE :q OR c.contract_number LIKE :q OR EXISTS (SELECT 1 FROM reservation_bikes rb JOIN bikes b ON b.id=rb.bike_id WHERE rb.reservation_id=r.id AND (b.name LIKE :q OR b.code LIKE :q)))";
    $params[':q'] = '%' . $q . '%';
}
$count = db()->prepare('SELECT COUNT(*)' . $base . ' WHERE ' . $filter);
$count->execute($params);
$total = (int) $count->fetchColumn();
$pages = max(1, (int) ceil($total / 30));
$page = min($page, $pages);
$sql = 'SELECT c.id,c.contract_number,c.signed_at,r.id AS reservation_id,r.start_at,r.end_at,u.name AS customer_name,
 (SELECT GROUP_CONCAT(b.code || \' · \' || b.name, \', \') FROM reservation_bikes rb JOIN bikes b ON b.id=rb.bike_id WHERE rb.reservation_id=r.id) AS bikes'
 . $base . ' WHERE ' . $filter . ($status === 'signed' ? ' ORDER BY c.signed_at DESC,c.id DESC' : ' ORDER BY r.start_at ASC,c.id ASC') . ' LIMIT 30 OFFSET ' . (($page - 1) * 30);
$stmt = db()->prepare($sql);
$stmt->execute($params);
$contracts = $stmt->fetchAll();
render_header('Ondertekenen', true, 'planning');
?>
<section class="tablet-contracts">
    <p class="muted">Kies het contract en geef de tablet aan de klant. Na ondertekening staat het contract bij Ondertekend.</p>
    <nav class="tablet-tabs" aria-label="Contractstatus">
        <a class="button <?= $status === 'pending' ? '' : 'button-secondary' ?>" href="contracts.php?status=pending" <?= $status === 'pending' ? 'aria-current="page"' : '' ?>>Nog te ondertekenen</a>
        <a class="button <?= $status === 'signed' ? '' : 'button-secondary' ?>" href="contracts.php?status=signed" <?= $status === 'signed' ? 'aria-current="page"' : '' ?>>Ondertekend</a>
    </nav>
    <form method="get" class="tablet-search">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <label for="contract-search">Zoek klant, fiets of contractnummer</label>
        <div class="actions"><input id="contract-search" type="search" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="Bijvoorbeeld klantnaam of E01"><button class="button" type="submit">Zoeken</button><a href="contracts.php?status=<?= e($status) ?>">Wissen</a></div>
    </form>
    <p><?= $total ?> contract(en) · pagina <?= $page ?> van <?= $pages ?></p>
    <div class="tablet-contract-list">
    <?php foreach ($contracts as $item): ?>
        <article class="card tablet-contract-card">
            <div><span class="badge <?= $status === 'signed' ? 'status-confirmed' : 'status-reserved' ?>"><?= $status === 'signed' ? 'Ondertekend' : 'Nog te ondertekenen' ?></span>
                <h2><?= e($item['customer_name']) ?></h2>
                <p><?= e($item['bikes'] ?: 'Bekijk fietsen in het dossier') ?></p>
                <p class="muted"><?= e(date('d/m/Y H:i', strtotime($item['start_at']))) ?> tot <?= e(date('d/m/Y H:i', strtotime($item['end_at']))) ?><br><?= e($item['contract_number']) ?><?= $item['signed_at'] ? ' · Getekend op ' . e(date('d/m/Y H:i', strtotime($item['signed_at']))) : '' ?></p>
            </div>
            <a class="button button-large" href="sign.php?contract_id=<?= (int) $item['id'] ?>"><?= $status === 'signed' ? 'Contract bekijken' : 'Laat klant ondertekenen' ?></a>
        </article>
    <?php endforeach; ?>
    </div>
    <?php if (!$contracts): ?><div class="card"><h2>Geen contracten gevonden</h2><p>Pas je zoekopdracht aan of maak de huurovereenkomst eerst op vanuit het verhuurdossier.</p></div><?php endif; ?>
    <div class="actions tablet-pages">
    <?php if ($page > 1): ?><a class="button button-secondary" href="?<?= e(http_build_query(['status'=>$status,'q'=>$q,'page'=>$page-1])) ?>">Vorige</a><?php endif; ?>
    <?php if ($page < $pages): ?><a class="button" href="?<?= e(http_build_query(['status'=>$status,'q'=>$q,'page'=>$page+1])) ?>">Volgende</a><?php endif; ?>
    </div>
</section>
<?php render_footer('planning'); ?>
