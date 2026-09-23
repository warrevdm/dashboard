# Aerts Action Bike — snelstart

Open `/home/` op dezelfde host als de bestaande verhuurmodule en mailingtool.
Apache verwijst `/home` automatisch door naar `/home/` en serveert `index.html`.

## Functie

- Eén compacte snelstart met drie acties:
  - Nieuwe verhuur: `/huur-module/reservation-new.php`.
  - Klant mailen: `/mailing-system/index.php`.
  - Vervangfiets meegeven: `/huur-module/quick-replacement.php`.
- De pagina werkt volledig zonder JavaScript.
- Responsive weergave voor desktop, tablet en mobiel.
- League Spartan en het bestaande Aerts-logo worden lokaal geladen.

Elke tool behoudt de eigen login en toegangsrechten. De snelstart bevat geen centrale
login, sessielogica, databaseverbinding of klantgegevens. Als een tool na inloggen
zijn eigen startpagina opent, blijft dat bestaande gedrag behouden.

## Plaatsen op Combell

1. Upload de volledige map `home/` naar `/www/home/`, inclusief `.htaccess` en `assets/`.
2. Open `https://www.aertsactionbike.cc/home/` en controleer de navigatie.
3. Upload voor de teruglinks en de publieke teamlink ook de gewijzigde bestanden:
   - `/www/index.html`
   - `/www/huur-module/app/views.php`
   - `/www/mailing-system/index.php`
   - `/www/mailing-system/collect-go.php`
   - `/www/mailing-system/communication-dashboard.php`
   - `/www/mailing-system/admin.php`

Staat de vorige dashboardversie al online? Dan volstaat het om `home/index.html`
en `home/assets/dashboard.css` te vervangen. Een eerder geüpload
`home/assets/dashboard.js` wordt niet meer gebruikt en mag worden verwijderd.

Gebruik de host waarop je de tools normaal opent: `www` en het hoofddomein kunnen
verschillende bestaande cookies hebben. De dashboardlinks blijven op dezelfde host.
Laat `.env`, `src/config.php`, databases, uploads en bestaande serverinstellingen
staan. Er is geen database-update of Composer-installatie nodig voor dit dashboard.

`noindex` voorkomt indexering door meewerkende zoekmachines en is geen toegangsbeveiliging.
Deze publieke startpagina toont alleen namen en koppelingen naar de tools.

## Lokaal bekijken

Start vanuit de hoofdmap van deze repository:

```sh
python3 -m http.server 8080 --bind 127.0.0.1
```

Open `http://127.0.0.1:8080/home/`. Gebruik deze server alleen voor een lokale
dashboardpreview; PHP-tools vereisen de bestaande PHP-server. Stel de Python-server
niet publiek open: hij voert PHP niet uit en beschermt geen private projectmappen.

## Onderhoud

De drie acties, beschrijvingen en links staan in `index.html`; de vormgeving staat
in `assets/dashboard.css`. Verhoog bij CSS-wijzigingen de versie in de stylesheetlink
zodat browsers de nieuwe vormgeving ophalen.

Het lettertype valt onder de SIL Open Font License, meegeleverd in `assets/fonts/OFL.txt`.
