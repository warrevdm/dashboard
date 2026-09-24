# Verhuurmodule: regressietests

De tests laden de echte PHP-functies en pagina's in een geïsoleerde PHP 8.3/Wasm-omgeving. De reservatietests gebruiken tijdelijke, synthetische SQLite-databases; de hostingdiagnostiektests werken zonder database. Ze lezen geen privéconfiguratie of productiegegevens en versturen geen e-mail.

Voer vanuit de hoofdmap van de repository uit (Node.js met `--experimental-wasm-jspi`):

```sh
npm --prefix lease/tests ci --ignore-scripts --no-audit --no-fund
node --experimental-wasm-jspi huur-module/tests/reservation-edit.cjs
node --experimental-wasm-jspi huur-module/tests/reservation-create.cjs
node --experimental-wasm-jspi huur-module/tests/diagnostics.cjs
```

De controles omvatten dossierwijzigingen, beschikbaarheid van meerdere fietsen, datums en zomertijd, behoud van betalingen en ondertekende contracten, intrekken van conceptcontracten, gelijktijdige wijzigingen, autorisatie, CSRF, foutmeldingen en rollback bij een auditfout. Oude SQLite-schema's worden getest op behoud van rijen, relaties, extra kolommen, indexen, triggers, views en de ID-teller.

De aanmaaktests voeren echte GET- en POST-verzoeken uit voor **Nieuwe verhuur** en **Snelle vervangfiets**. Ze controleren de keuzelijst Huur/Test/Vervang en standaardwaarden, het opgeslagen type en auditlog, handmatige en automatische prijsberekening, aanvangsbetalingen, toegangsrechten, CSRF, ongeldige typen en beschikbaarheidsconflicten. Oudere open formulieren zonder het nieuwe veld behouden hun oorspronkelijke standaardtype.

De hostingdiagnostiektests gebruiken synthetische sessies zonder database. Ze controleren dat alleen beheerders metingen krijgen, alle antwoorden `no-store` gebruiken en alleen GET/HEAD zijn toegestaan. Ontbrekende of geblokkeerde OPcache-statistieken blijven onbekend; configuratie en runtime-status zijn afzonderlijke waarden. Het rapport laat scriptpaden, wachtwoorden en sessiegegevens weg. De tests controleren ook correcte sessieafsluiting, ruwe hostbelasting en de beperkingen van de meting.

Deze map hoeft niet naar de hosting. `.htaccess` blokkeert HTTP-toegang op Apache.
