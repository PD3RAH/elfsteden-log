# Elfsteden-log

Logprogramma voor de **42e Friese Elfsteden Contest** (zondag 22 november 2026, 11.00–14.00 uur lokale tijd).

Gemaakt door Richard Hendriksen, PD3RAH – [www.pd3rah.nl](https://www.pd3rah.nl)

## Downloaden

- **Windows:** download `Elfsteden-log-Setup-2.3.0.exe` onder [Releases](../../releases).
- **Zonder installatie:** download `elfsteden-log.html` en `handleiding.html` en open het programma in Chrome, Edge of Firefox.

Lees de [handleiding](Handleiding-Elfsteden-log.pdf) voor uitleg.

## Functies

- Apart log per band (80 m en 2 m)
- Automatische puntentelling: eigen regio 1, buiten eigen regio 5, buitenland 2
- Herkenning van de 12 multipliers (elf steden + Bartlehiem), ook in het Fries
- Waarschuwing bij dubbele verbindingen
- Export: afdrukken/PDF met scoreberekening, Cabrillo en CSV
- Fail-safe opslag: elke QSO direct op schijf, reservekopieën en herstelpunten

## Zelf bouwen (alleen voor ontwikkelaars)

> Wil je het programma gewoon gebruiken? Dan heb je dit niet nodig: download het installatiebestand onder [Releases](../../releases).

Voor wie het installatiebestand zelf uit de broncode wil maken (vereist [Node.js](https://nodejs.org)):

```
npm install
npm run dist
```

Het installatiebestand verschijnt in de map `dist`.

## Auteursrecht en disclaimer

© 2026 Richard Hendriksen, PD3RAH. Alle rechten voorbehouden.
Gebruik op eigen risico en verantwoordelijkheid. Zie [LICENSE.txt](LICENSE.txt) voor de volledige disclaimer en gebruiksvoorwaarden.
