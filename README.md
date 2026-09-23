# Aerts Action Bike — intern dashboard

De interne werkomgeving van **Aerts Action Bike**: een compacte snelstart met
tools voor fietsverhuur, klantcommunicatie en leaseopvolging.

**Startpagina:** [aertsactionbike.cc/home/](https://aertsactionbike.cc/home/)

De snelstart heeft geen centrale login. Iedere tool beheert zijn eigen aanmelding,
sessie en toegangsrechten. Deze repository bevat de broncode en
voorbeeldconfiguratie; installatiegegevens en bedrijfsdata blijven op de server.

## Onderdelen

| Map | Functie | Webadres op dezelfde host | Handleiding |
| --- | --- | --- | --- |
| `home/` | Snelstart naar de interne tools | `/home/` | [Snelstart](home/README.md) |
| `huur-module/` | Verhuurplanning, vervangfietsen, betalingen, fietsbeheer en contracten | `/huur-module/` | [Verhuur](huur-module/README.md) |
| `mailing-system/` | Fiets klaar / Collect & Go, mailvoorbeelden, verzending en historiek | `/mailing-system/` | [Mailing](mailing-system/README.md) |
| `lease/` | Contractimport, dossieropvolging, logboek en interne dinsdagmail | `/lease/public/` | [Leasing](lease/README.md) |

`index.html` in de hoofdmap verwijst bezoekers naar de officiële webshop en biedt
medewerkers een link naar de snelstart. De modules zijn zelfstandige PHP-apps;
er is geen centrale frontendbuild of gedeelde database.

## Vereisten

- **PHP 8.2 of hoger (64-bit)** voor het volledige project, met Composer 2.
- **MySQL/MariaDB** voor leasing; **PDO SQLite** voor verhuur en mailing.
- De PHP-extensies uit de Composer-afhankelijkheden, plus `pdo_mysql` en
  `pdo_sqlite` voor de gebruikte databases. Composer controleert de overige
  vereisten, zoals `curl`, `dom`, `fileinfo`, `mbstring`, `gd`, XML en `zip`.
- **Apache 2.4** met ondersteuning voor de meegeleverde `.htaccess`-regels,
  of een serverconfiguratie met gelijkwaardige routering en afscherming.
- **HTTPS** en schrijfbare opslagmappen op de productieomgeving.
- **Node.js 24** alleen voor de leasingtests. Browserconnectors en de optionele
  [Windows eID-bridge](huur-module/docs/eid-bridge.md) hebben eigen afhankelijkheden.

## Repository lokaal ophalen

```sh
git clone https://github.com/warrevdm/dashboard.git
cd dashboard
git branch --show-current
```

Een clone begint op `main`. Deze handleiding hoort bij de branch die je bekijkt:
wijzigingen uit een open pull request staan niet automatisch op `main`.
Voor de huidige beveiligings- en dinsdagmailupdates:

```sh
git fetch origin
git switch codex/lease-weekly-digest
```

Installeer daarna de afhankelijkheden vanuit de hoofdmap:

```sh
composer install --working-dir=huur-module
composer install --working-dir=mailing-system
composer install --working-dir=lease
```

## Configuratie en eerste installatie

| Onderdeel | Lokaal aanmaken | Voorbeeld / opdracht |
| --- | --- | --- |
| Verhuur | `huur-module/.env` | Kopie van [`.env.example`](huur-module/.env.example) |
| Mailing | `mailing-system/src/config.php` | Kopie van [`config.example.php`](mailing-system/src/config.example.php) |
| Lease-database | `lease/config/config.local.php` en een aparte productieconfiguratie | Kopie van [`config.example.php`](lease/config/config.example.php) |
| Lease-toegang | `lease/config/auth.local.php` | `php lease/scripts/configure-auth.php` |
| Lease-dinsdagmail | `lease/config/weekly-mail.local.php` | Kopie van [`weekly-mail.example.php`](lease/config/weekly-mail.example.php) |

Maak deze bestanden alleen aan als ze nog ontbreken. Bewaar bestaande
productie-instellingen; vul lokaal testgegevens en eigen wachtwoorden in.
De gegenereerde lease-toegangssleutel verschijnt één keer in de terminal.

Voltooi vervolgens de installatie volgens de handleiding van de module:

1. **Verhuur:** stel de lokale `.env` en een eigen beheerderwachtwoord in;
   voer `php huur-module/bin/setup.php` uit om de SQLite-database aan te maken.
2. **Mailing:** stel de private configuratie in en maak via `setup.php` het
   eerste beheerdersaccount aan. Directe verzending gebruikt Microsoft Graph;
   een `.eml` downloaden kan zonder Graph-verbinding.
3. **Leasing:** maak een lege lokale database aan, importeer het
   [databaseschema](lease/database/schema.sql) en configureer de aanmelding.
   Gebruik het schema niet als update-instructie voor een bestaande productieomgeving.

Gebruik een lokale Apache-site met de repository als documentroot om de
dashboardlinks en alle modules samen te testen. De links verwachten `/home/`,
`/huur-module/`, `/mailing-system/` en `/lease/` direct onder dezelfde host.
De lease-configuratieloader herkent momenteel alleen `localhost` en `127.0.0.1`
zonder afwijkend poortnummer als lokale omgeving; zie de leasehandleiding.

Alleen de snelstart bekijken kan zonder PHP:

```sh
python -m http.server 8080 --bind 127.0.0.1
```

Open dan [de lokale snelstart](http://127.0.0.1:8080/home/). Deze previewserver
voert de PHP-tools niet uit en is uitsluitend voor lokaal gebruik.

## Bestaande installatie bijwerken

Controleer vanuit de repository eerst je branch en lokale wijzigingen:

```sh
git status
git branch --show-current
git pull --ff-only
```

Bij lokale wijzigingen: bewaar en beoordeel die eerst; gebruik geen geforceerde
reset om een pull te laten slagen. Installeer gewijzigde Composer-afhankelijkheden
met `composer install`, zodat de vastgelegde versies uit het lockbestand worden gebruikt.

Een Git-pull werkt alleen je lokale checkout bij. Voor de live website upload je
de gewijzigde bestanden afzonderlijk naar je hosting.

**Welke bestanden moeten online, en welke moeten blijven staan?**
Zie [de hosting- en updatehandleiding](docs/deployment.md).

## Dinsdagmail

Leasing kan iedere **dinsdag vanaf 09:00 Belgische tijd** een intern overzicht
sturen naar `info@aertsactionbike.be` en `marketing@aertsactionbike.be`.
Het gaat om niet-gearchiveerde contracten die binnen drie kalendermaanden aflopen
en nog geen logboekactie hebben.

- Automatisch: configureer SMTP, zet `enabled` op `true` in de private
  mailconfiguratie en laat een PHP CLI-taak ieder uur draaien.
- Het script controleert zelf dinsdag, het uur en eerdere verzendingen.
- Handmatig: **Dinsdagmail → Nu versturen** werkt ook als de automatische planning uitstaat.
- De statusmap moet bij updates behouden blijven. Er worden geen klantmails
  verstuurd en er worden geen logboekacties aangemaakt.

Zie [installatie en werking van de dinsdagmail](lease/README.md#wekelijkse-dinsdagmail)
voor de cronopdracht, SMTP-instellingen, controle en herstel.

## Ontwikkelen en controleren

Wijzig één module tegelijk en behoud de bestaande toegangscontrole. Gebruik
voorbeelddata voor lokale tests. Private configuratie, databases, imports,
foto's, gegenereerde contracten en browserprofielen horen niet in commits.

De leasingtests draaien met fictieve gegevens en een geïsoleerde PHP-runtime:

```sh
npm --prefix lease/tests ci
npm --prefix lease/tests test
git diff --check
```

Er worden daarbij geen echte mails verzonden. Deze tests dekken de leasebeveiliging
en de dinsdagmail; ze vervangen geen functionele controle van verhuur en mailing.

## Documentatie

- [Hosting, updates en te bewaren bestanden](docs/deployment.md)
- [Snelstart aanpassen](home/README.md)
- [Verhuur installeren en gebruiken](huur-module/README.md)
- [Mailing instellen en gebruiken](mailing-system/README.md)
- [Leasing, toegang en dinsdagmail](lease/README.md)
- [Optionele eID-bridge](huur-module/docs/eid-bridge.md)
