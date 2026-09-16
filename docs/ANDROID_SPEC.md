# Specificatie Android-app

Versie 0.2 · 15 september 2026 · Status: voorgesteld ontwerp; geen implementatie.

Gerelateerd: [projectoverzicht](PROJECT_OVERVIEW.md), [LEDO en beslisboom](TRIAGE_SPEC.md), [API-contract](API_CONTRACT.md), [testplan](ACCEPTANCE.md).

## 1. Doel en technische basis

De Android-app biedt de bewoner een rustige manier om een probleem in huis te melden via spraak of tekst. De app toont begrijpelijke voortgang, maakt corrigeren eenvoudig en houdt microfoongebruik zichtbaar.

Voorgestelde basis: Kotlin, Jetpack Compose, Material 3, ViewModel, coroutines en StateFlow. De exacte stabiele dependencyversies, compile-/target-SDK en WebRTC-library worden bij de technische proef vastgesteld en daarna vastgelegd in een version catalog en buildconfiguratie. Voorstel minimum: Android 10; nog te bevestigen.

De app is zelfstandig. Er is geen afhankelijkheid van Keyplan, Teamwissels of een bestaande mobiele app. Een login- of activatiescherm is afhankelijk van OPEN-03; onderstaande schermen veronderstellen geldige backendtoegang.

## 2. Navigatie en schermen

### A-01 — Start

Toon de werknaam, een korte uitleg van de intake en de primaire knop **Probleem melden**. Leg uit dat de bewoner met een AI-assistent spreekt en dat antwoorden worden verwerkt om een melding op te stellen. Verwijs naar de nog vast te stellen privacytekst.

Secundair: **Liever typen**. Als een lokaal bekende intake nog open staat, toon **Intake hervatten** en **Nieuwe intake**. De eerste versie heeft geen uitgebreid dossierarchief.

Een nieuwe intake krijgt een nieuw dossier. Hervatten haalt eerst de actuele servertoestand op. Geen achtergrondactivatie van de microfoon.

### A-02 — Microfoontoegang en verbinden

Vraag microfoontoestemming pas nadat de bewoner voor spraak kiest. Bij weigeren blijven tekstinvoer en teruggaan beschikbaar. Bij permanent weigeren kan de gebruiker de Android-instellingen openen.

Toon tijdens starten **Verbinding maken…** met annuleren. De startknop is tijdens één lopende startactie geblokkeerd. Meld verbindingfouten in gewone taal, met opnieuw proberen of typen.

Start de openingszin pas wanneer audio werkelijk gereed is. Voorkom twee begroetingen door een retry. Een nieuw gesprek begint Nederlands; een herstelsessie voor hetzelfde gesprek behoudt de bevestigde gesprekstaal.

### A-03 — Gesprek

Onderdelen in leesvolgorde:

1. Titel en verbindingsstatus.
2. Actuele vraag of korte reactie van de assistent.
3. Scrollbaar transcript met herkenbare sprekers.
4. Inklapbaar blok **Wat we al weten** met vier LEDO-velden.
5. Tekstinvoer en verzenden.
6. Microfoon dempen/hervatten en **Gesprek stoppen**.

Voorbeelden voor zichtbare toestanden: **Verbonden**, **Microfoon uit**, **Gegevens verwerken**, **Verbinding verbroken**. Omdat luisteren en spreken tegelijk kunnen plaatsvinden, zijn microfoonstatus, audioweergave en backendverwerking afzonderlijke toestanden.

Toon per LEDO-veld een korte waarde met **Nog niet bekend**, **Opgegeven** of **Controleren**. In vermoedens staat expliciet **Mogelijke oorzaak**. Gebruik geen schijnnauwkeurige percentages voor zekerheid of intakevoortgang.

Een actief gesprek kan meteen worden gestopt; er is geen bevestigingsdialoog die eerst de microfoon actief laat. Stoppen beëindigt opname en audio onmiddellijk en pauzeert de intake. Definitief verwijderen is een aparte actie.

### A-04 — Gegeven corrigeren

De gebruiker kan een veld aantikken of in het gesprek corrigeren. Een tekstcorrectie toont de huidige waarde en een invoerveld. Voor oorzaak is **Ik weet het niet** beschikbaar; wissen betekent opnieuw uitvragen, niet hetzelfde als een onbekende oorzaak.

Na verzenden toont de app de servergevalideerde waarde. Een backendconflict toont de actuele gegevens en laat de gebruiker opnieuw beoordelen. De app overschrijft een nieuwere waarde niet automatisch.

Als een wijziging gevolgen heeft voor andere velden, toon kort: **Door deze wijziging controleren we enkele gegevens opnieuw.** De backend bepaalt welke waarden opnieuw beoordeeld worden.

### A-05 — Samenvatting controleren

Toon:

- Het probleem in de gesprekstaal.
- Locatie, element, defect en oorzaak, inclusief onbekende gegevens.
- Eventuele markering dat menselijke beoordeling nodig is.
- De Nederlandse omschrijving onder **Omschrijving voor de medewerker**, wanneer de gesprekstaal anders is.
- **Aanpassen** en **Bevestigen**.

Een samenvatting is gebonden aan een revisie. Bij een nieuw antwoord of correctie vervalt de eerdere bevestigingsmogelijkheid totdat de nieuwe samenvatting is geladen.

Voorgesteld gedrag: een expliciete bevestiging van de actuele samenvatting kan gesproken of via de knop plaatsvinden. Bij een gesproken bevestiging koppelt de app die aan de actuele summary-ID en revisie; een los “ja” buiten die context is onvoldoende. De app laat daarna via de backend het definitieve meldingsrecord aanmaken. TalkBack moet de knop volledig bruikbaar maken.

### A-06 — Afgerond

Toon **Melding opgeslagen**, het door de backend geretourneerde meldingsnummer, geverifieerd adres en korte samenvatting. Beloof geen reparatie, terugbelmoment of verstuurde werkbon zonder daadwerkelijke backendbevestiging van die actie. In deze scope wordt het definitieve meldingsrecord opgeslagen; externe verzending of planning is niet voorzien.

Acties: **Nieuwe melding** en terug naar het startscherm. Microfoon, audio en liveverbinding zijn gesloten. Een bevestigde intake is in deze eerste versie alleen-lezen.

### A-07 — Menselijke beoordeling / buiten bereik

Als de beslisboom niet verder kan of een gevaarsignaal optreedt, toon de door de backend aangeleverde tekst. Stop de gewone vraagroute waar vereist. Alleen geconfigureerde contactopties mogen zichtbaar worden; de gebruiker start een oproep zelf.

Zonder contactconfiguratie meldt de demo dat geen medewerker is ingeschakeld. Een demo mag niet als operationele spoedvoorziening worden gepresenteerd.

## 3. Gebruikersinterface en toegankelijkheid

- Rustige vormgeving, korte zinnen en duidelijke tekstlabels naast iconen.
- Geen onderscheid uitsluitend met kleur; primaire bediening minimaal 48 dp groot.
- Layout met scrolling bij klein scherm, toetsenbord en 200% tekstgrootte.
- Bij tabletbreedte mag het LEDO-overzicht naast het transcript staan.
- Portret als uitgangspunt; rotatie behoudt de intake en start geen tweede sessie.
- Ondersteun TalkBack, focusvolgorde, donkere modus en contrastcontrole.
- Respecteer systeeminsets en zorg dat het toetsenbord de verzendknop niet bedekt.
- Bij lange assistenttekst blijft de microfoon-/stopbediening bereikbaar.
- Tekstvelden ondersteunen Unicode en rechts-naar-links lopende inhoud; volledige RTL-interface pas claimen na testen.
- Stel schermlabels via Android-resources beschikbaar; niet door het model laten genereren.

## 4. Taalgedrag

Gesprekstaal en interface-taal zijn afzonderlijk. Het model mag vrij spreken in de gedetecteerde taal, maar de app bevat alleen expliciet vertaalde schermlabels. Voorstel: Nederlandse en Engelse interface in de eerste versie; bij andere gesprekstalen blijven labels in de gekozen ondersteunde interface-taal.

Bewaar `conversation_language` als servergegeven. De app kan de herkende taal tonen en een handmatige taalkeuze aanbieden. De standaardstand is automatisch. Handmatige keuze houdt stand totdat de gebruiker weer automatisch kiest of expliciet een andere taal vraagt.

Bij een nieuw dossier begint de gesproken begroeting Nederlands, ook als het toestel Engels staat. Als de gebruiker al vóór de begroeting zelf begint te spreken, mag diens spraak niet worden afgekapt om de Nederlandse begroeting alsnog af te spelen.

Een los “okay”, merknaam, accent, televisie of medespreker mag geen automatische taalwisseling veroorzaken. Onzekerheid leidt tot één korte taalvraag. Zie de volledige regels in [TRIAGE_SPEC](TRIAGE_SPEC.md).

## 5. Audio en levenscyclus

Voorstel: WebRTC voor audio, onderhandeld via de backend. Android-specifieke echo-onderdrukking, audiofocus, Bluetooth-gedrag en onderbrekingen worden in de proef gevalideerd. Er wordt geen API-key in de app geplaatst.

Het product moet onderbreken tijdens assistentspraak ondersteunen. Een onderbreking van audio betekent niet automatisch dat een backendactie is geannuleerd. De app verzoekt annulering apart en toont de uiteindelijk bevestigde servertoestand.

Microfoon dempen stopt verzending van microfoonaudio daadwerkelijk. Het mute-icoon alleen wijzigen is onvoldoende. Bij stoppen, verlies van toegang, app op achtergrond, schermvergrendeling of audiofocusverlies stopt de eerste versie de opname en wordt de live sessie afgesloten of gepauzeerd volgens het transportcontract. Hervatten vereist een zichtbare gebruikersactie.

Een rotatie is geen vrijwillig achtergrondgesprek en mag niet tot verlies of duplicatie van de sessie leiden. Gebruik een lifecyclebewuste sessiehouder buiten de composable. Na process death start geen automatische microfoonopname; laad het dossier opnieuw en vraag om hervatten.

Geen always-on luisteren, audio-opnamebestand of foreground service voor achtergrondgesprekken in versie 1.

## 6. Toestandmodel

| Dimensie | Toestanden | Betekenis |
| --- | --- | --- |
| Intake | `collecting`, `review_required`, `ready_for_confirmation`, `confirmed`, `cancelled` | Alleen backend bepaalt duurzame status |
| Verbinding | `disconnected`, `connecting`, `connected`, `reconnecting`, `closing`, `failed` | Geen koppeling met dossierafronding |
| Microfoon | `unavailable`, `muted`, `active` | Feitelijke opname-/verzendtoestand |
| Audio-uitvoer | `idle`, `playing`, `interrupted` | Niet afleiden uit transcript alleen |
| Verwerking | `idle`, `pending`, `failed` | Lopende backendaanvraag |

Een gepauzeerd gesprek blijft bijvoorbeeld een intake met `collecting` en verbinding `disconnected`. Verbindingsverlies maakt de intake niet automatisch `cancelled`.

## 7. Code-indeling

| Laag / module | Verantwoordelijkheid |
| --- | --- |
| `ui/start`, `ui/conversation`, `ui/review` | Composables en presentatietoestand |
| `domain` | Schermonafhankelijke use-cases en immutable modellen |
| `data/api` | Eigen backend-API, foutmapping, eventstream |
| `data/session` | Actuele dossierkopie, revisies en herstel |
| `voice` | WebRTC-adapter, audiofocus en sessielevenscyclus |

Compose bevat geen providerprotocol of beslisboomregels. ViewModels sturen use-cases aan. De voice-adapter kan in tests door een fake worden vervangen. De precieze Gradle-modulegrenzen mogen klein beginnen; bovenstaande scheiding is logisch, niet verplicht zes losse modules.

## 8. Lokale opslag en netwerk

- Bewaar alleen voorkeuren, actieve intake-ID en benodigde toegangsmiddelen lokaal; toegangsmiddelen via een platformgeschikte beveiligde opslag.
- Bewaar transcript en LEDO-gegevens standaard in geheugen; herstel via de backend.
- Geen toegangstokens, ruwe audio of gesprekstekst in logcat, analytics of crashmeldingen.
- Alle productiecommunicatie gebruikt TLS. Lokale HTTP-uitzonderingen zijn uitsluitend debugconfiguratie.
- Iedere wijziging gebruikt `expected_revision` en een unieke idempotentiesleutel.
- Een nieuwe stream haalt gemiste events op of laadt een volledig dossier; geen aanname dat een socket alle events heeft ontvangen.
- Offline tekst kan zichtbaar als concept blijven, maar wordt niet als opgeslagen getoond. Geen onzichtbare latere automatische verzending.
- Bij herstart blijft alleen gegarandeerd behouden wat de server bevestigd heeft.

## 9. Fouten en herstel

| Situatie | Gedrag |
| --- | --- |
| Microfoon geweigerd | Bied typen; geen herhaalde toestemmingslus |
| Geen internet | Meld dit; behoud schermconcept; bied handmatige retry |
| Sessiestart mislukt | Stop audioresources; bied retry of tekst |
| GPT-Live niet beschikbaar | Tekst alleen als backendanalyse werkt; anders eerlijke foutmelding |
| Oud resultaat na correctie | Backend verwerpt; app neemt geen oudere revisie over |
| Bevestigingsantwoord verloren | Lees status of herhaal met dezelfde sleutel; geen tweede dossier |
| Toegang verlopen | Stop microfoon; herauthenticeer zonder gegevens aan ander account te tonen |
| Backendlimiet bereikt | Toon hervatmogelijkheid indien toegestaan; geen eindeloze retries |
| Servervalidatie weigert invoer | Geef bruikbare melding zonder ruwe stacktrace |

## 10. Build, distributie en acceptatie

Bij implementatie: Gradle wrapper, vastgelegde dependencyversies, debug/release-varianten, gescheiden backend-URLs en CI voor unit-tests, lint en APK-build. Ondertekeningssleutels worden buiten de repository beheerd. Distributiekanaal is nog open.

Android is gereed voor de pilot als de scenario's A-01 t/m A-07, NL-opening, taalwisseling, microfoonweigering, mute, achtergrondgedrag, rotatie, onderbreken, bevestigen en netwerkherstel aantoonbaar werken. Minimaal één echte telefoon en één tablet worden gebruikt; het definitieve apparaatprofiel is OPEN-08.

Zie [ACCEPTANCE.md](ACCEPTANCE.md) voor de controleerbare scenario's en nog niet uitgevoerde tests.

## 11. Adres verzamelen en verifiëren

Voeg vóór de eindcontrole een adresstap toe, via gesprek, GPS of invoervelden. Verzamel postcode en huisnummer, of vraag de GPS-locatie (`Gebruik mijn locatie`). Vraag een toevoeging alleen waar nodig. Ondersteun dat een bewoner adresgegevens al tijdens de probleembeschrijving noemt. De backend zoekt op; het model verzint geen straat of woonplaats.

Toon het volledige gevonden adres en laat de agent dit in de gesprekstaal ter controle voorleggen. Een expliciete gesproken bevestiging of knop bevestigt precies de getoonde kandidaat. Bij meerdere adressen (GPS in de buurt of meerdere units) toont de app de lijst en laat de bewoner kiezen; nooit automatisch het eerste resultaat. Bij nul resultaten corrigeert de bewoner de invoer of probeert GPS opnieuw. Bij storing blijft de intake bewaard, maar wordt geen geverifieerd adres gesuggereerd.

Een wijziging van postcode, huisnummer, toevoeging of kandidaat trekt de eerdere adresverificatie en samenvatting in. Eindcontrole toont probleem én adres. Als alles is gecontroleerd, roept de app de afrondingsroute aan. Bij timeout controleert zij de status en herhaalt zo nodig met dezelfde idempotentiesleutel. Nooit een succesmelding uitsluitend op basis van uitgesproken modeltekst.

Geen verwachte reparatieduur op enig scherm. De definitieve melding bevat gespreksdetails; informeer de bewoner daarover in de start-/privacyuitleg. Verifieer adres niet opnieuw bij een ongerelateerde probleemcorrectie zolang de adresversie gelijk blijft.
