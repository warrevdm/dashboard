# Ondertekenen op de winkeltablet

Vaste snelkoppeling: `https://aertsactionbike.cc/huur-module/contracts.php`.
Meld de tablet aan met een actief medewerker- of beheerderaccount. De bestaande
sessieduur blijft gelden; dit is geen permanente automatische login. Boekhouding
krijgt geen toegang tot deze handeling. Er is geen nieuwe database-instelling nodig.

## Werkflow

1. Maak een reservatie en de huurovereenkomst op in het verhuurdossier.
2. Open **Ondertekenen** in het menu, of de vaste snelkoppeling op de tablet.
3. Zoek op klantnaam, fietsnaam, fietscode of contractnummer.
4. Kies **Laat klant ondertekenen**. De klant leest het contract, vult de naam in,
   tekent en bevestigt het akkoord. Een aparte klantlogin of gedeelde link is niet nodig.
5. De bevestiging toont dat de ondertekening opgeslagen is en meldt de mailstatus.
6. Via **Medewerker: terug naar contractoverzicht** opent de medewerker het overzicht.
   Het getekende contract staat onder **Ondertekend** en is alleen te bekijken.

Het overzicht bevat opgemaakte contracten. Een reservatie zonder opgemaakte
huurovereenkomst verschijnt nog niet. Geannuleerde, ongetekende contracten worden
niet aangeboden. Getekende contracten blijven zichtbaar. Zoekresultaten hebben
paginering van 30 contracten. De handtekening, contractkopie en mail volgen de
bestaande opslag- en verzendprocedure.

Het klantenscherm heeft geen algemeen beheermenu, maar de tablet blijft intern
aangemeld: de terugknop is geen pincodebeveiliging. Medewerkers begeleiden de
ondertekening en nemen de tablet daarna terug.

## Upload naar hosting

- `huur-module/contracts.php`
- `huur-module/public/contracts.php`
- `huur-module/public/sign.php`
- `huur-module/public/assets/tablet-contracts.css`
- `huur-module/app/tablet_contracts.php`
- `huur-module/app/contracts_v2.php`
- `huur-module/app/views.php`

Upload de bestanden samen. Geen `.env`, database of storage overschrijven.
