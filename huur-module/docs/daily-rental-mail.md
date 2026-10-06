# Dagmail: verhuren onderweg

Elke dag vanaf 17:00 Europe/Brussels (ook weekends) naar werkplaats@aertsactionbike.be en marketing@aertsactionbike.be. Dezelfde selectie als de planning: status picked_up, alle types. Eén kaart per dossier met alle fietsen, klantnaam, periode, te-laat-markering en beveiligde dossierlink. Ook een leeg overzicht wordt verzonden.

## Installatie

Upload app/daily_rental_mail.php en bin/send-daily-rentals.php. Voeg aan de bestaande private huur-module/.env toe:

```
DAILY_RENTAL_MAIL_ENABLED=1
```

Gebruikt de mailinstellingen van de **huurmodule**, niet automatisch die van lease. MAIL_TRANSPORT moet smtp of graph zijn. Voor SMTP: bestaande MAIL_HOST, MAIL_PORT, MAIL_ENCRYPTION (tls/ssl), MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS en MAIL_FROM_NAME; composer/vendor met PHPMailer moet aanwezig zijn. Graph gebruikt GRAPH_TENANT_ID, GRAPH_CLIENT_ID, GRAPH_CLIENT_SECRET en GRAPH_FROM_ADDRESS. Bewaar secrets uitsluitend op de hosting, niet in GitHub. Log/testmodus en PHP mail() worden niet gebruikt voor deze verzending.

Maak in de hosting één cronjob, elke vijf minuten:

```
*/5 * * * * /usr/bin/php /ABSOLUUT/PAD/huur-module/bin/send-daily-rentals.php
```

Vervang de paden door het PHP CLI-pad en projectpad van je hosting. De code controleert zelf de Belgische tijd (inclusief zomer-/wintertijd). Daardoor is de servertijdzone van cron niet van belang. Eerste uitvoering vanaf 17:00 verstuurt; bij tijdelijke uitval later dezelfde dag inhaalverzending. Geen verzending vóór 17:00 en geen oude dagen inhalen. Configureer cron-foutmeldingen bij de hosting.

## Controleren zonder verzending

```
php /ABSOLUUT/PAD/huur-module/bin/send-daily-rentals.php --preview
```

Geeft HTML op stdout, geen e-mail en geen verzendregistratie. Bevat klantnamen: sla alleen privé op. Na activatie kan het normale commando vanaf 17:00 echt verzenden; geen browser-endpoint.

## Dubbele verzending en fouten

SQLite-tabel daily_rental_mail_runs bewaart een unieke dag/ontvanger-claim vóór verzending. Herhaalde/gelijktijdige cronjobs sturen niet opnieuw naar dezelfde ontvanger. Bij een fout/time-out blijft status uncertain (of sending bij een afgebroken proces): de provider kan het bericht al hebben geaccepteerd. De andere ontvanger wordt nog geprobeerd. De opdracht geeft exitcode 1 zolang er een onzekere verzending voor vandaag bestaat. Inspecteer providerlogs; pas na bevestiging van niet-verzenden mag een beheerder de betreffende dag/ontvanger-record verwijderen om opnieuw te proberen. Verwijder nooit sent-records om duplicaten te voorkomen. Provideracceptatie garandeert geen inboxaflevering.
