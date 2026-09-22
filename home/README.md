# Aerts Action Bike — centraal dashboard

Open `/home/` op dezelfde host als de bestaande verhuurmodule en mailingtool.
Apache verwijst `/home` automatisch door naar `/home/` en serveert `index.html`.

## Functie

- Centrale startpagina met negen bestaande tools en drie snelacties.
- Categorieën, zoeken met meerdere woorden en zoeken via de `/`-toets.
- Favorieten per browser, alleen toolcodes in `localStorage`.
- Alle toolkoppelingen werken ook wanneer JavaScript uitstaat.
- Datum en begroeting volgen `Europe/Brussels`.
- Responsive weergave voor desktop, tablet en mobiel.
- League Spartan en het bestaande Aerts-logo worden lokaal geladen.

De pagina bevat geen centrale login, sessielogica, databaseverbinding, klantgegevens
of veronderstelde livecijfers. Elke tool blijft de eigen authenticatie en rechten
controleren. De links naar het kasboek, gebruikersbeheer en snelle vervangfietsen
geven geen extra rechten. Als een tool na inloggen zijn eigen startpagina opent,
blijft dat bestaande gedrag behouden.

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

Toolnamen, beschrijvingen, categorieën en links staan in `index.html`. Houd de
`data-tool`-codes stabiel voor opgeslagen favorieten. Werk de totaalweergave in de
navigatie en de statische resultaatsteller bij wanneer je tools toevoegt.
Favorieten worden niet met accounts of andere apparaten gesynchroniseerd.

Het lettertype valt onder de SIL Open Font License, meegeleverd in `assets/fonts/OFL.txt`.
