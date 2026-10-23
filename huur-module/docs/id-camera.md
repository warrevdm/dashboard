# ID fotograferen op de winkeltablet

Bij Nieuwe verhuur en bij Identiteitsdocument in het dossier staat **Foto van ID nemen**. De camera opent in de browser. Dit start niet de aparte Windows Camera-app.

1. Open de HTTPS-website op de Surface en geef de browser cameratoegang.
2. De achtercamera wordt automatisch gekozen. Er is geen camerakeuzelijst. Als de achtercamera niet herkend wordt, stopt de functie met een melding; de voorcamera wordt niet als alternatief getoond.
3. Neem de foto, controleer de leesbaarheid en kies **Deze foto gebruiken**.
4. Vul de bewaardatum in en sla het formulier op om het document te uploaden.

Annuleren behoudt een eerder gekozen bestand. De camerastream stopt na de opname, bij sluiten en bij het verlaten van de pagina. Geen microfoontoegang. De foto gaat via de bestaande beveiligde documentupload; er is geen automatische upload bij het openen van de camera.

Bij blokkering: controleer de cameratoestemming voor deze website in de browser en de Windows-privacyinstellingen voor cameratoegang. Een door de hosting opgelegde `Permissions-Policy: camera=()` moet eveneens worden verwijderd of aangepast voor deze pagina's. Bestand kiezen blijft beschikbaar.

Cameratoegang is in de applicatie beperkt tot reservation.php en reservation-new.php. Getest met een gesimuleerde Chromium-camera; de fysieke Surface-camera moet na upload nog op het toestel gecontroleerd worden.

Technische referentie: https://developer.mozilla.org/en-US/docs/Web/API/MediaDevices/getUserMedia
