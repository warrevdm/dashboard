# Lease Import Manager

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
