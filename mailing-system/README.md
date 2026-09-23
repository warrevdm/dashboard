# Aerts Action Bike — interne mailingtool

[Algemene README](../README.md) · [Hosting en updates](../docs/deployment.md)

Medewerkers maken gepersonaliseerde mails voor een fiets die klaarstaat of een
**Collect & Go**-bestelling. De app toont een voorbeeld, controleert mogelijke
dubbele verzendingen en bewaart de mailhistoriek in SQLite.

## Verzenden

| Actie | Werking | Nodig |
| --- | --- | --- |
| Mail direct versturen | Verzendt via Microsoft Graph vanuit de ingestelde mailbox | Geldige Graph-configuratie en toestemming voor die mailbox |
| Outlook .eml maken | Downloadt een mailbestand dat de medewerker in een geschikt mailprogramma opent | Geen Graph-verbinding; de medewerker verstuurt zelf |
| Voorbeeld bekijken | Toont de samengestelde mail zonder te versturen | Alleen het ingevulde formulier |

Directe verzending gebruikt Microsoft Graph. De verhuur- en leasingmodule hebben
hun eigen mailinstellingen.

## Vereisten

- PHP 8.1 of hoger; gebruik voor de volledige repository PHP 8.2 of hoger.
- PHP-extensies `pdo_sqlite`, `curl`, `json` en `mbstring`.
- Schrijfrechten op de private map `data/` en HTTPS in productie.
- Voor Microsoft Graph: een appregistratie, een client secret en de
  `Mail.Send`-application permission met beheerdersgoedkeuring. Configureer
  toegang tot de bedoelde afzendermailbox volgens het Microsoft 365-beleid.

## Eerste installatie

Voer vanuit de repository in PowerShell uit, alleen als de private configuratie
nog niet bestaat:

```powershell
Copy-Item mailing-system/src/config.example.php mailing-system/src/config.php
```

Vul in `src/config.php` de eigen instellingen in:

- `AUTH_SETUP_KEY` en `AUTH_AUDIT_PEPPER`: twee verschillende, willekeurige waarden.
- `AUTH_DB_PATH`: pad naar de private SQLite-database voor accounts en historiek.
- `AUTH_COOKIE_PATH`: het URL-pad waarop de app staat, normaal `/mailing-system/`.
- `AUTH_FORCE_SECURE_COOKIE`: `true` in productie met HTTPS.
- Afzender, antwoordadres, logo en `BOOKING_URL`.
- `MS_TENANT_ID`, `MS_CLIENT_ID` en `MS_CLIENT_SECRET` voor directe verzending.

Genereer iedere geheime waarde afzonderlijk, bijvoorbeeld met:

```sh
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Open daarna `/mailing-system/setup.php` en maak met de setup-sleutel het eerste
beheerdersaccount aan. De database en tabellen worden bij gebruik aangemaakt.
Na het eerste account is de setuppagina niet meer beschikbaar.

Gebruik je alleen `.eml`-bestanden? Zet `INTERNAL_SEND_ENABLED` op `false`.
De Microsoft-gegevens hoeven dan niet voor echte verzending geconfigureerd te zijn.
Bewaar alle private instellingen buiten Git; `src/config.php` is uitgesloten.

## Lokaal starten

Voor een losse preview van alleen deze module:

```sh
php -S 127.0.0.1:8081 -t mailing-system
```

Open [de lokale mailingtool](http://127.0.0.1:8081/). Stel hiervoor alleen in je
lokale configuratie `AUTH_COOKIE_PATH` in op `/` en
`AUTH_FORCE_SECURE_COOKIE` op `false`, omdat deze preview HTTP gebruikt.
Zet `INTERNAL_SEND_ENABLED` op `false` als je geen echte mails wilt versturen.
Upload deze lokale instellingen niet over de productieconfiguratie.

De ingebouwde PHP-server past `.htaccess` niet toe en is alleen voor lokaal
gebruik. Voor samenhangende navigatie met alle tools gebruik je de Apache-opstelling
uit de [algemene README](../README.md).

## Dagelijks gebruik

1. Meld aan met een persoonlijk account.
2. Kies **Nieuwe fiets** of **Collect & Go**.
3. Vul klantnaam, e-mailadres en fiets/product in en voeg eventueel een boodschap toe.
4. Controleer het voorbeeld en een eventuele waarschuwing voor dubbele verzending.
5. Kies directe verzending of download een `.eml` voor handmatige verzending.

Het communicatieoverzicht toont de geregistreerde acties. Beheerders beheren de
accounts en kunnen de historiek bekijken. Een aangemaakt `.eml`-bestand betekent
niet dat de mail al daadwerkelijk verzonden is.

Bij een Graph-fout kan de ingestelde `.eml`-fallback een handmatige verzending
aanbieden. Controleer bij een onzekere aflevering eerst de mailbox voordat je
opnieuw verstuurt.

## Updates en gegevens

Behoud bij iedere update `src/config.php` en de volledige `data/`-map.
Upload de meegeleverde `.htaccess` voor afscherming van configuratie en data.
Een SQLite-back-up moet consistent zijn, inclusief nog niet verwerkte schrijfacties;
zie [hosting en updates](../docs/deployment.md).

De noodreset via `reset-admin.php` is standaard uitgeschakeld. Activeer die alleen
tijdelijk met een aparte resetsleutel in de private configuratie en zet
`AUTH_ADMIN_RESET_ENABLED` daarna terug op `false`.
