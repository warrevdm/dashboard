# Niet opgedaagd (no-show)

Open vanuit de planning het dossier. Kies **Niet opgedaagd (no-show)** bij **Status wijzigen** of in **Dossier aanpassen → Status**. Je kunt ook **Klant niet komen opdagen?** openklappen. Vanaf het afgesproken startuur kan een medewerker of beheerder bevestigen dat de klant niet is opgedaagd. Een reden is optioneel, maximaal 1000 tekens.

Dit is mogelijk bij Huur en Test met status Gereserveerd of Bevestigd. Een afgehaalde, teruggebrachte of geannuleerde reservatie kan geen no-show worden. Er is geen automatische detectie: een medewerker controleert dit zelf.

- Het hele dossier wordt afgesloten met medewerker, tijdstip en eventuele reden.
- Alle gekoppelde fietsen blokkeren de planning en beschikbaarheidscontrole niet meer. Onderhoud, inactieve fietsen en andere boekingen blijven gelden.
- Het dossier blijft terug te vinden via **Niet opgedaagd** op de planning. Deze historiek volgt de startdatum, periode, categorie- en fietstypefilters en toont maximaal 100 dossiers.
- Werkplaatsscherm, dagmail, afhalingen, retours en te-laatmeldingen tonen geen no-shows.
- Prijs, betalingen en eerder ondertekende documenten blijven in de historiek staan. Er wordt geen no-showvergoeding berekend en geen betaling teruggedraaid. Het kasboek telt echte ontvangsten mee, maar telt deze afspraak niet als huurwaarde of vordering.
- Niet-ondertekende links worden ongeldig en ondertekenen is geblokkeerd zolang het dossier no-show is. Getekende contracten blijven leesbaar.
- Een no-show dossier is alleen-lezen. Met **Opnieuw bevestigen en beschikbaarheid controleren** kan het bewust heropend worden zolang de oorspronkelijke periode nog niet voorbij is en alle fietsen beschikbaar zijn. Bij een conflict of afgelopen periode maak je een nieuwe reservatie. De registratie en heropening blijven in de audit bewaard.

Na upload past de applicatie automatisch de statusbeperking in SQLite aan en voegt ze `no_show_reason` toe. Bestaande dossiers, betalingen, gekoppelde fietsen en contracten worden behouden. Upload alle gewijzigde bestanden samen. Vervang geen productie-databasebestand.

Tests: `node --experimental-wasm-jspi huur-module/tests/no-show.cjs`, `no-show-migration.cjs` en `no-show-contracts.cjs` vanuit de repositoryroot (met het volledige pad `huur-module/tests/` voor ieder bestand). Ze gebruiken synthetische gegevens.
