# Aerts Action Bike — interne verhuurmodule

[Algemene README](../README.md) · [Hosting en updates](../docs/deployment.md)

PHP 8.2-module voor interne fietsverhuur, planning, betalingen, contractopmaak en elektronische ondertekening.

## Functionaliteit

- Horizontale agenda met één rij per fiets en duidelijke kleuren per verhuurstatus.
- Planning toont actieve fietsen, fietsen in onderhoud en inactieve fietsen.
- Fietsen in onderhoud of met status inactief kunnen niet worden ingepland.
- Live beschikbaarheidscontrole bij het kiezen van de huurperiode.
- Eén verhuurdossier kan meerdere fietsen tegelijk bevatten.
- Type Huur/Test/Vervang kiezen bij zowel Nieuwe verhuur als Snelle vervangfiets.
- Bestaande dossiers bewerken: start- en einddatum met uren, type Huur/Test/Vervang, klantgegevens, status en notities.
- Eén gezamenlijk contract vermeldt alle fietsen, framenummers, maten en dagprijzen.
- Betalingslog met bedrag, Bancontact of cash, medewerker en tijdstip.
- Automatische status: nog niet betaald, deels betaald of volledig afgerekend.
- Server-side blokkering van overlappende reservaties voor elke geselecteerde fiets.
- Fietsbeheer met unieke interne code, uniek framenummer, framemaat, status en foto.
- Gebruikersprofielen met beheerder-, medewerker- en financiële rol.
- Naam-, datum- en tijdstempel bij aanmaak en afsluiting van een huur.
- Publieke ondertekenpagina met handtekeningvak en beveiligde toegangstoken.
- Contracthash, ondertekenmoment, IP-adres en user-agent als bewijsgegevens.
- Ondertekend contract als PDF wanneer Dompdf geïnstalleerd is.
- Contractkopie via PHPMailer; in testmodus wordt een mailvoorbeeld lokaal opgeslagen.
- Optionele private documentupload buiten de publieke webmap.

## Vereisten

- PHP 8.2 of hoger
- PHP-extensies `pdo_sqlite`, `fileinfo`, `dom` en `mbstring`
- Composer
- Schrijfrechten op `storage/`
- HTTPS in productie

Lokaal gebruikt `composer serve` de map `public/` als document root. Voor Combell kan de volledige projectmap onder `/www/huur-module/` staan: de root-entrypoints laden de echte bestanden uit `public/` en de root-`.htaccess` routeert `/assets/...` naar `public/assets/...` en blokkeert private projectmappen.

## Eerste lokale installatie

Voer vanuit de hoofdmap van de repository uit. Kopieer het voorbeeld alleen
als je nog geen eigen `.env` hebt:

```powershell
Copy-Item huur-module/.env.example huur-module/.env
```

Vul een eigen `ADMIN_EMAIL` en sterk `ADMIN_PASSWORD` in. Gebruik voor een lokale
preview `APP_ENV=local`, `APP_URL=http://localhost:8080` en `MAIL_TRANSPORT=log`.
De standaarddatabase is `storage/database.sqlite`; begin met een lege testdatabase.

```sh
composer install --working-dir=huur-module
php huur-module/bin/setup.php
composer --working-dir=huur-module serve
```

Open [de lokale verhuurmodule](http://localhost:8080/). Het setup-script maakt de
databasetabellen en het eerste beheerdersaccount aan. Voor alle modules samen en
werkende dashboardlinks gebruik je een lokale Apache-site zoals beschreven in
de [algemene README](../README.md).

## Combell File Manager deployment

Doelmap:

```text
/www/huur-module/
```

De repository bevat root-entrypoints voor `index.php`, `planning.php`, `bikes.php`, `reservation-new.php`, `reservation.php`, `contract.php`, `sign.php`, `users.php`, `bike-photo.php`, `api-bike-availability.php` en `reservation-stamp.php`.

Hierdoor zijn de normale productie-URL's:

```text
https://aertsactionbike.cc/huur-module/
https://aertsactionbike.cc/huur-module/planning.php
https://aertsactionbike.cc/huur-module/bikes.php
```

Directe links onder `/public/*.php` worden door `.htaccess` terug naar de root-route gestuurd. `public/assets/` blijft de fysieke assetmap; `/assets/...` wordt intern daarheen gerouteerd.

Bij een File Manager-update mag je de code uit de lokale projectmap overschrijven, maar **niet blind de volledige lokale map over productie zetten**. Behoud altijd de serverversies van:

```text
.env
storage/database.sqlite
storage/private/
storage/logs/
storage/backups/
```

De lokale `.env` kan localhost-instellingen bevatten en de lokale database kan testdata bevatten. Upload `.git/` en lokale backups niet naar productie.

## Bestaande installatie bijwerken

Volg eerst de [algemene updateprocedure](../docs/deployment.md), inclusief een
consistente back-up van database en private bestanden. Gebruik de gekozen branch
van deze dashboardrepository. Voer bij wijzigingen aan afhankelijkheden of het
databaseschema vanuit de repository uit:

```sh
composer install --working-dir=huur-module --no-dev --optimize-autoloader
php huur-module/bin/setup.php
```

Het setup-script werkt op de database uit je `.env`. Bij een SFTP-installatie
werkt lokaal uitvoeren alleen de lokale database bij; een benodigde
productiemigratie moet op de hosting tegen de bedoelde database worden uitgevoerd.
Geef de PHP-gebruiker gerichte schrijfrechten op `storage/`.

`php bin/setup.php`:

- verwijdert geen bestaande reservaties, fietsen of gebruikers;
- maakt `reservation_bikes` en `payment_logs` aan;
- koppelt elke bestaande reservatie automatisch aan de reeds opgeslagen fiets;
- voegt ontbrekende afsluitstempels en fietsvelden toe;
- maakt de private fotomap aan.

## Belangrijkste routes

- `planning.php`
- `reservation-new.php`
- `reservation.php?id=1`
- `bikes.php`
- `users.php` — alleen beheerders
- `contract.php?reservation_id=1`
- `sign.php?token=...`

Oude links via `index.php?route=...` worden automatisch doorgestuurd.

## Meerdere fietsen reserveren

1. Open **Nieuwe verhuur**.
2. Stel eerst start- en eindmoment in.
3. De selectielijst toont per fiets **BESCHIKBAAR**, **IN ONDERHOUD**, **INACTIEF** of **AL GERESERVEERD**.
4. Selecteer meerdere fietsen met Ctrl op Windows of Command op Mac.
5. Sla het dossier op.
6. De module maakt één gezamenlijk contract voor alle geselecteerde fietsen.

De controle gebeurt zowel in de browser als opnieuw op de server bij het opslaan.

## Type kiezen bij aanmaak

Zowel **Nieuwe verhuur** als **Snelle vervangfiets** heeft het veld
**Type reservatie** met **Huur**, **Test** en **Vervang**. Nieuwe verhuur begint
standaard op Huur; de snelle registratie op Vervang. De keuze wordt opgeslagen
op het dossier en verschijnt in de planning en het kasboek.

Bij Nieuwe verhuur blijft de bestaande automatische of handmatige prijs gelden.
Het gekozen type wijzigt het bedrag niet automatisch: controleer de totaalprijs
en vul €0 in voor een gratis test of vervangfiets. Een betaling kan niet hoger
zijn dan de totaalprijs, ook niet bij €0. Na opslaan opent Huur het gezamenlijke
contract; Test en Vervang openen het dossier.

Een snelle registratie begint voor elk type op €0. Bij Huur en Test opent na
opslaan het dossier, waar je een eventuele prijs, e-mailadres en overige
klantgegevens kunt aanvullen en zo nodig een contract opmaken. Vervang keert
zoals voordien terug naar de planning. De bestaande toegangsrechten blijven gelden.

## Een reservatie aanpassen

Open een reservatie vanuit **Planning** en kies **Dossier aanpassen**. Je kunt
startdatum, startuur, einddatum, einduur, **Huur / Test / Vervang**, klantnaam,
telefoon, e-mail, adres, status en interne notities wijzigen. Klik daarna op
**Wijzigingen opslaan**. Ingevoerde gegevens blijven bij een validatiefout staan.

- Alle gekoppelde fietsen blijven behouden; iedere fiets wordt op overlap met
  andere reservaties gecontroleerd. Een vervangdossier met één fiets behoudt
  ook de mogelijkheid om die fiets te wisselen.
- De afgesproken prijs en betalingen worden niet automatisch herberekend bij
  een wijziging van periode of type. Gebruik daarvoor de bestaande prijs- of
  vervangkostbewerking. Testdossiers met een bedrag blijven in het kasboek staan.
- Wijzigingen aan klantgegevens, periode, type of fiets maken een bestaand
  conceptcontract en de oude ondertekenlink ongeldig. Ondertekende contracten,
  handtekeningen en PDF's blijven met hun oorspronkelijke afspraken bewaard.
- Bij een gewijzigde klantnaam vervalt de eerdere eID-bevestiging. Klantgegevens
  die uitzonderlijk door meerdere dossiers gedeeld worden, zijn hier geblokkeerd
  om wijzigingen aan andere dossiers te voorkomen.
- Boekhouding behoudt alleen leesrechten. Geannuleerde dossiers zijn niet
  bewerkbaar. Een dossier dat ondertussen gewijzigd is, moet eerst worden herladen.
- De oude link **Einddatum aanpassen** verwijst naar hetzelfde dossierformulier.

### Deze update installeren

Maak eerst een consistente back-up van de productiegegevens. Upload deze
gewijzigde programmabestanden samen, met behoud van hun mappenstructuur:

```text
huur-module/.htaccess
huur-module/app/database.php
huur-module/app/reservation_edit.php
huur-module/app/views.php
huur-module/bin/setup.php
huur-module/database/schema.sql
huur-module/public/reservation.php
huur-module/public/reservation-end-date.php
huur-module/public/reservation-new.php
huur-module/public/quick-replacement.php
huur-module/public/planning.php
huur-module/public/cashbook.php
huur-module/public/assets/planning-status.css
huur-module/public/assets/reservation-end-date.js
```

De uitbreiding voor **Test** wordt bij de eerste databaseverbinding automatisch
op de bestaande SQLite-database toegepast. Bestaande dossiernummers, gekoppelde
fietsen, betalingen en contracten blijven behouden. Daarvoor is schrijfrecht op de
database én de bijbehorende map nodig. Vervang de online database, `.env` en
private bestanden niet door lokale exemplaren. De map `tests/` hoeft niet naar
de hosting.

De [regressietests voor dossierbewerking](tests/README.md) gebruiken uitsluitend
synthetische gegevens en controleren ook de migratie van bestaande databases.

## Betalingslog

Bij de aanmaak kan onmiddellijk een betaling via Bancontact of cash worden geregistreerd. Nadien kunnen bijkomende betalingen vanuit de verhuurfiche worden toegevoegd.

Elke logregel bewaart:

- bedrag;
- betaalwijze;
- datum en uur;
- medewerker;
- optionele notitie.

Betalingen worden niet overschreven. Het openstaande saldo wordt berekend als totaalprijs min de som van alle logregels.

## Planning en kleurlegende

De planning bevat een vaste legende voor:

- Gereserveerd;
- Bevestigd;
- Afgehaald;
- Teruggebracht;
- Beschikbare fiets;
- Fiets in onderhoud;
- Inactieve fiets.

Onderhoud en inactief worden als geblokkeerde rijen weergegeven. Bestaande reservaties blijven zichtbaar, maar nieuwe lege tijdvakken zijn niet aanklikbaar.

## Fietsidentificatie

Per fiets kunnen worden bijgehouden:

- interne code;
- naam en model;
- uniek framenummer;
- categorie en framemaat;
- dagprijs en status;
- JPG-, PNG- of WebP-afbeelding.

```dotenv
BIKE_IMAGE_MAX_MB=8
```

Afbeeldingen worden opgeslagen onder `storage/private/bikes/` en alleen via een beveiligde route aan ingelogde medewerkers getoond.

## E-mail

Testmodus:

```dotenv
MAIL_TRANSPORT=log
```

Mailvoorbeelden worden opgeslagen onder `storage/private/mail/`.

Voor echte verzending configureer je het passende mailtransport en de bijbehorende
SMTP- of Microsoft Graph-gegevens in `.env`. Plaats wachtwoorden en sleutels nooit
in GitHub. Test mailinstellingen uitsluitend met een daarvoor bestemd adres.

## Planning sneller laden

De planning laadt alleen de drie benodigde stylesheets en het script voor de
klantnaam bij aanwijzen, in plaats van negen stylesheets en acht scripts.
Betalingen worden via de bestaande index alleen voor de gevonden reservaties
opgeteld. De planning bewaart het afmeldtoken en meldingen en geeft daarna de
sessie vrij vóór het databasewerk. Fietsfotoverzoeken geven de sessie direct na
de toegangscontrole vrij, zodat het opzoeken en versturen van foto's geen
volgende navigatie binnen die sessie blokkeert.

Upload voor deze verbetering de volgende bestanden samen:

```text
huur-module/app/repositories.php
huur-module/app/views.php
huur-module/public/bike-photo.php
huur-module/public/planning.php
```

Er is geen databasemigratie nodig. Upload geen lokale database of `.env`.
De planning behoudt actuele gegevens en wordt niet gecachet. Andere pagina's
behouden hun bestaande scripts en stylesheets.

Voor verdere diagnose bevat het planningdocument een `Server-Timing`-header:
`bootstrap` meet het opstarten inclusief sessiewachttijd, `data` het ophalen en
voorbereiden van gegevens. Deze waarden bevatten geen PHP-workerwachtrij,
netwerkvertraging of daaropvolgende HTML-rendering. In de browser zijn ze bij
Network → planning.php → Headers te bekijken. Minder databasewerk en downloads
zijn geen garantie tegen tijdelijke hosting- of netwerkvertraging.

## Hosting en OPcache controleren

Upload voor de beveiligde meetpagina deze drie bestanden met hun mappenstructuur:

```text
huur-module/app/diagnostics.php
huur-module/public/system-check.php
huur-module/system-check.php
```

Meld je aan als beheerder en open `/huur-module/system-check.php` op de hosting.
Bij een installatie waarbij `public/` de webroot is, open je daar
`system-check.php`. De pagina toont de PHP-versie, opstarttijd inclusief het
openen van de sessie, geheugen van dit verzoek, OPcache-instelling en beschikbare
OPcache-status. **Download meetrapport (JSON)** maakt een nieuwe meting die je
kunt delen voor analyse. Herhaal bij klachten en op een rustig moment om te
kunnen vergelijken. Er wordt geen historie opgeslagen.

De controle leest uitsluitend metingen: er is geen databaseverbinding,
OPcache-reset of wijziging van hostinginstellingen. Alleen beheerders krijgen
toegang. De PHP-antwoorden krijgen `Cache-Control: no-store, private`, ook bij
een geweigerde toegang of verwijzing naar de aanmeldpagina. De JSON bevat geen wachtwoorden,
klantgegevens, sessiegegevens of scriptpaden.

Interpretatie:

- **Status onbekend** betekent dat de host de status niet beschikbaar maakt;
  het bewijst niet dat OPcache uitgeschakeld is. De instelling `opcache.enable`
  wordt afzonderlijk getoond.
- OPcache-tellers kunnen gedeeld worden met andere sites in dezelfde PHP-pool
  en zijn cumulatief. Een volle cache, weinig vrije ruimte of oplopende herstarts
  zijn aanleiding om de capaciteit met de hostingprovider te controleren.
- Load averages betreffen de host of container en zijn geen CPU-percentages van
  dit hostingaccount. Zonder servercapaciteit kan je geen overbelasting afleiden.
- De PHP-tijden meten deze controle tot het meetmoment. Netwerktijd en wachttijd
  vóór een PHP-worker beschikbaar komt zitten daar niet in. Het geheugen betreft
  alleen dit PHP-verzoek.

Voor CPU/RAM-verbruik van je account, I/O-limieten, afremming en PHP-FPM-workers
of wachtrijen zijn statistieken uit het hostingpaneel of van de provider nodig.
Noteer bij screenshots steeds het tijdstip waarop de site traag was. Deze
meetpagina vervangt die providergegevens niet.

## Controle na update

Voer deze gerichte controles uit vanuit `huur-module/`:

```bash
php -l app/repositories.php
php -l app/contracts_v2.php
php -l public/planning.php
php -l public/reservation-new.php
php -l public/reservation.php
php -l public/api-bike-availability.php
```

Open daarna de planning en test één dossier met minstens twee fietsen en één deelbetaling.

## Juridisch en privacy

De contracttekst is een operationeel model en moet vóór definitieve productie juridisch worden nagekeken. Maak een kopie van een identiteitskaart niet verplicht zonder concrete wettelijke basis. Gebruik waar mogelijk visuele identificatie en verwerk alleen noodzakelijke gegevens.
