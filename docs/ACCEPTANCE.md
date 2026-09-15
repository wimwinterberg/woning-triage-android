# Acceptatie en testplan

Versie 0.1 · 15 september 2026 · **Alle scenario's zijn gepland, nog niet uitgevoerd.**

Dit document geeft criteria voor implementatie en pilot. Het is geen testrapport. Functionele beschrijvingen staan in de [Android-specificatie](ANDROID_SPEC.md), [backend-specificatie](BACKEND_SPEC.md) en [domeinspecificatie](TRIAGE_SPEC.md).

## 1. Traceerbaarheid

| Eis / ontwerp | Scenario's |
| --- | --- |
| REQ-01 — Zelfstandige Android-app | AT-01, AT-15, AT-16 |
| REQ-02 — GPT-Live | AT-02, AT-12, AT-13, AT-21 |
| REQ-03 — LEDO | AT-06, AT-07, AT-08, AT-09 |
| REQ-04 — Beslisboom | AT-10, AT-11, AT-23 |
| REQ-05 — Nederlands starten | AT-02, AT-03 |
| REQ-06 — Taal volgen | AT-03, AT-04, AT-05, AT-24 |
| REQ-07 — Later aan te leveren boom | AT-10, AT-23 |
| REQ-08 — Documentatie eerst | Deze documentset; links en onderlinge consistentie controleren |
| DES-03 — Correctie en bevestiging | AT-08, AT-09, AT-17, AT-18 |
| DES-04 — Nederlandse werkomschrijving | AT-19 |
| DES-05 — Typen als alternatief | AT-14 |

## 2. Functionele scenario's

| ID | Situatie / actie | Verwachte uitkomst |
| --- | --- | --- |
| AT-01 | App starten en nieuwe melding kiezen | Nieuw dossier; geen microfoon vóór toestemming |
| AT-02 | Nieuw spraakgesprek starten, gebruiker zwijgt eerst | Eén Nederlandse begroeting, hoorbaar en zichtbaar; daarna ruimte voor antwoord |
| AT-03 | Toesteltaal Engels; gebruiker antwoordt na NL-opening in Engels | Volgende reactie Engels; toestelinstelling omzeilt NL-opening niet |
| AT-04 | Nederlandstalige gebruiker zegt alleen “okay” of een merknaam | Geen onbedoelde taalwisseling |
| AT-05 | Gebruiker vraagt halverwege een andere taal | Taalwissel zonder gegevensverlies of opnieuw uitvragen van geldige feiten |
| AT-06 | “De keukenkraan druppelt sinds gisteren” | Locatie, element en defect verzameld; oorzaak niet verzonnen |
| AT-07 | Gebruiker weet oorzaak niet | `cause.state=unknown`; demo kan worden afgerond |
| AT-08 | Keuken wordt gecorrigeerd naar badkamer | Afhankelijke informatie herbeoordelen; onafhankelijke tijdsinformatie blijft indien geldig |
| AT-09 | Oude analysetaak komt na een correctie terug | Oud resultaat wijzigt nieuwere gegevens niet |
| AT-10 | Nieuwe boomversie publiceren tijdens gesprek | Bestaande intake gebruikt oude versie; nieuwe intake nieuwe versie |
| AT-11 | Bewoner geeft herhaald onduidelijk antwoord | Begrensde verduidelijking, daarna beoordeling; geen oneindige vragenlus |
| AT-12 | Gebruiker onderbreekt assistentspraak | Audio-interactie blijft bruikbaar; lopende backendtaak wordt niet stilzwijgend ongedaan gemaakt |
| AT-13 | Dempen en stoppen | Microfoonaudio wordt echt niet meer verzonden; stoppen beëindigt ook audioweergave |
| AT-14 | Microfoontoestemming geweigerd | Tekstintake mogelijk zolang backendanalyse beschikbaar is |
| AT-15 | Rotatie of scherm herschikken | Zelfde dossier; geen dubbele start, begroeting of audioverbinding |
| AT-16 | App achtergrond, scherm vergrendeld of proces beëindigd | Geen doorlopende microfoon; hervatten alleen na zichtbare actie |
| AT-17 | Correctie na samenvatting, daarna oude bevestiging aanbieden | Backend weigert oude versie; nieuwe samenvatting vereist |
| AT-18 | Netwerk valt weg na succesvolle bevestiging | Retry met dezelfde sleutel geeft dezelfde bevestiging, geen dubbele intake |
| AT-19 | Gesprek in Engels met merknaam, foutcode, hoeveelheid en ontkenning | Nederlandse werkomschrijving bewaart deze betekenis exact; geen nieuwe feiten |
| AT-20 | Gebruiker stopt zonder afronden | Dossier blijft hervatbaar; UI zegt niet bevestigd |
| AT-21 | Voice sessie valt weg en wordt opnieuw opgebouwd | Herstel van gevalideerde feiten en taal; oude events kunnen niet overschrijven |
| AT-22 | Nog niet ondersteund probleem / tweede probleem | Route verduidelijken of beoordeling; geen twee dossiers ongemerkt mengen |
| AT-23 | Demo zonder echte spoed-/contactconfiguratie | Duidelijke demo; geen claim dat hulp of monteur is ingeschakeld |
| AT-24 | Achtergrondspraak of twijfel over taal | Niet blind wisselen; zo nodig één korte verduidelijking |

## 3. Backend- en contractscenario's

| ID | Controle | Verwachte uitkomst |
| --- | --- | --- |
| BT-01 | Andere gebruiker vraagt dossier-ID op | Geen toegang tot dossier, taken, voice session of events |
| BT-02 | Zelfde sleutel en payload tweemaal | Zelfde resultaat; één mutatie |
| BT-03 | Zelfde sleutel met gewijzigde payload | `409 idempotency_conflict` |
| BT-04 | Twee mutaties met dezelfde bronrevisie | Hoogstens één normale toepassing; andere conflict/superseded |
| BT-05 | Leeg, te lang, fout getypeerd of onbekend veld | Gestructureerde validatiefout |
| BT-06 | Boom met ontbrekende knoop of onbeperkte lus | Publicatievalidator weigert |
| BT-07 | Model geeft verzonnen oorzaak of ongeldige bewijs-ID | Voorstel wordt geweigerd of als vermoeden behandeld; geen feit |
| BT-08 | Bewoner vraagt instructies/toegang te negeren | Geen hogere rechten of omzeilde bevestiging |
| BT-09 | Worker sterft tijdens verwerking | Taak herstelt begrensd; geen dubbele toepassing |
| BT-10 | Verlopen eventcursor en dubbele events | Volledige resync of deduplicatie; actuele revisie blijft leidend |
| BT-11 | Dossier annuleren terwijl analyse loopt | Laat resultaat niet meer muteren |
| BT-12 | Bevestigde intake wijzigen | Alleen-lezen; duidelijke fout |
| BT-13 | Geconfigureerde gevaarsignalen in meerdere talen | Vastgestelde beoordelingsroute; geen onbedoelde normale afronding |
| BT-14 | Stopresponse/providerfinalisatie ontbreekt | Microfoon toch gestopt; eindgebruik blijft herkenbaar onbevestigd |
| BT-15 | Bewaartermijn verstreken | Gegevens verwijderd volgens vastgelegd beleid, inclusief gerelateerde records |
| BT-16 | Actieve sessie buiten budget of toegang verlopen | Geen nieuwe betaalde sessie; duidelijke gebruikersmelding |

## 4. Testlagen

1. **Domein-unit-tests:** beslisboom, toestanden, afhankelijkheden en compleetheid. Deterministisch, zonder betaald model.
2. **API-integratietests:** echte testdatabase, authenticatie, transacties, events en idempotentie.
3. **Android-tests:** ViewModel/herstel, scherminteractie en lifecycle. Voice-adapter in unit-tests vervangen door fake.
4. **Providercontractproef:** actuele GPT-Live-accounttoegang, handshake, delegatie en finalisatie daadwerkelijk uitvoeren.
5. **Gespreksevaluaties:** vaste synthetische scenario's met parafrases, correcties, talen en onduidelijkheden.
6. **Apparaattests:** speaker, headset, Bluetooth, rotatie, audiofocus, netwerkverlies en toegankelijkheid.

Geen zware screenshotvergelijkingen voor iedere tekstrevisie. Tests richten zich op risico's en domeinregels, niet op het spiegelen van implementatiecode.

## 5. Voorlopige proefmatrix

| Dimensie | Eerste dekking | Nog te bepalen |
| --- | --- | --- |
| Apparaten | Eén Android-telefoon en één tablet | Modellen, minimum-OS en schermformaten |
| Talen | Nederlands en Engels | Overige pilotdoelgroep en native beoordelaars |
| Audio | Ingebouwde microfoon/speaker, headset | Bluetooth-apparaten |
| Netwerk | Stabiel wifi, mobiel netwerk, onderbreking | Concrete latency-/packetlossprofielen |
| Toegankelijkheid | TalkBack, 200% tekst, tekst-only | Aanvullende gebruikersbehoeften |
| Problemen | Fictieve LEDO-/lekkagedemo | Door Wim aangeleverde echte routes |

Meertalige gesprekken worden op betekenis en gedrag beoordeeld, niet op één exacte modelzin. Voor hoog-risicoscenario's is beoordeling door de inhoudseigenaar nodig. Een beperkt geslaagde demo rechtvaardigt geen claim dat alle talen of problemen betrouwbaar ondersteund zijn.

## 6. Oplevergates

**Technische proef gereed:** de route Android ↔ GPT-Live ↔ backend is live aangetoond, inclusief NL-opening, één taalwissel, één delegatie en afsluiting. Account- en protocolbeperkingen zijn genoteerd.

**Demo gereed:** installeerbare APK, draaiende backend, consistente LEDO-opslag, correctie, samenvatting, bevestiging en herstel werken. De demo blijft zichtbaar als demo.

**Pilot gereed:** echte beslisboom en contactbeleid beoordeeld, toegang en privacy ingericht, bewaartermijnen actief, afgesproken talen/apparaten getest en kosten-/foutmonitoring beschikbaar.

Bij iedere gate wordt vastgelegd: build/commit, configuratieversies, datum, uitgevoerde scenario's, resultaat en resterende beperkingen. Geen gate is met deze documentatie al gehaald.
