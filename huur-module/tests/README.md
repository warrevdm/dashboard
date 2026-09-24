# Reservaties bewerken: regressietests

De tests laden de echte PHP-functies en pagina's in een geïsoleerde PHP 8.3/Wasm-omgeving met tijdelijke, synthetische SQLite-databases. Ze lezen geen privéconfiguratie of productiegegevens en versturen geen e-mail.

Voer vanuit de hoofdmap van de repository uit (Node.js met `--experimental-wasm-jspi`):

```sh
npm --prefix lease/tests ci --ignore-scripts --no-audit --no-fund
node --experimental-wasm-jspi huur-module/tests/reservation-edit.cjs
```

De controles omvatten dossierwijzigingen, beschikbaarheid van meerdere fietsen, datums en zomertijd, behoud van betalingen en ondertekende contracten, intrekken van conceptcontracten, gelijktijdige wijzigingen, autorisatie, CSRF, foutmeldingen en rollback bij een auditfout. Oude SQLite-schema's worden getest op behoud van rijen, relaties, extra kolommen, indexen, triggers, views en de ID-teller.

Deze map hoeft niet naar de hosting. `.htaccess` blokkeert HTTP-toegang op Apache.
