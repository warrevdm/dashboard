<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_auth();
require_once __DIR__ . '/../app/reservation_edit.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$reservation = find_reservation($id);
if (!$reservation) {
    http_response_code(404);
    exit('Verhuur niet gevonden.');
}

$contract = find_contract_by_reservation($id);
$isFinanceView = is_finance();
$isReplacementReservation = (string) ($reservation['rental_kind'] ?? 'rental') === 'replacement';
$editError = null;
$editInput = null;

if ((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($isFinanceView) {
        http_response_code(403);
        exit('Boekhouding heeft alleen leesrechten op reservaties.');
    }

    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update-details') {
        $editInput = $_POST;
        try {
            update_reservation_details($id, $_POST);
            flash('success', 'Reservatie en dossier bijgewerkt. De prijs en betalingen zijn behouden.');
            redirect('reservation.php?id=' . $id . '#dossier-bewerken');
        } catch (DomainException $e) {
            $editError = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Reservatie bewerken: ' . $e->getMessage());
            $editError = 'De wijzigingen konden niet worden opgeslagen. Probeer opnieuw.';
        }
    }

    if ($action === 'update-replacement-cost') {
        if (!$isReplacementReservation) {
            flash('error', 'Deze actie is alleen beschikbaar voor vervangfietsen.');
            redirect('reservation.php?id=' . $id);
        }
        if ((string) $reservation['status'] === 'cancelled') {
            flash('error', 'Een geannuleerd vervangdossier kan niet meer financieel worden aangepast.');
            redirect('reservation.php?id=' . $id . '#vervangkost');
        }

        $amount = max(0, round((float) ($_POST['replacement_cost'] ?? 0), 2));
        $costNote = trim((string) ($_POST['replacement_cost_note'] ?? '')) ?: null;
        $summary = reservation_payment_summary($id, (float) $reservation['total_price']);

        if ($amount + 0.009 < (float) $summary['paid']) {
            flash('error', 'De vervangkost kan niet lager zijn dan het reeds betaalde bedrag.');
            redirect('reservation.php?id=' . $id . '#vervangkost');
        }

        $stmt = db()->prepare(
            'UPDATE reservations
             SET total_price = :amount,
                 replacement_cost_note = :cost_note,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            ':amount' => $amount,
            ':cost_note' => $costNote,
            ':id' => $id,
        ]);

        audit('update_replacement_cost', 'reservation', $id, [
            'old_amount' => (float) $reservation['total_price'],
            'new_amount' => $amount,
            'cost_note' => $costNote,
        ]);

        flash('success', $amount > 0 ? 'Vervangkost opgeslagen.' : 'Vervangkost verwijderd.');
        redirect('reservation.php?id=' . $id . '#vervangkost');
    }

    if ($action === 'cancel-replacement') {
        if (!$isReplacementReservation || in_array((string) $reservation['status'], ['returned', 'cancelled'], true)) {
            flash('error', 'Deze vervangfietsplanning kan niet meer uit de planning worden verwijderd.');
            redirect('reservation.php?id=' . $id);
        }

        $reason = trim((string) ($_POST['cancel_reason'] ?? ''));
        if ($reason === '') {
            flash('error', 'Vul een reden in voor het verwijderen uit de planning.');
            redirect('reservation.php?id=' . $id . '#vervangfiets-verwijderen');
        }

        $cancelledAt = (new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels')))->format('Y-m-d H:i:s');
        $stmt = db()->prepare(
            "UPDATE reservations
             SET status = 'cancelled',
                 cancelled_reason = :reason,
                 cancelled_by = :cancelled_by,
                 cancelled_at = :cancelled_at,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $stmt->execute([
            ':reason' => $reason,
            ':cancelled_by' => (int) current_user()['id'],
            ':cancelled_at' => $cancelledAt,
            ':id' => $id,
        ]);

        audit('cancel_replacement_reservation', 'reservation', $id, [
            'reason' => $reason,
            'previous_status' => (string) $reservation['status'],
            'cancelled_at' => $cancelledAt,
        ]);

        flash('success', 'De vervangfiets is uit de planning verwijderd. Het dossier blijft bewaard in de historiek.');
        redirect('planning.php');
    }

    if ($action === 'confirm-eid-check') {
        if (!empty($reservation['eid_checked_at'])) {
            flash('error', 'De eID-identiteitscontrole is voor dit dossier al bevestigd.');
            redirect('reservation.php?id=' . $id . '#identiteitscontrole');
        }

        $physicalChecked = isset($_POST['eid_physical_checked']);
        $photoMatch = isset($_POST['eid_photo_match']);

        if (!$physicalChecked || !$photoMatch) {
            flash('error', 'Bevestig zowel de fysieke eID-controle als de visuele fotovergelijking.');
            redirect('reservation.php?id=' . $id . '#identiteitscontrole');
        }

        $checkedAt = (new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels')))->format('Y-m-d H:i:s');
        $checkedBy = (int) current_user()['id'];

        $stmt = db()->prepare(
            'UPDATE reservations
             SET eid_physical_checked = 1,
                 eid_photo_match = 1,
                 eid_checked_by = :checked_by,
                 eid_checked_at = :checked_at,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND eid_checked_at IS NULL'
        );
        $stmt->execute([
            ':checked_by' => $checkedBy,
            ':checked_at' => $checkedAt,
            ':id' => $id,
        ]);

        if ($stmt->rowCount() !== 1) {
            flash('error', 'De identiteitscontrole kon niet worden vastgelegd of was al geregistreerd.');
            redirect('reservation.php?id=' . $id . '#identiteitscontrole');
        }

        audit('confirm_eid_identity_check', 'reservation', $id, [
            'eid_physical_checked' => true,
            'eid_photo_match' => true,
            'eid_checked_by' => $checkedBy,
            'eid_checked_at' => $checkedAt,
        ]);

        flash('success', 'eID-identiteitscontrole geregistreerd.');
        redirect('reservation.php?id=' . $id . '#identiteitscontrole');
    }

    if ($action === 'update-total-price') {
        if ((string) ($reservation['rental_kind'] ?? 'rental') === 'replacement') {
            flash('error', 'Een vervangfiets heeft geen huurbetaling of huurprijs.');
            redirect('reservation.php?id=' . $id);
        }

        $newTotalPrice = round((float) ($_POST['total_price'] ?? 0), 2);
        $summary = reservation_payment_summary($id, (float) $reservation['total_price']);

        if ($newTotalPrice < 0) {
            flash('error', 'De totaalprijs kan niet negatief zijn.');
            redirect('reservation.php?id=' . $id . '#betalingen');
        }
        if (!empty($contract['signed_at'])) {
            flash('error', 'De totaalprijs kan niet meer worden gewijzigd nadat het contract ondertekend is.');
            redirect('reservation.php?id=' . $id . '#betalingen');
        }
        if ($newTotalPrice + 0.009 < (float) $summary['paid']) {
            flash('error', 'De totaalprijs kan niet lager zijn dan het reeds betaalde bedrag.');
            redirect('reservation.php?id=' . $id . '#betalingen');
        }

        db()->beginTransaction();
        try {
            $stmt = db()->prepare('UPDATE reservations SET total_price = :total_price, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([
                ':total_price' => $newTotalPrice,
                ':id' => $id,
            ]);

            if ($contract && empty($contract['signed_at'])) {
                $stmt = db()->prepare('DELETE FROM rental_contracts WHERE id = :id');
                $stmt->execute([':id' => (int) $contract['id']]);
                unset($_SESSION['contract_tokens'][(int) $contract['id']]);
            }

            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', 'De totaalprijs kon niet worden opgeslagen.');
            redirect('reservation.php?id=' . $id . '#betalingen');
        }

        audit('update_total_price', 'reservation', $id, [
            'old_total_price' => (float) $reservation['total_price'],
            'new_total_price' => $newTotalPrice,
            'unsigned_contract_reset' => $contract && empty($contract['signed_at']),
        ]);
        flash('success', 'Totaalprijs bijgewerkt. Een niet-ondertekend contract wordt opnieuw opgebouwd met de nieuwe prijs.');
        redirect('reservation.php?id=' . $id . '#betalingen');
    }

    if ($action === 'add-payment') {
        $paymentAnchor = $isReplacementReservation ? '#vervangkost' : '#betalingen';
        if ($isReplacementReservation && (string) $reservation['status'] === 'cancelled') {
            flash('error', 'Op een geannuleerd vervangdossier kan geen nieuwe betaling worden geregistreerd.');
            redirect('reservation.php?id=' . $id . $paymentAnchor);
        }
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $method = (string) ($_POST['method'] ?? '');
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        $summary = reservation_payment_summary($id, (float) $reservation['total_price']);

        if ($amount <= 0 || !in_array($method, ['bancontact', 'cash'], true)) {
            flash('error', 'Vul een positief bedrag in en kies Bancontact of cash.');
            redirect('reservation.php?id=' . $id . $paymentAnchor);
        }
        if ((float) $reservation['total_price'] <= 0) {
            flash('error', $isReplacementReservation ? 'Stel eerst een vervangkost in voordat je een betaling registreert.' : 'Stel eerst de totaalprijs in voordat je een betaling registreert.');
            redirect('reservation.php?id=' . $id . $paymentAnchor);
        }
        if ($amount - (float) $summary['outstanding'] > 0.009) {
            flash('error', 'Het bedrag is hoger dan het openstaande saldo.');
            redirect('reservation.php?id=' . $id . $paymentAnchor);
        }

        $stmt = db()->prepare(
            'INSERT INTO payment_logs (reservation_id, amount, method, note, paid_at, recorded_by)
             VALUES (:reservation_id, :amount, :method, :note, CURRENT_TIMESTAMP, :recorded_by)'
        );
        $stmt->execute([
            ':reservation_id' => $id,
            ':amount' => $amount,
            ':method' => $method,
            ':note' => $note,
            ':recorded_by' => (int) current_user()['id'],
        ]);
        $paymentId = (int) db()->lastInsertId();
        audit('create', 'payment_log', $paymentId, [
            'reservation_id' => $id,
            'amount' => $amount,
            'method' => $method,
        ]);
        $paymentContext = $isReplacementReservation ? 'replacement_cost' : (string) $reservation['rental_kind'];
        audit('payment_context', 'payment_log', $paymentId, ['context' => $paymentContext]);
        flash('success', $isReplacementReservation ? 'Betaling op de vervangkost geregistreerd.' : 'Betaling geregistreerd in het betalingslog.');
        redirect('reservation.php?id=' . $id . ($isReplacementReservation ? '#vervangkost' : '#betalingen'));
    }
}

$reservation = find_reservation($id) ?? $reservation;
$contract = find_contract_by_reservation($id);
$payments = reservation_payments($id);
$paymentSummary = reservation_payment_summary($id, (float) $reservation['total_price']);
$rentalKind = (string) ($reservation['rental_kind'] ?? 'rental');
$isReplacement = $rentalKind === 'replacement';
$isTest = $rentalKind === 'test';
$canEdit = !$isFinanceView && (string) $reservation['status'] !== 'cancelled';
$replacementBikeOptions = $canEdit && $isReplacement && count($reservation['bikes']) === 1 ? all_bikes(true) : [];
$editValues = $editInput ?? [
    'customer_name' => (string) $reservation['customer_name'],
    'customer_phone' => (string) ($reservation['customer_phone'] ?? ''),
    'customer_email' => (string) ($reservation['customer_email'] ?? ''),
    'customer_address' => (string) ($reservation['customer_address'] ?? ''),
    'start_date' => (new DateTimeImmutable((string) $reservation['start_at']))->format('Y-m-d'),
    'start_time' => (new DateTimeImmutable((string) $reservation['start_at']))->format('H:i'),
    'end_date' => (new DateTimeImmutable((string) $reservation['end_at']))->format('Y-m-d'),
    'end_time' => (new DateTimeImmutable((string) $reservation['end_at']))->format('H:i'),
    'rental_kind' => $rentalKind, 'status' => (string) $reservation['status'],
    'notes' => (string) ($reservation['notes'] ?? ''), 'bike_id' => (string) $reservation['bike_id'],
    'version' => reservation_edit_version($reservation, $contract),
];

render_header(($isReplacement ? 'Vervangfiets #' : ($isTest ? 'Testreservatie #' : 'Verhuur #')) . $id);
?>
<?php if ($isFinanceView): ?>
    <div class="actions mb-18">
        <a class="button button-secondary" href="cashbook.php">← Terug naar kasboek</a>
        <span class="badge">Alleen-lezen voor Boekhouding</span>
    </div>
<?php endif; ?>
<?php if ($editError !== null): ?>
    <div class="alert alert-error" role="alert"><?= e($editError) ?> <a href="reservation.php?id=<?= $id ?>#dossier-bewerken">Dossier herladen</a></div>
<?php endif; ?>
<section class="grid" data-finance-readonly="<?= $isFinanceView ? '1' : '0' ?>">
    <div class="card col-8">
        <div class="actions actions-between">
            <div>
                <h2><?= e((string) $reservation['bike_summary']) ?></h2>
                <p class="muted"><?= count($reservation['bikes']) ?> fiets(en) in dit dossier</p>
            </div>
            <div class="actions">
                <?php if ($canEdit): ?><a class="button button-secondary" href="#dossier-bewerken" data-edit-reservation>Dossier aanpassen</a><?php endif; ?>
                <?php if ($isReplacement): ?>
                    <span class="booking-kind booking-kind-replacement">↺ Vervangfiets</span>
                    <?php if ((float) $reservation['total_price'] > 0): ?>
                        <span class="badge <?= $paymentSummary['is_paid'] ? 'booking-payment-paid' : ($paymentSummary['is_partial'] ? 'booking-payment-partial' : 'booking-payment-open') ?>">
                            € <?= number_format((float) $reservation['total_price'], 2, ',', '.') ?> vervangkost
                        </span>
                    <?php else: ?>
                        <span class="badge booking-payment-not-required">€0 · geen kost</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="booking-kind booking-kind-<?= $isTest ? 'test' : 'rental' ?>"><?= $isTest ? 'Testfiets' : '€ Huurfiets' ?></span>
                    <?php if (!$isFinanceView): ?>
                        <a class="button button-secondary" href="#betalingen">Betaling registreren</a>
                    <?php endif; ?>
                <?php endif; ?>
                <span class="badge status-<?= e((string) $reservation['status']) ?>"><?= e(status_label((string) $reservation['status'])) ?></span>
            </div>
        </div>

        <div class="reservation-bike-list">
            <?php foreach ($reservation['bikes'] as $bike): ?>
                <article class="reservation-bike-item">
                    <div class="reservation-bike-thumb">
                        <?php if (!empty($bike['photo_stored_name'])): ?>
                            <img src="bike-photo.php?id=<?= (int) $bike['id'] ?>&amp;v=<?= e((string) $bike['updated_at']) ?>" alt="<?= e((string) $bike['name']) ?>">
                        <?php else: ?><span>Geen foto</span><?php endif; ?>
                    </div>
                    <div>
                        <strong><?= e((string) $bike['code']) ?> — <?= e((string) $bike['name']) ?></strong>
                        <div class="muted"><?= e((string) $bike['category']) ?> · maat <?= e((string) ($bike['frame_size'] ?: '—')) ?></div>
                        <div class="muted">Framenummer: <?= e((string) ($bike['frame_number'] ?: '—')) ?></div>
                    </div>
                    <span class="badge badge-<?= e((string) $bike['status']) ?>"><?= e(bike_status_label((string) $bike['status'])) ?></span>
                </article>
            <?php endforeach; ?>
        </div>

        <dl class="summary-list mt-18">
            <dt>Periode</dt><dd><?= e((new DateTimeImmutable((string) $reservation['start_at']))->format('d/m/Y H:i')) ?> → <?= e((new DateTimeImmutable((string) $reservation['end_at']))->format('d/m/Y H:i')) ?></dd>
            <dt>Klant</dt><dd><?= e((string) $reservation['customer_name']) ?></dd>
            <dt>Telefoon</dt><dd><?= e((string) ($reservation['customer_phone'] ?: '—')) ?></dd>
            <dt>E-mail</dt><dd><?= e((string) ($reservation['customer_email'] ?: '—')) ?></dd>
            <dt>Adres</dt><dd><?= e((string) ($reservation['customer_address'] ?: '—')) ?></dd>
            <dt>Type</dt><dd><?= $isReplacement ? '↺ Vervangfiets' : ($isTest ? 'Testfiets' : '€ Huurfiets · betaling verwacht') ?></dd>
            <?php if ($isReplacement): ?>
                <dt>Vervangkost</dt><dd><?= (float) $reservation['total_price'] > 0 ? '€ ' . number_format((float) $reservation['total_price'], 2, ',', '.') : 'Geen kost gekoppeld' ?></dd>
                <dt>Kostomschrijving</dt><dd><?= e((string) (($reservation['replacement_cost_note'] ?? '') ?: '—')) ?></dd>
            <?php else: ?>
                <dt>Totaalprijs</dt><dd>€ <?= number_format((float) $reservation['total_price'], 2, ',', '.') ?></dd>
            <?php endif; ?>
            <dt>Notities</dt><dd><?= nl2br(e((string) ($reservation['notes'] ?: '—'))) ?></dd>
        </dl>
    </div>

    <aside class="card col-4">
        <?php if ($isReplacement): ?>
            <h2>Vervangdossier</h2>
            <p><span class="badge status-<?= e((string) $reservation['status']) ?>"><?= e(status_label((string) $reservation['status'])) ?></span></p>
            <p class="muted">Dit is een vervangdossier. Een eventuele kost beheer je hieronder bij Vervangkost / eigen bijdrage.</p>
            <?php if (!empty($reservation['cancelled_at'])): ?>
                <div class="alert alert-warning">
                    <strong>Uit planning verwijderd</strong><br>
                    <?= e((new DateTimeImmutable((string) $reservation['cancelled_at']))->format('d/m/Y H:i')) ?><br>
                    Door: <?= e((string) (($reservation['cancelled_by_name'] ?? '') ?: 'Onbekend')) ?><br>
                    Reden: <?= e((string) (($reservation['cancelled_reason'] ?? '') ?: 'Niet opgegeven')) ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (!$isReplacement || $contract): ?>
        <h2>Huurovereenkomst</h2>
        <?php if (!$contract): ?>
            <p class="muted">Nog geen contract opgemaakt.</p>
            <?php if (!$isFinanceView): ?><a class="button" href="contract.php?reservation_id=<?= $id ?>">Gezamenlijk contract opmaken</a><?php endif; ?>
        <?php elseif (!empty($contract['signed_at'])): ?>
            <p><span class="badge status-confirmed">Ondertekend</span></p>
            <p class="muted">Door <?= e((string) $contract['signer_name']) ?> op <?= e((new DateTimeImmutable((string) $contract['signed_at']))->format('d/m/Y H:i')) ?>.</p>
            <?php if (!$isFinanceView): ?><a class="button" href="contract.php?reservation_id=<?= $id ?>">Contract bekijken</a><?php endif; ?>
        <?php else: ?>
            <p><span class="badge status-reserved">Wacht op handtekening</span></p>
            <?php if (!$isFinanceView): ?><a class="button" href="contract.php?reservation_id=<?= $id ?>">Naar ondertekening</a><?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (!$isReplacement): ?>
        <hr><h2 id="identiteitscontrole">eID-identiteitscontrole</h2>
        <?php if (!empty($reservation['eid_checked_at'])): ?>
            <div class="alert alert-success">
                <strong>Identiteit gecontroleerd</strong><br>
                Fysieke eID gecontroleerd: âœ“<br>
                Foto visueel overeenkomstig: âœ“<br>
                Medewerker: <?= e((string) ($reservation['eid_checked_by_name'] ?: 'Onbekend')) ?><br>
                Tijdstip: <?= e((new DateTimeImmutable((string) $reservation['eid_checked_at']))->format('d/m/Y H:i')) ?>
            </div>
        <?php else: ?>
            <p class="muted">Nog niet bevestigd.<?= $isFinanceView ? '' : ' Voer deze controle uit wanneer de huurder fysiek aanwezig is.' ?></p>
            <?php if (!$isFinanceView): ?>
            <form method="post" class="stack">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="confirm-eid-check">

                <label class="checkbox-row">
                    <input type="checkbox" name="eid_physical_checked" value="1" required>
                    Fysieke Belgische eID gecontroleerd
                </label>

                <label class="checkbox-row">
                    <input type="checkbox" name="eid_photo_match" value="1" required>
                    Foto op de fysieke eID visueel overeenkomstig met de huurder
                </label>

                <span class="help">
                    Bij bevestiging worden medewerker en tijdstip automatisch geregistreerd.
                    Er wordt geen rijksregisternummer of eID-foto opgeslagen.
                </span>

                <button class="button button-secondary" type="submit">Identiteitscontrole bevestigen</button>
            </form>
            <?php endif; ?>
        <?php endif; ?>

        <hr><h2>Identiteitsdocument</h2>
        <?php if ($reservation['document_id'] && !$reservation['document_deleted_at']): ?>
            <p><strong><?= e((string) $reservation['document_name']) ?></strong><br><span class="muted"><?= e((string) $reservation['document_mime']) ?> · <?= number_format((int) $reservation['document_size'] / 1024, 0, ',', '.') ?> KB</span></p>
            <p class="muted">Bewaren tot <?= e($reservation['retention_until'] ? (new DateTimeImmutable((string) $reservation['retention_until']))->format('d/m/Y') : 'niet ingesteld') ?></p>
            <?php if (!$isFinanceView): ?><a class="button button-secondary" href="index.php?route=id-download&amp;id=<?= (int) $reservation['document_id'] ?>">Veilig openen</a><?php endif; ?>
        <?php else: ?>
            <p class="muted">Geen document gekoppeld.</p>
        <?php endif; ?>
        <?php endif; ?>
    </aside>

    <?php if ($canEdit): ?>
    <div class="card col-12" id="dossier-bewerken">
        <div class="actions actions-between">
            <div>
                <h2>Dossier aanpassen</h2>
                <p class="muted">Wijzig de periode, het type, de klantgegevens, status en interne notities. De beschikbaarheid van alle gekoppelde fietsen wordt bij opslaan gecontroleerd.</p>
            </div>
            <a class="button button-secondary" href="planning.php?start=<?= e((new DateTimeImmutable((string) $reservation['start_at']))->format('Y-m-d')) ?>">Toon in planning</a>
        </div>
        <?php if (!empty($contract['signed_at'])): ?>
            <p class="alert alert-warning">Het ondertekende contract blijft bewaard met de oorspronkelijke afspraken. Een dossierwijziging past dat document niet aan. Bij een andere klantnaam moet de identiteit opnieuw worden gecontroleerd.</p>
        <?php else: ?>
            <p class="help">Bij wijziging van klantgegevens, fiets, periode of type vervalt een bestaand conceptcontract met zijn ondertekenlink. Je kunt daarna een nieuw contract opmaken.</p>
        <?php endif; ?>
        <form method="post" action="reservation.php?id=<?= $id ?>#dossier-bewerken" class="stack mt-18">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="update-details">
            <input type="hidden" name="version" value="<?= e((string) ($editValues['version'] ?? '')) ?>">
            <div class="form-grid">
                <div class="field">
                    <label for="edit-kind">Type reservatie *</label>
                    <select id="edit-kind" name="rental_kind" required>
                        <?php foreach (['rental' => 'Huur', 'test' => 'Test', 'replacement' => 'Vervang'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($editValues['rental_kind'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="edit-status">Status *</label>
                    <select id="edit-status" name="status" required>
                        <?php foreach (['reserved', 'confirmed', 'picked_up', 'returned'] as $value): ?>
                            <option value="<?= e($value) ?>" <?= ($editValues['status'] ?? '') === $value ? 'selected' : '' ?>><?= e(status_label($value)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($replacementBikeOptions): ?>
                    <div class="field field-full">
                        <label for="edit-bike">Vervangfiets *</label>
                        <select id="edit-bike" name="bike_id" required>
                            <?php foreach ($replacementBikeOptions as $option):
                                $unavailable = (int) $option['id'] !== (int) $reservation['bike_id'] && $option['status'] !== 'active';
                            ?>
                                <option value="<?= (int) $option['id'] ?>" <?= (int) ($editValues['bike_id'] ?? 0) === (int) $option['id'] ? 'selected' : '' ?> <?= $unavailable ? 'disabled' : '' ?>><?= e($option['code'] . ' — ' . $option['name'] . ($unavailable ? ' · niet actief' : '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <?php foreach (['start_date' => ['Startdatum', 'date'], 'start_time' => ['Startuur', 'time'], 'end_date' => ['Einddatum', 'date'], 'end_time' => ['Einduur', 'time']] as $field => [$label, $type]): ?>
                    <div class="field">
                        <label for="edit-<?= e($field) ?>"><?= e($label) ?> *</label>
                        <input id="edit-<?= e($field) ?>" name="<?= e($field) ?>" type="<?= e($type) ?>" required value="<?= e((string) ($editValues[$field] ?? '')) ?>">
                    </div>
                <?php endforeach; ?>
                <?php foreach (['customer_name' => ['Klantnaam', 'text'], 'customer_phone' => ['Telefoon', 'tel'], 'customer_email' => ['E-mail', 'email'], 'customer_address' => ['Adres', 'text']] as $field => [$label, $type]): ?>
                    <div class="field">
                        <label for="edit-<?= e($field) ?>"><?= e($label) ?><?= $field === 'customer_name' ? ' *' : '' ?></label>
                        <input id="edit-<?= e($field) ?>" name="<?= e($field) ?>" type="<?= e($type) ?>" <?= $field === 'customer_name' ? 'required' : '' ?> value="<?= e((string) ($editValues[$field] ?? '')) ?>">
                    </div>
                <?php endforeach; ?>
                <div class="field field-full">
                    <label for="edit-notes">Interne notities</label>
                    <textarea id="edit-notes" name="notes"><?= e((string) ($editValues['notes'] ?? '')) ?></textarea>
                </div>
            </div>
            <p class="help">De afgesproken prijs en geregistreerde betalingen blijven behouden, ook bij een andere periode of een ander type. Pas een eventuele prijs apart aan bij de betalingen of vervangkost.</p>
            <div class="actions">
                <button class="button" type="submit">Wijzigingen opslaan</button>
                <a class="button button-secondary" href="reservation.php?id=<?= $id ?>">Wijzigingen annuleren</a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($isReplacement): ?>
        <div class="card col-12 payment-card" id="vervangkost">
            <div class="actions actions-between">
                <div>
                    <h2>Vervangkost / eigen bijdrage</h2>
                    <p class="muted">Standaard €0. Alleen gebruiken wanneer er voor dit specifieke vervangdossier effectief een kost wordt aangerekend.</p>
                </div>
                <?php if ((float) $reservation['total_price'] <= 0): ?>
                    <span class="payment-state payment-paid">Geen kost</span>
                <?php elseif ($paymentSummary['is_paid']): ?>
                    <span class="payment-state payment-paid">Vervangkost betaald</span>
                <?php elseif ($paymentSummary['is_partial']): ?>
                    <span class="payment-state payment-partial">Deels betaald</span>
                <?php else: ?>
                    <span class="payment-state payment-open">Vervangkost open</span>
                <?php endif; ?>
            </div>

            <div class="payment-summary-grid">
                <div><span>Vervangkost</span><strong>€ <?= number_format((float) $reservation['total_price'], 2, ',', '.') ?></strong></div>
                <div><span>Betaald</span><strong>€ <?= number_format((float) $paymentSummary['paid'], 2, ',', '.') ?></strong></div>
                <div><span>Openstaand</span><strong>€ <?= number_format((float) $paymentSummary['outstanding'], 2, ',', '.') ?></strong></div>
            </div>

            <?php if (!$isFinanceView && (string) $reservation['status'] !== 'cancelled'): ?>
                <form method="post" class="form-grid mt-18">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="update-replacement-cost">
                    <div class="field">
                        <label for="replacement-cost">Bedrag</label>
                        <input id="replacement-cost" name="replacement_cost" type="number" min="<?= e(number_format((float) $paymentSummary['paid'], 2, '.', '')) ?>" step="0.01" value="<?= e(number_format((float) $reservation['total_price'], 2, '.', '')) ?>" required>
                    </div>
                    <div class="field">
                        <label for="replacement-cost-note">Reden / omschrijving</label>
                        <input id="replacement-cost-note" name="replacement_cost_note" value="<?= e((string) ($reservation['replacement_cost_note'] ?? '')) ?>" placeholder="Bijv. eigen bijdrage schade">
                    </div>
                    <div class="field field-full">
                        <button class="button button-secondary" type="submit">Vervangkost opslaan</button>
                    </div>
                </form>

                <?php if (!$paymentSummary['is_paid'] && (float) $reservation['total_price'] > 0): ?>
                    <form method="post" class="payment-entry-form mt-18">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="action" value="add-payment">
                        <div class="field">
                            <label>Bedrag</label>
                            <input name="amount" type="number" min="0.01" max="<?= e(number_format((float) $paymentSummary['outstanding'], 2, '.', '')) ?>" step="0.01" value="<?= e(number_format((float) $paymentSummary['outstanding'], 2, '.', '')) ?>" required>
                        </div>
                        <div class="field">
                            <label>Betaalwijze</label>
                            <select name="method" required><option value="bancontact">Bancontact</option><option value="cash">Cash</option></select>
                        </div>
                        <div class="field">
                            <label>Notitie</label>
                            <input name="note" placeholder="Bijv. vervangkost betaald aan balie">
                        </div>
                        <button class="button" type="submit">Betaling registreren</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <h3 class="mt-18">Betalingshistoriek vervangkost</h3>
            <?php if ($payments): ?>
                <div class="table-wrap"><table>
                    <thead><tr><th>Datum</th><th>Bedrag</th><th>Betaalwijze</th><th>Geregistreerd door</th><th>Notitie</th></tr></thead>
                    <tbody><?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><?= e((new DateTimeImmutable((string) $payment['paid_at']))->format('d/m/Y H:i')) ?></td>
                            <td><strong>€ <?= number_format((float) $payment['amount'], 2, ',', '.') ?></strong></td>
                            <td><?= e(payment_method_label((string) $payment['method'])) ?></td>
                            <td><?= e((string) ($payment['recorded_by_name'] ?: 'Onbekend')) ?></td>
                            <td><?= e((string) ($payment['note'] ?: '—')) ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php else: ?>
                <p class="muted">Nog geen betaling gekoppeld aan dit vervangdossier.</p>
            <?php endif; ?>
        </div>

        <?php if (!$isFinanceView && !in_array((string) $reservation['status'], ['returned', 'cancelled'], true)): ?>
        <div class="card col-12" id="vervangfiets-verwijderen">
            <h2>Uit planning verwijderen</h2>
            <p class="muted">De reservatie verdwijnt uit de planning, maar blijft bewaard als geannuleerd dossier voor historiek en audit.</p>
            <form method="post" class="actions">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="cancel-replacement">
                <div class="field" style="min-width: min(100%, 420px);">
                    <label for="replacement-cancel-reason">Reden *</label>
                    <input id="replacement-cancel-reason" name="cancel_reason" required placeholder="Bijv. klant heeft geen vervangfiets meer nodig">
                </div>
                <button class="button button-danger" type="submit">Uit planning verwijderen</button>
            </form>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$isReplacement): ?>
    <div class="card col-12 payment-card" id="betalingen">
        <div class="actions actions-between">
            <div>
                <h2><?= $isFinanceView ? 'Betalingen' : 'Betalingen registreren' ?></h2>
                <p class="muted"><?= $isFinanceView ? 'Financieel overzicht van deze reservatie.' : 'Log iedere betaling rechtstreeks op deze reservatie, met betaalwijze, medewerker, datum en uur.' ?></p>
            </div>
            <?php if ($isTest && (float) $reservation['total_price'] <= 0): ?>
                <span class="payment-state payment-paid">Geen kost</span>
            <?php elseif ($paymentSummary['is_paid']): ?>
                <span class="payment-state payment-paid">Volledig afgerekend</span>
            <?php elseif ($paymentSummary['is_partial']): ?>
                <span class="payment-state payment-partial">Deels betaald</span>
            <?php else: ?>
                <span class="payment-state payment-open">Nog niet betaald</span>
            <?php endif; ?>
        </div>

        <div class="payment-summary-grid">
            <div><span>Totaal</span><strong>€ <?= number_format((float) $reservation['total_price'], 2, ',', '.') ?></strong></div>
            <div><span>Betaald</span><strong>€ <?= number_format((float) $paymentSummary['paid'], 2, ',', '.') ?></strong></div>
            <div><span>Openstaand</span><strong>€ <?= number_format((float) $paymentSummary['outstanding'], 2, ',', '.') ?></strong></div>
        </div>

        <?php if (!$isFinanceView && empty($contract['signed_at'])): ?>
            <form method="post" class="payment-price-form mt-18">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="update-total-price">
                <div class="field">
                    <label>Totaalprijs reservatie</label>
                    <input name="total_price" type="number" min="<?= e(number_format((float) $paymentSummary['paid'], 2, '.', '')) ?>" step="0.01" value="<?= e(number_format((float) $reservation['total_price'], 2, '.', '')) ?>" required>
                </div>
                <button class="button button-secondary" type="submit">Totaalprijs opslaan</button>
                <span class="help">Een bestaand maar nog niet ondertekend contract wordt opnieuw opgebouwd met de nieuwe prijs.</span>
            </form>
        <?php endif; ?>

        <?php if (!$isFinanceView && !$paymentSummary['is_paid'] && (float) $reservation['total_price'] > 0): ?>
            <form method="post" class="payment-entry-form mt-18">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="add-payment">
                <div class="field"><label>Te registreren bedrag</label><input name="amount" type="number" min="0.01" max="<?= e(number_format((float) $paymentSummary['outstanding'], 2, '.', '')) ?>" step="0.01" value="<?= e(number_format((float) $paymentSummary['outstanding'], 2, '.', '')) ?>" required></div>
                <div class="field"><label>Betaalwijze</label><select name="method" required><option value="bancontact">Bancontact</option><option value="cash">Cash</option></select></div>
                <div class="field"><label>Notitie</label><input name="note" placeholder="Bijvoorbeeld voorschot of restbetaling"></div>
                <button class="button" type="submit">Betaling registreren</button>
            </form>
        <?php elseif (!$isFinanceView && $isTest && (float) $reservation['total_price'] <= 0): ?>
            <p class="help mt-18">Aan deze test is geen kost gekoppeld.</p>
        <?php elseif (!$isFinanceView && (float) $reservation['total_price'] <= 0): ?>
            <div class="alert alert-warning mt-18">Stel hierboven eerst de totaalprijs in om een betaling te kunnen registreren.</div>
        <?php elseif (!$isFinanceView): ?>
            <div class="alert alert-success mt-18">Deze reservatie is volledig afgerekend. Nieuwe betalingen zijn geblokkeerd om dubbel registreren te voorkomen.</div>
        <?php endif; ?>

        <h3 class="mt-18">Betalingshistoriek</h3>
        <?php if ($payments): ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Datum</th><th>Bedrag</th><th>Betaalwijze</th><th>Geregistreerd door</th><th>Notitie</th></tr></thead>
                <tbody><?php foreach ($payments as $payment): ?>
                    <tr>
                        <td><?= e((new DateTimeImmutable((string) $payment['paid_at']))->format('d/m/Y H:i')) ?></td>
                        <td><strong>€ <?= number_format((float) $payment['amount'], 2, ',', '.') ?></strong></td>
                        <td><?= e(payment_method_label((string) $payment['method'])) ?></td>
                        <td><?= e((string) ($payment['recorded_by_name'] ?: 'Onbekend')) ?></td>
                        <td><?= e((string) ($payment['note'] ?: '—')) ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php else: ?>
            <div class="alert alert-warning">Nog geen betaling geregistreerd.</div>
        <?php endif; ?>
    </div>

    <?php if (!$isFinanceView): ?>
    <?php require __DIR__ . '/reservation-stamp.php'; ?>

    <div class="card col-12">
        <h2>Status wijzigen</h2>
        <form method="post" action="index.php?route=reservation-status&amp;id=<?= $id ?>" class="actions">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <select class="select-narrow" name="status"><?php foreach (['reserved', 'confirmed', 'picked_up', 'returned', 'cancelled'] as $status): ?><option value="<?= e($status) ?>" <?= $reservation['status'] === $status ? 'selected' : '' ?>><?= e(status_label($status)) ?></option><?php endforeach; ?></select>
            <button class="button">Status opslaan</button>
            <a class="button button-secondary" href="planning.php?start=<?= e((new DateTimeImmutable((string) $reservation['start_at']))->format('Y-m-d')) ?>">Toon in planning</a>
        </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php render_footer();
