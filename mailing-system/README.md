# AAB – Fiets klaar mailer

Interne webapp waarmee een medewerker de klantnaam, het e-mailadres en het fietstype invult. Daarna opent Outlook met ontvanger, onderwerp en mailtekst vooraf ingevuld.

De medewerker controleert de mail en klikt zelf op **Verzenden**. Er worden geen SMTP-, Microsoft Graph- of mailboxgegevens gebruikt.

## Vereisten

- PHP 8.1 of hoger
- Een Microsoft 365-account dat in Outlook Web is aangemeld
- HTTPS op de productieserver

## Installatie

Haal de laatste versie binnen:

```powershell
git pull origin main
```

Kopieer de configuratie indien nodig:

```powershell
Copy-Item src/config.example.php src/config.php
```

Start lokaal:

```powershell
php -S localhost:8000
```

Open daarna:

`http://localhost:8000`

## Werking

1. Vul naam, e-mailadres en fietsmodel in.
2. Voeg eventueel een extra boodschap toe.
3. Controleer het voorbeeld.
4. Klik op **Openen in Outlook**.
5. Outlook Web opent een nieuw concept.
6. Controleer afzender, ontvanger en tekst.
7. Klik handmatig op **Verzenden**.

## Belangrijk

- De Outlook deeplink vult platte tekst in. Volledige HTML-opmaak en een grafische knop worden niet ondersteund in deze conceptflow.
- De Bookings-link staat zichtbaar en klikbaar in de tekst.
- De afzender wordt bepaald door het account waarmee de medewerker in Outlook is aangemeld.
- Er worden geen mails automatisch verstuurd.
- Er zijn geen tenant ID, client ID, client secret of mailboxwachtwoord nodig.

## Bestanden

- `index.php`: formulier en preview
- `send.php`: validatie en Outlook-deeplink
- `src/config.php`: Bookings-link
- `assets/style.css`: huisstijl
- `assets/app.js`: validatie en mailpreview

## Booking-link

`https://outlook.office365.com/book/Verkochtefietsen@aertsactionbike.be/?ismsaljsauthenabled=true`

## Beveiliging

Beveilig de webapp bij voorkeur met Microsoft-login, VPN, serverauthenticatie of een interne personeelsomgeving.
