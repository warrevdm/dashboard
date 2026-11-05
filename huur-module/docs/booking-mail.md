# Optionele afspraakmail na het boeken

Na een nieuwe boeking met type **Huur** of **Test** verschijnt een keuzevenster.
Dit geldt voor Nieuwe verhuur (ook voor Boekhouding) en Snelle vervangfiets.
Bij type **Vervang** blijft de bestaande route behouden en verschijnt geen mailkeuze.
De keuze kijkt naar het reservatietype, niet naar het gebruikstype van de fiets.

- **Mail met agenda versturen** verstuurt nu één bevestigingsmail naar de klant.
- **Overslaan · geen mail**, het kruisje, Escape of klikken buiten het venster
  gaat verder zonder een verzending te starten.
- De boeking is al opgeslagen voordat het venster verschijnt. Een mailfout of
  overslaan annuleert de boeking niet.
- Het e-mailadres is vooraf ingevuld. Bij een snelle registratie kan het hier
  worden toegevoegd. Een geldig adres wordt uitsluitend bij expliciet versturen
  in het klantdossier opgeslagen.
- Hierna vervolgt de bestaande route: Huur vanuit Nieuwe verhuur naar het
  contract (beheerder/medewerker), andere gevallen naar het dossier. Boekhouding
  krijgt geen nieuwe toegang tot contracten.

## Inhoud en agenda

De mail bevat naam, fiets(en), afhaal- en retourmoment en het winkeladres.
Er worden geen ID-documenten, contracten of interne dossierlinks meegestuurd.
`booking-<dossiernummer>.ics` bevat de volledige boekingsperiode en een
agendamelding één uur voor de start. De klant moet de bijlage toevoegen aan de
eigen agenda; de agenda-app bepaalt of de melding wordt overgenomen.

Er wordt geen latere automatische herinneringsmail of cronjob aangemaakt.
De agendabijlage is een persoonlijk agenda-item (`METHOD:PUBLISH`), geen RSVP
of koppeling met Microsoft Bookings. Uren worden vanuit Europe/Brussels naar UTC
omgezet, ook bij zomer- en wintertijd. Wijzigingen of annuleringen achteraf
versturen geen automatische agenda-update.

## Mailinstellingen en hosting

Gebruikt de bestaande mailinstellingen van de verhuurmodule (`MAIL_TRANSPORT`
met `smtp`, `graph`, `mail` of `log`). Er zijn geen nieuwe wachtwoorden, sleutels
of croninstellingen nodig. Voor SMTP blijft PHPMailer vereist.

`MAIL_TRANSPORT=log` bewaart een privé `.eml` onder `storage/private/mail` en
toont expliciet dat niets naar de klant is verstuurd. Dit is geen mailtest bij
de provider. Voor echte verzending is het bestaande SMTP-, Graph- of mailtransport
nodig.

De tabel `reservation_booking_mail_runs` wordt bij de eerste expliciete
verzendpoging automatisch toegevoegd aan de bestaande SQLite-database. Ook het
schema voor nieuwe installaties bevat deze tabel. **Geen databasebestand
overschrijven en geen handmatige SQL uitvoeren.**

## Beveiliging en dubbele verzending

Alleen de actieve medewerker/beheerder/boekhoudgebruiker die de boeking heeft
aangemaakt kan de bijbehorende sessiekeuze gebruiken. POST vereist CSRF,
dossiernummer en een willekeurige sessiegebonden eenmalige keuze. De keuze
vervalt na 24 uur; rechtstreeks een dossiernummer openen verstuurt niets.

Voor versturen worden actuele rechten, status, type, klantgegevens, fiets(en)
en periode opnieuw gecontroleerd. Een gewijzigd, geannuleerd, teruggebracht of
als no-show afgesloten dossier krijgt geen mail via een oud venster.

Een blijvende unieke registratie per dossier voorkomt dubbele verzending bij
dubbelklikken, herladen of een herhaalde POST. De database-transactie eindigt
voordat de provider wordt benaderd. Een onbevestigde verzending wordt niet
automatisch opnieuw uitgevoerd: controleer eerst de mailbox/provider. Bij
overslaan na zo'n poging wordt alleen de mailstap afgesloten; een mogelijk al
aanvaarde mail wordt niet ingetrokken.

## Controle

```sh
node --experimental-wasm-jspi huur-module/tests/booking-mail.cjs
node --experimental-wasm-jspi huur-module/tests/reservation-create.cjs
```

De tests gebruiken fictieve klanten, tijdelijke SQLite-data en een lokaal
logtransport of gesimuleerde verzender. Ze versturen geen echte klantmails.
