# Hosting en updates

[Terug naar de algemene README](../README.md)

De hoofdmap van deze repository komt overeen met de publieke website-map op de
hosting, bijvoorbeeld `/www/`. Gebruik steeds dezelfde hostnaam voor de interne
tools; cookies op `www.aertsactionbike.cc` en `aertsactionbike.cc` kunnen verschillen.

## Voor een update

1. Controleer welke branch en wijzigingen je uitrolt. Een open pull request
   staat niet automatisch op `main`.
2. Maak een herstelbare back-up van de huidige code, private configuratie en
   gegevens. Gebruik voor actieve databases een consistente databaseback-up:
   MySQL-export voor leasing en de SQLite-back-upfunctie voor verhuur en mailing.
   Kopieer niet zomaar een SQLite-bestand terwijl de app erin schrijft.
3. Bekijk `git status`, haal de gekozen branch op met `git pull --ff-only` en
   controleer de gewijzigde bestanden.
4. Lees de modulehandleiding bij wijzigingen aan configuratie of databases.
   Voer installatie- of migratiescripts alleen uit als die update ze vereist.

## Wat upload je?

| Onderdeel | Uploaden bij wijzigingen | Behouden op de server |
| --- | --- | --- |
| Snelstart | `home/` inclusief assets en `.htaccess`; eventueel `index.html` | Eventuele eigen aanvullende serverinstellingen |
| Verhuur | Gewijzigde PHP-bestanden, `app/`, `public/`, root-entrypoints en `.htaccess` | `.env` en de volledige `storage/` |
| Mailing | Gewijzigde PHP-bestanden, `src/` zonder private config, `assets/` en `.htaccess` | `src/config.php` en `data/` |
| Leasing | Gewijzigde PHP-bestanden, `app/`, `public/`, loaders/voorbeelden in `config/`, `scripts/` en meegeleverde `.htaccess`-bestanden | Private configuratie, MySQL-database, inhoud van `storage/` en lokale connectorinstellingen/profielen |

Upload verborgen `.htaccess`-bestanden mee; de toolmappen bevatten ook private
bestanden die door de webserver moeten worden afgeschermd. `lease/storage/.htaccess`
is broncode die mee kan veranderen; de overige runtime-inhoud van `storage/`
moet blijven staan.

Als Composer-afhankelijkheden gewijzigd zijn, voer dan vanuit de repository uit:

```sh
composer install --working-dir=huur-module --no-dev --optimize-autoloader
composer install --working-dir=mailing-system --no-dev --optimize-autoloader
composer install --working-dir=lease --no-dev --optimize-autoloader
```

Dit hoeft alleen voor de getroffen modules. Bij SFTP zonder Composer op de
hosting upload je ook de bijbehorende `vendor/`. Bouw met een PHP-versie en
extensies die passen bij de hosting; gebruik geen `--ignore-platform-reqs`.
Waar een terminal beschikbaar is, controleert bijvoorbeeld
`composer check-platform-reqs --working-dir=lease --no-dev` de werkelijke PHP-omgeving.

Documentatie, `.git/`, `node_modules/`, tests, ontwikkeltools en lokale backups
zijn niet nodig op de publieke hosting. Publiceer browserprofielen en
connectorgeheimen niet; de connectors zijn lokale hulpmiddelen, geen webpagina's.

## Private bestanden

Bewaar in ieder geval:

| Pad | Inhoud |
| --- | --- |
| `huur-module/.env` | Databasepad, bedrijfsinstellingen en mailconfiguratie |
| `huur-module/storage/` | SQLite-database, foto's, contracten, handtekeningen en logs |
| `mailing-system/src/config.php` | Graph-gegevens en aanmeldinstellingen |
| `mailing-system/data/` | Accounts, mailhistoriek en aanmeldlogboek |
| `lease/config/config.local.php` | Lokale databaseconfiguratie |
| `lease/config/config.production.php` | Productiedatabaseconfiguratie, ook gebruikt door de CLI-mailtaak |
| `lease/config/auth.local.php` | Gehashte toegangssleutel en cookie-sleutel |
| `lease/config/weekly-mail.local.php` | Afzender, SMTP-wachtwoord en dinsdagmailinstellingen |
| `lease/storage/` | Uploads, imports, aanmeldtellers en verzendstatus |

Bij configuratie buiten de webroot gelden de ingestelde absolute paden en
omgevingsvariabelen. Vervang een bestaand privaat bestand niet door een voorbeeld.
Een code-update vereist normaal geen nieuwe toegangssleutel.

## Serverinstellingen

De meegeleverde afscherming en routering gebruiken Apache 2.4-regels. Zorg dat de
hosting die `.htaccess`-regels toepast. Bij een andere webserver moeten
gelijkwaardige regels aanwezig zijn voordat de apps publiek bereikbaar zijn.

Gebruik HTTPS. Geef PHP alleen de benodigde lees- en schrijfrechten en zorg dat
de CLI-mailtaak en de webapp dezelfde private configuratie en statusmap gebruiken.
De dinsdagmail is een **PHP CLI-taak**; een geplande taak die alleen een webadres
oproept, kan het script niet uitvoeren. Zie de [leasehandleiding](../lease/README.md).

## Controle na upload

1. Open de snelstart en controleer de links naar de tools.
2. Controleer aanmelden, navigeren en afmelden bij iedere bijgewerkte module.
3. Controleer zonder aanmelding dat private configuratie en opslag niet via
   een webadres uitgelezen kunnen worden.
4. Controleer de relevante werking met testgegevens: bijvoorbeeld een
   huurreservatie, een mailvoorbeeld of een lease-importpreview.
5. Controleer bij een mailupdate de SMTP-instellingen en verzendstatus.
   `php lease/scripts/send-weekly-mail.php --dry-run` verstuurt niets en test
   geen daadwerkelijke SMTP-aflevering. **Nu versturen** verstuurt wel een echte mail.

## Opschoning van eerder meegestuurde bestanden

Databasekopieën, exports, een lokale `.htaccess`-backup en een gegenereerde
connectorscreenshot zijn uit de actuele Git-versie verwijderd. Ze zijn geen
installatiebron en mogen niet opnieuw worden gecommit. `.gitignore` voorkomt
dat nieuwe exemplaren ongemerkt worden toegevoegd.

Een pull kan eerder gevolgde bestanden lokaal verwijderen. Bewaar eigen kopieën
die je nog nodig hebt vooraf buiten je checkout. De actieve productieopslag
moet bij een SFTP-update blijven staan; gebruik geen synchronisatie die alle
bestanden verwijdert die lokaal ontbreken.

Deze opschoning wist geen oude Git-commits. Eerder gecommitteerde gegevens blijven
in de geschiedenis aanwezig; gevoelige sleutels die ooit gepubliceerd zijn,
moeten worden vervangen. Dit is geen herschrijving van de repositorygeschiedenis.
