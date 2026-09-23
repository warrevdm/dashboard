# Lease Import Manager

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
die lijst vervangt de standaardontvangers volledig. De verzending blijft standaard
uitgeschakeld totdat de mailserver en de geplande taak zijn ingesteld.

Onder **Dinsdagmail** zie je de ingestelde ontvangers, planning, laatste
verzendstatus per ontvanger en een actueel mailvoorbeeld. **Contracten verlopen →
Zonder logboek** gebruikt exact dezelfde selectie. Het mailvoorbeeld kan dus
anders zijn dan de selectie op de eerstvolgende dinsdag.

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
   ongeacht de tijdzone van de server. Je kunt `send_hour` aanpassen (0–23).
   Iedere andere dag slaat de taak over. Een gemiste dinsdag wordt niet op een
   andere dag ingehaald. Op Windows kan dezelfde PHP-opdracht als ieder uur
   herhaalde taak in Taakplanner worden ingesteld.
7. Zet `enabled` op `true` in de private configuratie. Controleer na de eerste
   dinsdag zowel de ontvangersmailbox als de verzendstatus. Alleen deze instelling
   wijzigen maakt **geen** cronjob aan. Zonder ingestelde servertaak komt er geen
   automatische mail.

De taak werkt zonder browsersessie en is uitsluitend via PHP CLI uitvoerbaar;
een HTTP-verzoek naar het script krijgt 404. Er is geen publieke cron-URL of
geheime sleutel in een URL. Gebruik één planner en een permanente, voor PHP
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
