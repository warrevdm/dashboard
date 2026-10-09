# Retour per fiets

In een dossier met status Afgehaald staat bij elke nog uitgeleende fiets een bevestigingsvakje en **Retour registreren**. Medewerkers en beheerders kunnen dit gebruiken; boekhouding ziet alleen de gegevens.

- De fiets krijgt een eigen retourtijd en medewerkerregistratie en blijft in het dossier staan.
- Vanaf de retourtijd blokkeert deze fiets geen nieuwe verhuur meer. Onderhoud, inactieve status en andere reservaties blijven de beschikbaarheid bepalen.
- Het dossier blijft Afgehaald totdat de laatste fiets is teruggenomen; dan wordt het automatisch Teruggebracht, met afsluitstempel.
- De afgesproken prijs, betalingen en contracthistoriek blijven behouden.
- Dagmail, werkplaatsscherm, focusplanning en te-laatmeldingen tonen alleen nog uitgeleende fietsen.
- Historische planning gebruikt de werkelijke retourtijd per fiets.
- Geregistreerde retours kunnen niet via dossierbewerking worden verwijderd of opnieuw uitgegeven. Maak voor een nieuwe uitgifte een nieuwe reservatie.

Bij de eerste databaseverbinding voegt de applicatie automatisch nullable `returned_at` en `returned_by` toe aan `reservation_bikes`. Bestaande gegevens worden behouden. Upload alle gewijzigde applicatiebestanden samen; vervang geen databasebestand. Een databaseback-up vóór deployment is aanbevolen.

Test: `node --experimental-wasm-jspi huur-module/tests/partial-returns.cjs`
