# Bouwopdracht Codex cloud — Woningtriage v1

## Opdracht

Bouw een werkende eerste versie in deze repository, met Android in `android/` en Symfony in `backend/`. Lever implementatie, tests, een debug-APK en reproduceerbare startinstructies op. Werk op een featurebranch en lever een reviewbare pull request; merge of deploy niet automatisch.

Lees README.md en alle specificaties in docs/. De aanvullingen in versie 0.2 en onderstaande expliciete gebruikerskeuzes zijn leidend bij tegenstrijdigheden met eerdere voorstellen. Los gewone technische keuzes zelfstandig op en documenteer ze. Stop niet na een plan of scaffold; werk door tot een geteste verticale versie. Benoem externe blokkades eerlijk en bouw de onafhankelijk uitvoerbare delen af.

## Bevestigde v1-scope

- Zelfstandige native Android-app, Kotlin en Jetpack Compose.
- Een natuurlijk gesproken gesprek via **GPT-Live**, Nederlands beginnen en automatisch aansluiten op de taal van de bewoner.
- Gebruik LEDO: Locatie, Element, Defect, Oorzaak. Vraag alleen door op ontbrekende/tegenstrijdige informatie; oorzaak mag onbekend zijn.
- Primair doel: duidelijke Nederlandse werkomschrijving en geverifieerd volledig adres.
- Verzamel postcode, huisnummer en zo nodig toevoeging. Laat de backend het volledige adres opzoeken. Laat de bewoner dat adres expliciet controleren.
- Vat aan het einde het probleem samen, verwerk correcties en laat de app via de backend één definitief meldingsrecord maken met alle tekstuele gespreksdetails.
- Geen reparatieduur tonen, uitvragen, plannen of opslaan in het meldingsrecord. `planning_duration` uit brondata negeren voor het product.
- Competentie-ID mag classificatiemetadata blijven; namen zijn geen blokkade.
- Geen ruwe audio-opnamen, externe werkbonverzending, planning, iOS of fotoanalyse in v1.
- Geen zelfstandige urgentiebepaling claimen: het definitieve spoedbeleid ontbreekt.

## Brondata: beslisboom-prod.json

Dit bestand is in de oorspronkelijke chat aangeleverd, maar nog **niet in deze repository opgenomen**. Zoek bij taakbijlagen of in de cloudwerkomgeving. Als het ontbreekt: bouw importer en kleine duidelijk fictieve testfixture, meld de ontbrekende productiebron expliciet en ga verder met de overige implementatie. Claim dan niet dat de productieboom geïmporteerd is. Gebruik nooit de oude beslisboom.json als gelijkwaardige vervanging.

Verwachte originele bron:
- Naam: `beslisboom-prod.json`
- Grootte: 53.115.659 bytes
- SHA-256: `4ba8f60d9b2072d05ffa03e7796b0be7fefd2b4a7f9a23d3a0565f99af6d901c`
- Root: `{"status":"success","data":{"ledoData":[...]}}`
- Knopen: `label`, `value`, `children`.
- Bladeren: `label`, `value`, `rm_competence_id`, `planning_duration`.
- Vier niveaus: gebouwtype → locatie → element → defect.
- 70 gebouwtypeknopen, 2.105 locatieknopen, 52.700 elementknopen, 538.807 bladeren.
- Alle bladeren hebben een competentie-ID en duur; 21 verschillende competentie-ID's.
- IDs zijn niet globaal uniek over niveaus. Gebruik boomversie + niveau/pad; verander bron-ID's niet.
- Boom is een classificatiecatalogus; er zijn geen expliciete oorzaken-/spoed-/vraagregels.
- Labels kunnen spaties of ongebruikelijke combinaties bevatten. Bewaar origineel en genormaliseerde zoektekst apart; verwijder niet blind bronkeuzes.
- Nederlands classificatielabel behouden ongeacht gesprekstaal.
- Stuur nooit de volledige 53 MB mee in een prompt. Importeer/indexeer server-side en selecteer beperkte relevante kandidaten.
- De JSON mag buiten versiebeheer worden aangeleverd via configureerbaar importpad. Documenteer het importcommando en bronhash.

## Technische uitvoering

1. Controleer actuele officiële GPT-Live-documentatie:
   - https://developers.openai.com/api/docs/guides/live
   - https://developers.openai.com/api/docs/guides/live-conversations
   - https://developers.openai.com/api/docs/guides/live-prompting
   Gebruik geen verzonnen endpoints of oude Realtime-events als GPT-Live-contract. Kies en documenteer de echte handshake en delegatie. Als de voorgestelde eigen API aangepast moet worden, pas Android, backend en docs samen aan.
2. Leg ondersteunde Android/Gradle/Kotlin/Compose- en PHP/Symfony-versies vast. Voeg Gradle wrapper, Composer lock, migraties en CI toe.
3. Maak een duurzame conceptintake en apart definitief report. Gebruik revisies, transacties, idempotentie en een unieke report-per-intake-constraint.
4. Bouw classificatie-import, zoekservice en gevalideerde LEDO-updates. Een laat resultaat mag een correctie niet overschrijven.
5. Bouw adreslookup achter een providerinterface. Kies een aantoonbaar passende Nederlandse adresdienst op basis van actuele officiële documentatie. Bij ontbrekende credentials: expliciete configuratie en testfake; nooit fictieve adressen als echte resultaten tonen. Behandel nul/meerdere matches, toevoegingen, storing en opnieuw verifiëren.
6. Bouw Android-schermen: starten, gesprek/transcript, LEDO, adrescontrole, eindcontrole en opgeslagen melding. Voeg tekstinvoer toe als toegankelijk alternatief. Bij stoppen/achtergrond geen actieve microfoon. Houd rotatie, audiofocus en netwerkherstel correct.
7. Bouw GPT-Live-adapter en backendverwerking. Beperk prompt van gesprekslaag; laat backend besluiten over dossier en volgende vraag. Houd API-sleutels op server. Gebruik een geschikte langlopende worker voor de providerverbinding, niet een kort PHP-FPM-request.
8. Samenvatting bevat het probleem in gesprekstaal en Nederlandse werkomschrijving. Een expliciete contextgebonden gesproken bevestiging of UI-bevestiging kan afronden; een willekeurig “ja” niet.
9. Report bevat geverifieerde adressnapshot, werkomschrijving, LEDO, classificatiepad + bronversie, onbekenden/vermoedens, berichten met spreker/taal/volgorde/tijd, correcties en verificatiebewijs. Geen interne modelredeneringen. Markeer ontbrekend/voorlopig transcript eerlijk.
10. Bied lokale ontwikkelconfiguratie, .env.example zonder geheimen en start-/test-/importinstructies. Bescherm echte endpoints met geverifieerde identiteit en dossier-eigendom. Geen gedeeld geheim in de APK.

## Verificatie en oplevering

Test minstens:
- LEDO over meerdere velden in één antwoord; onbekende oorzaak.
- Correctie tijdens lopende analyse; verouderde samenvatting.
- Adreslookup met nul/één/meerdere resultaten, toevoeging en providerstoring.
- Wijziging van adres trekt verificatie in.
- Definitief report bevat gespreksdetails en geen planning_duration.
- Gelijktijdige/dubbele afronding of verloren response geeft één report.
- Andere gebruiker heeft geen toegang tot dossier/events.
- Microfoonweigering, mute, stop, rotatie en herstel.
- Nederlandse opening, Engelse taalwisseling en Nederlands leenwoord “okay”.
- Geen technische diagnose of werkelijke noodoverdracht verzinnen.

Voer backendtests, Android unit-tests/lint en debugbuild uit. Gebruik testfakes voor CI; voer live providerproeven uitsluitend met beschikbare geautoriseerde credentials uit. Credentials nooit in output of git. Zonder accounttoegang meld expliciet dat GPT-Live/adresprovider niet end-to-end is bewezen; een fake geldt niet als live succes.

Lever:
- Featurebranch en reviewbare PR.
- Debug-APK als CI-artifact of beschikbaar bestand, indien build uitvoerbaar.
- README met opstarten backend/app, importer en vereiste configuratie.
- Uitgevoerde tests met resultaten en concrete resterende blokkades.
- Geen publicatie of productie-deployment.

## Status van deze opdracht

Dit bestand bereidt de cloudtaak voor; het aanmaken van dit document start geen agent. De gebruiker start de taak in Codex cloud en kiest deze repository/environment.
