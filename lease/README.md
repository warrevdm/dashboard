# Lease Import Manager

[Algemene README](../README.md) · [Hosting en updates](../docs/deployment.md)

Interne tool voor het importeren van leasecontracten, klant- en fietsgegevens,
onderhoudsbudgetten, archivering en opvolging via een dossierlogboek.
De webapp staat onder `/lease/public/` en gebruikt MySQL/MariaDB.

- [Eerste installatie](#eerste-installatie)
- [Wekelijkse dinsdagmail](#wekelijkse-dinsdagmail)
- [Toegang instellen of bijwerken](#beveiligingsupdate-installeren)
- [Beveiligingstests](#gerichte-beveiligingstests)

## Eerste installatie

Gebruik PHP 8.2 of hoger (64-bit) voor de volledige dashboardrepository en installeer
vanuit de hoofdmap de lease-afhankelijkheden:

```sh
composer install --working-dir=lease
```

Maak een lege lokale MySQL/MariaDB-database aan en importeer
[`database/schema.sql`](database/schema.sql). Het voorbeeldschema gebruikt
`lease_import_manager` met `CREATE DATABASE` en `USE`; pas die naam en instructies
aan als je hosting alleen een vooraf aangemaakte database toestaat. Gebruik deze
eerste-installatiestap niet als standaard update van een bestaande databank.

Kopieer [`config/config.example.php`](config/config.example.php) naar
`config/config.local.php` voor lokale gegevens en configureer op de hosting
afzonderlijk `config/config.production.php`. Maak alleen ontbrekende bestanden aan.

De bestaande loader kiest lokale instellingen bij exact `localhost` of
`127.0.0.1` als HTTP-host. Een hostnaam met een afwijkende poort, zoals
`localhost:8080`, kiest momenteel de productieconfiguratie. Gebruik voor deze
module een lokale Apache-site op de standaardpoort. De geplande mailtaak kiest
altijd expliciet de productieconfiguratie, tenzij `AAB_LEASE_DB_FILE` is ingesteld.

Genereer vervolgens de private aanmeldconfiguratie:

```sh
php lease/scripts/configure-auth.php
```

Bewaar de getoonde toegangssleutel en open `/lease/public/` op de lokale site.
Op productie moeten de private configuratie en de meegeleverde afscherming
aanwezig zijn voordat je de app gebruikt. Zie hieronder voor installatie op een
bestaande omgeving en optionele mailverzending.

## Wekelijkse dinsdagmail

De lease-module kan iedere dinsdag een **intern** overzicht sturen van alle
niet-gearchiveerde contracten die tussen de verzenddatum en drie kalendermaanden
later aflopen én geen enkele rij in `customer_logbook` hebben. Een oude
logboekactie telt ook als opvolging. Contracten met een verlopen of ontbrekende
einddatum vallen buiten deze selectie. Het overzicht is gesorteerd op einddatum.

De mail bevat klant, contractnummer, partner, fiets, einddatum, resterende dagen,
geregistreerd onderhoudsbudget en een beveiligde dossierlink. Er wordt geen
klantmail verstuurd en er wordt geen logboekactie aangemaakt. Een nog niet
opgevolgd contract blijft de volgende week terugkomen zolang het in de periode
valt. Een lege selectie levert een korte bevestiging op.

De afgesproken standaardontvangers zijn **info@aertsactionbike.be** en
**marketing@aertsactionbike.be**. Je kunt ze aanpassen in de private configuratie;
die lijst vervangt de standaardontvangers volledig. De automatische verzending
blijft standaard uitgeschakeld totdat de mailserver en de geplande taak zijn ingesteld.

Onder **Dinsdagmail** zie je de ingestelde ontvangers, planning, laatste
verzendstatus per ontvanger en een actueel mailvoorbeeld. **Contracten verlopen →
Zonder logboek** gebruikt exact dezelfde selectie. Het mailvoorbeeld kan dus
anders zijn dan de selectie op de eerstvolgende dinsdag.

### Activeren via het hostingpaneel met een cron-URL

Open na aanmelden **Dinsdagmail**. Klik **Dinsdagmail om 09:00 inschakelen** (de mailinstellingen en PHPMailer moeten volledig zijn). Open **Cronjob instellen bij de hosting** en kopieer de volledige URL, inclusief de persoonlijke sleutel, naar de URL-taak in het hostingpaneel. Kies `0 * * * *`: ieder uur, minuut 0. De applicatie bepaalt zelf dinsdag en 09:00 Europe/Brussels, ook bij zomer-/wintertijd.

Het formulier bewaart de activering in `storage/weekly-mail/automation.json`, samen met een willekeurige 256-bit cronsleutel. Deze expliciete dashboardinstelling heeft voorrang op `enabled` uit de private mailconfiguratie en stelt het uur in op 9. **Uitschakelen** pauzeert de automatisering; **Nu versturen** blijft apart werken. Bewaar de statusmap bij deployment; plaats de sleutel niet in Git of publieke communicatie.

De webroute `lease/public/weekly-mail-cron.php` accepteert uitsluitend HTTPS GET met de juiste sleutel (queryparameter `key`, of header `X-Cron-Key`). Zonder sleutel gebeurt geen databank- of SMTP-actie. De route heeft geen browsersessie nodig. Buiten dinsdag vanaf 09:00 of bij uitgeschakelde planning gebeurt geen verzending. Handmatig forceren of herproberen van onzekere verzendingen is niet beschikbaar via deze route. CLI en web delen de verzendregistratie en vergrendeling; gebruik één hostingtaak. Een cron-URL openen op dinsdag na 09:00 kan daadwerkelijk de mail versturen.

De webroute gebruikt dezelfde productieconfiguratie als de CLI: `lease/config/config.production.php`, of `AAB_LEASE_DB_FILE`. Ze toont alleen status en aantallen, geen contractgegevens of geheime instellingen.

### Meteen versturen via de leasingtool

Open na het aanmelden **Dinsdagmail** en klik op **Nu versturen**. Dit verstuurt
het actuele overzicht naar de ingestelde interne ontvangers, op elke dag en elk
uur. Ook bij een lege selectie komt er een bevestigingsmail. De knop werkt zodra
de ontvangers, afzender, SMTP en mailbibliotheek zijn ingesteld; een cronjob is
hiervoor niet nodig. `enabled` mag op `false` blijven staan: die instelling regelt
uitsluitend de automatische dinsdagmail.

De knop gebruikt een beveiligd POST-formulier met CSRF-controle en een eenmalige
verzendcode. Vernieuwen van de resultaatpagina of dubbelklikken verstuurt het
overzicht niet opnieuw. Een volgende bewuste klik met een nieuw formulier is een
nieuwe verzending. De laatste handmatige poging en de laatste dinsdagmail zijn
apart zichtbaar, inclusief status per ontvanger. Beide gebruiken dezelfde
vergrendeling zodat ze niet tegelijk verzenden. Er worden maximaal honderd
handmatige pogingen bewaard, zonder mailinhoud of klantgegevens.

Een handmatige mail wijzigt de dinsdagplanning en het klantlogboek niet. Bij een
onzekere of gedeeltelijk mislukte poging controleer je eerst de status en de
mailboxen: een nieuwe klik mailt opnieuw naar alle ingestelde ontvangers.

### Installeren en activeren

1. Installeer de vorige toegangsbeveiligingsupdate en behoud je private
   aanmeldconfiguratie. Deze feature verandert de toegangssleutel niet en vereist
   geen SQL-migratie.
2. Installeer de bijgewerkte PHP-afhankelijkheden, inclusief PHPMailer:

   ```sh
   composer install --working-dir=lease --no-dev --optimize-autoloader
   ```

   Upload bij een FTP/SFTP-installatie ook de opnieuw opgebouwde `lease/vendor`
   naast de gewijzigde bronbestanden. Node.js is alleen nodig voor de tests.
3. Kopieer `lease/config/weekly-mail.example.php` naar
   `lease/config/weekly-mail.local.php`. De twee afgesproken **interne** ontvangers
   zijn al ingevuld. Vul de toegestane afzender en SMTP-gegevens van je mailprovider
   in; die velden bevatten nog placeholders. Heb je al een privaat bestand, pas
   dan alleen de `recipients`-lijst aan en behoud de overige instellingen.
   Houd `enabled` voorlopig op `false`. Gebruik TLS met poort 587
   of SMTPS met poort 465 volgens je provider. Certificaatcontrole blijft aan.
   Dit private bestand is uitgesloten van Git en moet door PHP leesbaar zijn.
4. Zorg dat de geplande taak de juiste databank gebruikt. Standaard leest de CLI
   **`lease/config/config.production.php`**, onafhankelijk van de HTTP-hostnaam.
   Voor een lokale test of een externe configuratie stel je `AAB_LEASE_DB_FILE`
   expliciet in. Het pad moet hetzelfde productiegegevensbestand aanwijzen als
   de webapp. Er is bewust geen automatische fallback naar de lokale databank.
5. Controleer **Dinsdagmail** in de webapp en voer op de bedoelde server uit:

   ```sh
   php lease/scripts/send-weekly-mail.php --dry-run
   ```

   Dit toont aantallen, periode en configuratiefouten; het verstuurt niets en
   verandert de verzendstatus niet. Het controleert geen SMTP-verbinding of
   aflevering. Verifieer de ontvangers en de mailproviderinstellingen apart.
6. Laat de hosting ieder uur deze CLI-taak starten. Voorbeeld voor cron, met
   absolute paden die je aan je server moet aanpassen:

   ```cron
   0 * * * * /usr/bin/php /PAD/NAAR/dashboard/lease/scripts/send-weekly-mail.php >> /PAD/NAAR/dashboard/lease/storage/weekly-mail-cron.log 2>&1
   ```

   De applicatie controleert zelf dinsdag en het verzenduur. Standaard is dat
   **dinsdag vanaf 09:00 Europe/Brussels**, inclusief zomer- en wintertijd,
   ongeacht de tijdzone van de server. Na activeren via de webapp is het uur vast 09:00.
   Iedere andere dag slaat de taak over. Een gemiste dinsdag wordt niet op een
   andere dag ingehaald. Op Windows kan dezelfde PHP-opdracht als ieder uur
   herhaalde taak in Taakplanner worden ingesteld.
7. Zet `enabled` op `true` in de private configuratie. Controleer na de eerste
   dinsdag zowel de ontvangersmailbox als de verzendstatus. Alleen deze instelling
   wijzigen maakt **geen** cronjob aan. Zonder ingestelde servertaak komt er geen
   automatische mail.

De CLI-taak werkt zonder browsersessie; een HTTP-verzoek naar het CLI-script krijgt 404.
Voor hostingpanelen met URL-taken is er daarnaast de hieronder beschreven beveiligde webroute. Handmatig verzenden via de website
vereist een geldige aanmelding en het beveiligde formulier. Gebruik één planner en een permanente, voor PHP
schrijfbare statusmap `lease/storage/weekly-mail`. Verwijder of vervang die map
niet bij een deployment: ze voorkomt herhaalde verzending op dezelfde dinsdag.
De bestaande afscherming van private mappen moet actief blijven.

Voor configuratie buiten de webroot kun je `AAB_LEASE_WEEKLY_MAIL_FILE` instellen
op een absoluut privaat PHP-bestand met dezelfde inhoud. Geef die instelling aan
zowel PHP op de website als de geplande taak door. `state_directory` kan ook naar
een private, permanente map buiten de webroot verwijzen. Bewaar SMTP-wachtwoorden
en afwijkende ontvangerslijsten uitsluitend in de private configuratie; zet ze
niet in Git, in de crontab of in het zichtbare mailvoorbeeld.

### Dubbele mails, fouten en herstel

De taak houdt per dinsdag en per ontvanger de verzendstatus bij en vergrendelt de
uitvoering. Een herhaalde taak verstuurt een bevestigde mail niet opnieuw. Een
fout bij één ontvanger houdt de andere ontvangers niet tegen. Ontvangers worden
afzonderlijk gemaild; klantadressen worden nooit automatisch als ontvanger gekozen.

Bij een SMTP-timeout of een onderbroken proces kan het onduidelijk zijn of de mail
al is aangenomen. De taak markeert dat als controle nodig en probeert **niet**
automatisch opnieuw. Controleer eerst de mailbox en mailserver. Daarna kun je op
dezelfde dinsdag vanaf het ingestelde uur alleen de niet-bevestigde ontvangers
opnieuw proberen:

```sh
php lease/scripts/send-weekly-mail.php --retry-failed
```

Deze bewuste retry kan bij een eerder toch afgeleverde mail een duplicaat
opleveren. Reeds bevestigde ontvangers worden ook met deze vlag overgeslagen.
De volgende dinsdag wordt een nieuw overzicht berekend en opnieuw verzonden.
“Verzonden” betekent dat SMTP de mail aanvaardde; spamfilters en latere bounces
kunnen de uiteindelijke aflevering nog beïnvloeden. De status bevat geen
mailinhoud, klantgegevens of SMTP-wachtwoorden.

### Tests van de weekmail

`npm --prefix lease/tests test` draait de toegangscontroles en de weekmailtests
met een geïsoleerde PHP 8.3-runtime. De selectie wordt tegen SQLite met
voorbeeldcontracten gecontroleerd; de echte SMTP-verbinding wordt vervangen door
een lokale testimplementatie. Er worden geen echte mails verstuurd. Je kunt de
PHP-tests ook uitvoeren met `php lease/tests/weekly-mail.php` wanneer `pdo_sqlite`
beschikbaar is. De tests dekken grenzen van drie kalendermaanden, oude logboeken,
archivering, zomer-/wintertijd, HTML-escaping, lege mails, nieuwe weken,
herhaalde en overlappende taken, gedeeltelijke fouten en expliciete retries.

## Beveiligingsupdate installeren

Deze versie bevat geen toegangssleutel of geheime cookie-sleutel in Git. De oude
configuratie was openbaar: gebruik beide oude waarden nergens opnieuw. Verander
ook de toegangssleutel als die elders werd hergebruikt. Na deze update moet iedereen
opnieuw aanmelden. De bestaande dashboard-snelstart krijgt geen centrale login.

Voer de configuratiestap **vóór het vervangen van de live code** uit. Zonder geldige
private configuratie weigert de nieuwe versie toegang, ook voor bestaande sessies.

1. Haal de nieuwe code op. Genereer vanuit de repositorymap de private configuratie:

   ```sh
   php lease/scripts/configure-auth.php
   ```

   Op Windows met XAMPP kan dit in PowerShell met:

   ```powershell
   & 'C:\xampp\php\php.exe' lease/scripts/configure-auth.php
   ```

   Het script schrijft `lease/config/auth.local.php` en toont één nieuwe willekeurige
   toegangssleutel. Bewaar die in je wachtwoordmanager. Het script is uitsluitend
   via de terminal te gebruiken en overschrijft geen bestaande configuratie.

   Als de oudere generator op Windows/OneDrive meldt dat de doelmap schrijfbaar
   moet zijn, haal dan de bijgewerkte generator op en probeer vanuit de projectmap:

   ```sh
   php lease/scripts/configure-auth.php --output=lease/config/auth.local.php
   ```

   De generator probeert nu daadwerkelijk een bestand in de doelmap aan te maken.
   Bij een echte schrijffout toont hij het betrokken pad. Maak de OneDrive-map
   lokaal beschikbaar en controleer de schrijfrechten voor je eigen gebruiker.
   Bij een ontbrekende map controleer je het opgegeven pad. Het expliciete
   `--output`-argument heeft voorrang op `AAB_LEASE_AUTH_FILE`.

2. Upload `config/auth.local.php` via SFTP naar dezelfde plaats op de server vóór
   je de overige gewijzigde leasebestanden uploadt. Dit bestand wordt bewust niet
   door Git meegenomen. Upload ook de meegeleverde `.htaccess`-bestanden, inclusief
   `lease/.htaccess` en de bestanden in de private mappen. Vervang `config/auth.php`
   door de nieuwe loader; laat de oude inhoud niet staan. De bestaande database-
   configuratie en databank blijven behouden. Er is geen SQL-migratie nodig.

3. PHP moet de private configuratie kunnen lezen en naar `lease/storage/auth`
   kunnen schrijven. Gebruik correcte bestandseigenaars/rechten, geen `chmod 777`.
   De generator gebruikt bestandsrechten `0600`; pas de eigenaar aan wanneer CLI
   en PHP onder verschillende gebruikers draaien.

4. Meld aan met de nieuwe toegangssleutel. Controleer ook de importpagina, een
   wijziging aan een testcontract en afmelden. Een oude browseraanmelding moet
   naar de login gaan.

5. Controleer zonder aanmelding dat `/lease/config/auth.local.php`,
   `/lease/storage/` en `/lease/browser-connectors/` een 403 of 404 geven en nooit
   bestandinhoud tonen. Controleer hierbij ook een bestaand bestand in de
   opslagmap. Deze servercontrole is nodig naast de PHP-tests.

### Hosting

De meegeleverde afscherming vereist Apache 2.4 met `mod_rewrite` en toestemming
voor de gebruikte `.htaccess`-regels (`AllowOverride`). Bestaande aanvullende
Basic Auth in `public/.htaccess` kan behouden blijven. Als de regels een 500 geven,
laat de hoster ze op virtual-hostniveau toepassen; verwijder de bescherming niet.

Bij Nginx worden `.htaccess`-bestanden genegeerd. Laat daar bij voorkeur uitsluitend
`lease/public` als documentroot publiceren, of blokkeer de private mappen expliciet
in de serverconfiguratie. Apache-directoryregels blokkeren ook rechtstreekse
toegang tot configuratie, logs, uploads, browserprofielen, scripts en tests.

Een configuratie buiten de webroot heeft de voorkeur. Genereer die met:

```sh
php lease/scripts/configure-auth.php --output=/privaat/pad/lease-auth.php
```

Stel `AAB_LEASE_AUTH_FILE` voor de PHP-webomgeving in op dat absolute pad (alleen
instellen in je SSH-shell is niet voldoende). Optioneel kan `auth_storage_dir`
in dit bestand verwijzen naar een private schrijfbare map buiten de webroot.
Gebruik HTTPS. Een reverse proxy moet HTTPS en het echte client-IP via vertrouwde
serverconfiguratie doorgeven; de applicatie vertrouwt geen losse forwarded headers.

### Sleutels later vervangen

```sh
php lease/scripts/configure-auth.php --rotate
```

Geef bij een extern configuratiebestand ook hetzelfde `--output`-pad mee. Dit
vervangt zowel de toegangssleutel als de cookie-sleutel. Bestaande sessies en
onthouden aanmeldingen worden bij hun volgende verzoek geweigerd. Oude cookies
uit de versie vóór deze update worden altijd geweigerd. Reeds uitgelekte kopieën
in de Git-geschiedenis worden door deze wijziging niet verwijderd; rotatie maakt
ze onbruikbaar op de bijgewerkte installatie.

## Gedrag van de toegangscontrole

- Alle beschermde pagina's controleren de aanmelding vóór gegevensverwerking.
- Alle wijzigingsacties vereisen POST en een token dat bij de sessie hoort.
  Mailacties en bronimports gebruiken daarom formulieren in plaats van GET-links.
- Imports controleren dit vóór het laden van de spreadsheetbibliotheek of het
  opslaan/uitlezen van bestanden. Nieuwe uploads krijgen een willekeurige naam;
  de uploadlimiet is 10 MB (PHP kan een lagere serverlimiet opleggen).
- Na vijf foute sleutels geldt standaard vijf minuten blokkering per client-IP.
  De teller staat op de server en blijft bestaan wanneer iemand cookies wist.
  Er worden geen wachtwoorden of leesbare IP-adressen in deze teller opgeslagen.
  Een onbeschikbare teller blokkeert nieuwe aanmeldingen in plaats van de controle
  over te slaan. De opslag is bedoeld voor één server; bij meerdere instanties
  is gedeelde opslag vereist.
- Sessies verlopen standaard na acht uur inactiviteit of na 24 uur. Bij expliciet
  aangevinkt 'ingelogd blijven' kan een geldige cookie een nieuwe sessie starten,
  tot maximaal 30 dagen. Afmelden verwijdert de cookies van de huidige browser.
- Het blijft één gedeelde toegangssleutel voor de leasingtool. Individuele
  medewerkersaccounts en rollen zijn geen onderdeel van deze update.

## Gerichte beveiligingstests

Met Node.js 24 kun je de geïsoleerde PHP 8.3-tests uitvoeren:

```sh
npm --prefix lease/tests ci
npm --prefix lease/tests test
```

Deze tests gebruiken tijdelijke configuratie en sessies, controleren alle PHP-
bestanden op syntax en oefenen de echte aanmeld- en formuliercontroles uit.
Database- en spreadsheetverwerking worden na de toegangscontrole vervangen door
testmarkeringen; er worden geen echte contracten of partnerportalen aangeraakt.
De suite controleert ook cookieherstel, sleutelrotatie, verouderde sessies,
verlopen sessies, blokkering over meerdere browsersessies en de CLI-generator.
Webserverregels en de volledige importwerking moeten apart op de doelomgeving
worden gecontroleerd. De testafhankelijkheden zijn niet nodig op productie.

### Cyclis CSV importeren

Upload het originele Cyclis-bestand via **Excel importeren**. CSV met puntkomma's, komma's of tabs en een UTF-8 BOM wordt rechtstreeks als tekst gelezen; CSV vereist hiervoor geen PhpSpreadsheet. Voor XLS/XLSX blijven de Composer-afhankelijkheden nodig.

Automatische herkenning koppelt `Pickup Date` aan de startdatum, `End date` aan de einddatum, `lease maatschap` aan de leasepartner en `Available Maintenance Budget` aan het budget. `Dealer` is geen werkgever en wordt niet als Bedrijf geïmporteerd. Gebruik de automatische herkenning of controleer oudere opgeslagen templates.

Bij een bestaand SO-nummer blijven ontbrekende en lege velden behouden. Nul- en negatieve onderhoudsbudgetten worden wel bijgewerkt. De preview meldt ontbrekende contractdatums; nieuwe dossiers zonder datum krijgen geen verzonnen datum. De definitieve import wordt pas gestart na de bestaande preview en bevestiging.

Test: `cd lease/tests && npm run test:import`. Een lokaal CSV-bestand kan optioneel als argument aan `cyclis-import.cjs` meegegeven worden; de test rapporteert alleen aantallen en slaat geen klantgegevens op in de repository.

### Betrouwbaarheid en contractbeheer

- Het contractoverzicht toont standaard 50 resultaten per pagina (ook 100/200 mogelijk), met behoud van filters en sortering. CSV-export bevat alle overeenkomende actieve contracten.
- Overzicht en export gebruiken dezelfde Belgische kalenderdatums. De periode “binnen drie maanden” gebruikt kalendermaanden.
- Imports controleren alle rijen op ongeldige datums, bedragen, dubbele SO-nummers en ongeldige kolomkoppelingen vóór verwerking. Bij deze fouten verschijnt een rijmelding; herstel het bestand of de koppeling en start de preview opnieuw. Ontbrekende datums zijn nog steeds toegestaan.
- Mapping, batch en contractwijzigingen van een import worden samen opgeslagen. Bij een databasefout wordt de hele import teruggedraaid. Dit is geen herstel van oude batches; de bestaande rollback-knop verwijdert alleen nieuw aangemaakte contracten.
- Contractbewerking controleert datums, bedragen, e-mail, veldlengte en fietstype. Foutieve invoer blijft bewaard bij terugkeer naar het formulier. Bij een tussentijdse wijziging door een import of collega verschijnt eerst een melding; controleer de bewaarde invoer tegenover de opgeslagen gegevens.
- Bij het bewerken worden O2O/Cyclis-budgetdatums opnieuw berekend. Maand- en jaargrenzen worden begrensd op de laatste geldige dag (29 februari + één jaar wordt 28 februari). Bij wijziging van periode of partner wordt ook het jaarlijkse Lease a Bike-onderhoud opnieuw berekend.
- CSV-export beschermt tekstvelden tegen interpretatie als spreadsheetformule; echte nul- en negatieve budgetten blijven bedragen.

Upload gewijzigde PHP-bestanden en nieuwe helpers samen. Er is geen databasewijziging nodig voor deze verbeteringen. Het toegangsbestand, SMTP-configuratie en contractgegevens blijven runtimegegevens.

Voer de volledige geïsoleerde controles uit met `cd lease/tests && npm ci && npm test`. De tests gebruiken tijdelijke configuratie, synthetische gegevens en PHP/Wasm; ze versturen geen echte mail en verbinden niet met de productiedatabase.
