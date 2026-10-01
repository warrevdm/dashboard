<?php

declare(strict_types=1);

/** Detect changes made since the employee opened the dossier, including its contract. */
function reservation_edit_version(array $reservation, ?array $contract): string
{
    return hash('sha256', serialize([$reservation, $contract]));
}

function update_reservation_details(int $id, array $input): void
{
    if (!current_user() || is_finance()) {
        throw new DomainException('Je hebt geen rechten om reservaties te wijzigen.');
    }

    $name = trim((string) ($input['customer_name'] ?? ''));
    $email = trim((string) ($input['customer_email'] ?? ''));
    $phone = trim((string) ($input['customer_phone'] ?? ''));
    $address = trim((string) ($input['customer_address'] ?? ''));
    $kind = (string) ($input['rental_kind'] ?? '');
    $status = (string) ($input['status'] ?? '');
    $notes = trim((string) ($input['notes'] ?? ''));
    $startValue = trim((string) ($input['start_date'] ?? '')) . ' ' . trim((string) ($input['start_time'] ?? ''));
    $endValue = trim((string) ($input['end_date'] ?? '')) . ' ' . trim((string) ($input['end_time'] ?? ''));
    $start = parse_datetime((string) ($input['start_date'] ?? ''), (string) ($input['start_time'] ?? ''));
    $end = parse_datetime((string) ($input['end_date'] ?? ''), (string) ($input['end_time'] ?? ''));

    if ($name === '') {
        throw new DomainException('Vul een klantnaam in.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new DomainException('Vul een geldig e-mailadres in of laat het veld leeg.');
    }
    if (!$start || !$end || $start->format('Y-m-d H:i') !== $startValue || $end->format('Y-m-d H:i') !== $endValue || $end <= $start) {
        throw new DomainException('Vul geldige datums en uren in. Het einde moet na de start liggen.');
    }
    if (!in_array($kind, ['rental', 'test', 'replacement'], true)) {
        throw new DomainException('Kies Huur, Test of Vervang.');
    }
    if (!in_array($status, ['reserved', 'confirmed', 'picked_up', 'returned'], true)) {
        throw new DomainException('Kies een geldige status.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Read and validate inside the same transaction as the update. SQLite
        // rejects an upgrade to a writer if another writer changed this snapshot.
        $reservation = find_reservation($id);
        $contract = find_contract_by_reservation($id);
        if (!$reservation || $reservation['status'] === 'cancelled') {
            throw new DomainException('Een ontbrekend of geannuleerd dossier kan niet worden aangepast.');
        }
        if (!hash_equals(reservation_edit_version($reservation, $contract), (string) ($input['version'] ?? ''))) {
            throw new DomainException('Dit dossier is intussen gewijzigd. Herlaad de pagina en controleer de nieuwste gegevens voordat je opnieuw opslaat.');
        }

        $currentBikeIds = array_map(static fn (array $bike): int => (int) $bike['id'], $reservation['bikes']);
        $bikes = $reservation['bikes'];
        $bikeChanged = false;
        if (isset($input['bike_id']) && (int) $input['bike_id'] !== (int) $reservation['bike_id']) {
            if ($reservation['rental_kind'] !== 'replacement' || count($bikes) !== 1) {
                throw new DomainException('Een fietswissel is hier alleen mogelijk bij een vervangdossier met één fiets.');
            }
            $bike = find_bike((int) $input['bike_id']);
            if (!$bike || $bike['status'] !== 'active') {
                throw new DomainException('Kies een bestaande, actieve fiets.');
            }
            $bikes = [$bike];
            $bikeChanged = true;
        }
        if (!$bikes) {
            throw new DomainException('Er is geen fiets aan dit dossier gekoppeld.');
        }
        foreach ($bikes as $bike) {
            // Returned dossiers no longer occupy a bike. Their original planned
            // period may overlap a later booking after an early return.
            if ($status !== 'returned' && reservation_conflicts((int) $bike['id'], $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $id)) {
                throw new DomainException('De periode overlapt met een andere reservatie voor ' . $bike['code'] . ' — ' . $bike['name'] . '. Kies een andere periode.');
            }
        }

        $customerChanges = [];
        foreach (['name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address] as $field => $value) {
            if ((string) ($reservation['customer_' . $field] ?? '') !== $value) {
                $customerChanges[] = $field;
            }
        }
        if ($customerChanges) {
            $shared = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE customer_id = :customer AND id != :id');
            $shared->execute([':customer' => $reservation['customer_id'], ':id' => $id]);
            if ((int) $shared->fetchColumn() > 0) {
                throw new DomainException('Deze klant is aan meerdere dossiers gekoppeld. Klantgegevens kunnen hier niet gewijzigd worden; periode, type en notities wel.');
            }
            $stmt = $pdo->prepare('UPDATE customers SET name = :name, email = :email, phone = :phone, address = :address WHERE id = :id');
            $stmt->execute([':name' => $name, ':email' => $email ?: null, ':phone' => $phone ?: null, ':address' => $address ?: null, ':id' => $reservation['customer_id']]);
        }

        $identityChanged = in_array('name', $customerChanges, true);
        $stmt = $pdo->prepare(
            'UPDATE reservations SET start_at = :start_at, end_at = :end_at, rental_kind = :kind,
                status = :status, notes = :notes, bike_id = :bike_id,
                closed_at = :closed_at, closed_by = :closed_by,
                eid_physical_checked = :physical, eid_photo_match = :photo,
                eid_checked_at = :checked_at, eid_checked_by = :checked_by,
                updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute([
            ':start_at' => $start->format('Y-m-d H:i:s'), ':end_at' => $end->format('Y-m-d H:i:s'),
            ':kind' => $kind, ':status' => $status, ':notes' => $notes !== '' ? $notes : null,
            ':bike_id' => $bikeChanged ? (int) $bikes[0]['id'] : (int) $reservation['bike_id'],
            ':closed_at' => $status === 'returned' ? ($reservation['closed_at'] ?: gmdate('Y-m-d H:i:s')) : null,
            ':closed_by' => $status === 'returned' ? ($reservation['closed_by'] ?: (int) current_user()['id']) : null,
            ':physical' => $identityChanged ? 0 : (int) $reservation['eid_physical_checked'],
            ':photo' => $identityChanged ? 0 : (int) $reservation['eid_photo_match'],
            ':checked_at' => $identityChanged ? null : $reservation['eid_checked_at'],
            ':checked_by' => $identityChanged ? null : $reservation['eid_checked_by'],
            ':id' => $id,
        ]);
        if ($bikeChanged) {
            $stmt = $pdo->prepare('DELETE FROM reservation_bikes WHERE reservation_id = :id');
            $stmt->execute([':id' => $id]);
            $stmt = $pdo->prepare('INSERT INTO reservation_bikes (reservation_id, bike_id, daily_rate) VALUES (:id, :bike, :rate)');
            $stmt->execute([':id' => $id, ':bike' => $bikes[0]['id'], ':rate' => $reservation['bikes'][0]['reserved_daily_rate'] ?? 0]);
        }

        $contractChanged = $customerChanges || $bikeChanged || $kind !== $reservation['rental_kind']
            || $start->format('Y-m-d H:i:s') !== $reservation['start_at'] || $end->format('Y-m-d H:i:s') !== $reservation['end_at'];
        $resetContract = $contract && empty($contract['signed_at']) && $contractChanged;
        if ($resetContract) {
            $stmt = $pdo->prepare('DELETE FROM rental_contracts WHERE id = :id AND signed_at IS NULL');
            $stmt->execute([':id' => $contract['id']]);
        }

        audit('update_reservation_details', 'reservation', $id, [
            'old_start_at' => $reservation['start_at'], 'new_start_at' => $start->format('Y-m-d H:i:s'),
            'old_end_at' => $reservation['end_at'], 'new_end_at' => $end->format('Y-m-d H:i:s'),
            'old_rental_kind' => $reservation['rental_kind'], 'new_rental_kind' => $kind,
            'old_status' => $reservation['status'], 'new_status' => $status,
            'old_bike_ids' => $currentBikeIds, 'new_bike_ids' => array_column($bikes, 'id'),
            'customer_fields_changed' => $customerChanges, 'notes_changed' => (string) $reservation['notes'] !== $notes,
            'identity_check_reset' => $identityChanged, 'unsigned_contract_reset' => (bool) $resetContract,
            'signed_contract_preserved' => !empty($contract['signed_at']),
        ]);
        $pdo->commit();
        if ($resetContract) {
            unset($_SESSION['contract_tokens'][(int) $contract['id']]);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
