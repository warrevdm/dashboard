# AAB eID Bridge — DIGIPASS 905

[Algemene README](../../README.md) · [Verhuurmodule](../README.md)

## Doel

De verhuurmodule draait op Combell en kan daarom niet rechtstreeks de USB-kaartlezer op een winkel-pc aanspreken. De lokale **AAB eID Bridge** draait uitsluitend op Windows en luistert alleen op `127.0.0.1:17895`.

De browserflow is:

1. medewerker opent **Nieuwe verhuur**;
2. klant steekt de Belgische eID in de OneSpan DIGIPASS 905;
3. medewerker klikt **eID uitlezen**;
4. de browser vraagt de lokale bridge om de kaart uit te lezen;
5. de bridge gebruikt de lokaal geïnstalleerde officiële Belgische eID Viewer-backend;
6. alleen naam, adres en einddatum van de kaartgeldigheid worden naar het formulier teruggestuurd.

De bridge verwerkt bewust **geen rijksregisternummer, foto, chipnummer, geboortedatum, geslacht of nationaliteit**.

## Vereisten op de winkel-pc

- Windows 10/11 x64;
- OneSpan DIGIPASS 905 aangesloten via USB;
- de reader werkt in Windows;
- officiële Belgische **eID Middleware** geïnstalleerd;
- officiële Belgische **eID Viewer** geïnstalleerd;
- voor bouwen vanuit broncode: .NET 8 SDK.

De bridge zoekt standaard naar:

```text
C:\Program Files\Belgium Identity Card\EidViewer\eIDViewerBackend.dll
```

Een afwijkend pad kan worden ingesteld met de omgevingsvariabele `AAB_EID_BACKEND_DLL`.

## Installeren vanuit de repository

Open PowerShell in de hoofdmap van deze dashboardrepository. Stel voor de
Aerts-host eerst de toegestane origins in zoals hieronder beschreven en voer uit:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\huur-module\tools\eid-bridge\install.ps1
```

Het script:

- controleert of de Belgische eID Viewer-backend aanwezig is;
- bouwt een self-contained Windows x86 executable voor de eID-backend;
- installeert die onder `%LOCALAPPDATA%\AertsActionBike\EidBridge`;
- maakt standaard een opstartsnelkoppeling;
- start de bridge geminimaliseerd;
- voert een lokale health-check uit.

Zonder automatische start:

```powershell
.\huur-module\tools\eid-bridge\install.ps1 -NoStartup
```

## Bridge testen

```powershell
Invoke-RestMethod http://127.0.0.1:17895/v1/health
```

Verwacht ongeveer:

```text
ok            : True
service       : AAB eID Bridge
backendLoaded : True
cardPresent   : False
readers       : {DIGIPASS ...}
```

Steek daarna een eID in de reader:

```powershell
Invoke-RestMethod 'http://127.0.0.1:17895/v1/read?timeout=12000'
```

## Browser

De bridge accepteert alleen ingestelde origins en bindt aan `127.0.0.1`.
De standaardlijst bevat nog de oorspronkelijke ontwikkelhost; configureer voor
deze installatie expliciet de gebruikte Aerts-hostnaam.

Moderne browsers kunnen bij de eerste toegang tot een lokale/loopbackdienst een toestemming voor lokaal netwerk of loopback tonen. Kies **Toestaan** op de vertrouwde Aerts Action Bike winkel-pc.

## Extra toegestane origins

Standaard:

```text
https://warrevandermaat.be
https://www.warrevandermaat.be
http://localhost:8080
http://127.0.0.1:8080
http://localhost:8000
http://127.0.0.1:8000
```

Stel voor Aerts Action Bike vóór installatie of herstart de origins in. De
gebruikersvariabele blijft beschikbaar voor de opstartsnelkoppeling; de tweede
regel stelt dezelfde waarde in voor de huidige PowerShell-sessie:

```powershell
[Environment]::SetEnvironmentVariable('AAB_EID_ALLOWED_ORIGINS', 'https://aertsactionbike.cc;https://www.aertsactionbike.cc', 'User')
$env:AAB_EID_ALLOWED_ORIGINS='https://aertsactionbike.cc;https://www.aertsactionbike.cc'
```

Deze instelling vervangt de volledige standaardlijst. Voeg lokale origins
alleen toe als die nodig zijn en herstart een al draaiende bridge.

## Andere poort

Standaardpoort: `17895`.

```powershell
$env:AAB_EID_BRIDGE_PORT='17895'
```

Wanneer de poort wordt gewijzigd, moet ook `public/assets/eid-bridge.js` en de `connect-src` CSP in `app/bootstrap.php` worden aangepast.

## Probleemoplossing

### Geen kaartlezer gevonden

1. controleer USB;
2. open de officiële Belgische eID Viewer;
3. controleer of de DIGIPASS 905 daar zichtbaar is;
4. sluit de Viewer indien hij de reader exclusief vasthoudt;
5. herstart `AAB-eID-Bridge.exe`.

### Bridge niet bereikbaar vanuit Chrome/Edge

1. controleer `http://127.0.0.1:17895/v1/health` in PowerShell;
2. herlaad de verhuurpagina;
3. sta lokale/loopback-netwerktoegang toe wanneer de browser dit vraagt;
4. controleer dat de exacte hostnaam van de verhuurpagina in `AAB_EID_ALLOWED_ORIGINS` staat.

### Backend DLL niet gevonden

Installeer of herstel de officiële Belgische eID Viewer. De AAB bridge redistribueert de overheids-DLL niet zelf.

## Privacy

De bridge logt geen inhoud van identiteitsvelden naar de console en geeft alleen de gegevens terug die de verhuurflow nodig heeft. Het huidige doel is **sneller invullen**, niet het opslaan van een digitale kopie van de eID.
