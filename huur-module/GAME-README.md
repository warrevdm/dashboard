# After Hours — Warre × Berten

Een verborgen dagelijkse minigame binnen de verhuurmodule. Er is geen nieuwe
navigatielink en er worden geen spelbestanden op de planning geladen.

## Installeren

Upload samen, met behoud van de mappenstructuur:

```text
huur-module/game.php
huur-module/app/secret_game.php
huur-module/public/game.php
huur-module/public/assets/secret-game.js
huur-module/public/assets/secret-game.css
```

Meld je met je bestaande beheerdersaccount aan bij de verhuurmodule. Open daarna
`/huur-module/game.php` en kies eenmalig de bestaande accounts van Warre en Berten.
Alleen actieve admin- of staffaccounts kunnen gekozen worden, en het moeten twee
verschillende accounts zijn. Na de koppeling zijn uitsluitend die twee accounts
toegelaten, ook andere beheerders krijgen dan geen toegang. Maak eventueel een
persoonlijke bladwijzer naar de pagina. Het spel vraagt geen extra wachtwoord.

De koppeling is bewust niet via de spelinterface wijzigbaar. Een aanpassing aan
de koppeling vereist onderhoud van de private spelopslag door de sitebeheerder.
Kies dus de juiste accounts voordat je het formulier opslaat.

Bij een installatie met `public/` als webroot is de route `game.php` binnen die
webroot. De bestaande blokkering van `app/`, `storage/` en `tests/` blijft nodig.

## Dagelijkse competitie

- Middernacht in **Europe/Brussels** bepaalt de dag, ook bij zomer- en wintertijd.
- De volgorde herhaalt zich: **Galgje → Snake → Tetris → Woordzoeker**.
- Beide spelers krijgen dezelfde dagelijkse puzzel, voedselreeks of blokkenreeks.
- Elke speler krijgt **drie pogingen per dag**. Starten telt als poging, ook bij
  herladen, afsluiten of een verbroken verbinding. Er is geen hervatting na reload.
- Er kan per speler maar één onafgeronde poging tegelijk lopen. Een verlaten
  poging verloopt na maximaal 150 seconden; daarna kan je de volgende starten.
- Een ronde duurt maximaal **120 seconden**; Snake en Tetris maximaal **90 seconden**.
- **Stop & bewaar**, een ander tabblad openen of het einde van het spel bewaart
  de score. Bij sluiten/verbindingsverlies is verzending niet gegarandeerd.
  Bij een tijdelijke opslagfout blijft op de open pagina **Opnieuw opslaan**
  beschikbaar. Verzending moet binnen 150 seconden na starten aankomen.
- De hoogste score telt; bij gelijke punten wint de kortste servergemeten rondetijd.
  Bij exact gelijke punten én tijd is het gelijkspel. Netwerkvertraging kan de
  gemeten tijd beïnvloeden: dit is een vriendschappelijke competitie.
- Het klassement telt **dagzeges over de vorige 30 dagen**. Vandaag wordt pas na
  middernacht meegeteld. Als slechts één speler een score heeft bewaard, wint die
  de dag. De laatste zeven gespeelde afgelopen dagen zijn zichtbaar.
- **Scores vernieuwen** haalt het actuele klassement op. Er draait geen permanente
  polling, achtergrondtaak, cronjob of e-mailverzending.

## Spellen en punten

| Spel | Bediening | Punten |
|---|---|---|
| Galgje | Klik/type letters, maximaal 6 fouten | Opgelost: 1.000 min 100 per fout; anders 0 |
| Snake | Pijltjestoetsen of schermknoppen | 10 per hapje |
| Tetris | Links/rechts, boven voor draaien, spatie/beneden voor laten vallen | 1/2/3/4 rijen tegelijk: 100/300/500/800 |
| Woordzoeker | Klik begin- en eindletter; ook achterstevoren | 100 per gevonden woord, maximaal 800 |

Tetris is een compacte uitvoering: bewegingen worden per halve seconde verwerkt,
maximaal vier commando's per stap, zonder wall kicks of bewaren van een blok.
Woordzoeker gebruikt twaalf bij twaalf vakjes met acht fietswoorden.

## Opslag, veiligheid en beperkingen

Spelgegevens staan in `storage/private/secret-game.sqlite`, gescheiden van de
verhuurrecords. Die map moet schrijfbaar zijn. Neem dit bestand mee in de normale
consistente back-up van de private opslag. **Upload nooit een lokale kopie over de
online spel- of verhuurdatabase.** Er is geen wijziging aan het verhuurschema nodig.

De huidige gebruikersstatus wordt bij ieder verzoek opnieuw uit de verhuurdatabase
gecontroleerd. POST-acties vereisen CSRF. Pogingen zijn gekoppeld aan account en dag;
transactions bewaken de limieten bij gelijktijdige verzoeken. Herhaald bewaren is
idempotent. Antwoorden zijn privé en worden niet gecachet.

De server berekent scores zelf. Snake en Tetris worden uit een begrensd zetverloop
nagespeeld, woordselecties worden gecontroleerd en Galgje bewaart geraden letters
op de server. Het Galgje-antwoord wordt pas aan het einde getoond. Dit voorkomt
willekeurig ingestuurde punten, maar is **geen volwaardige anti-cheat**: bots en
het analyseren van zichtbare puzzels/reeksen vallen buiten de bescherming.

Er is geen effect op OPcache-instellingen, verhuurprijzen, reservaties of mails.
De geautomatiseerde tests staan in `tests/secret-game.cjs`; die map hoeft niet naar
hosting. Een echte browsercontrole op de hosting blijft nodig na upload: lokale
bestanden konden in de beschikbare cloudbrowser niet geopend worden.
