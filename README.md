# SitiAI Product Teksten (WordPress plugin)

SitiAI Product Teksten voegt een AI-gestuurde workflow toe aan WooCommerce zodat redacties product-, categorie- en merkteksten rechtstreeks binnen WordPress kunnen genereren. De plugin bundelt alle logica in deze repository (inclusief assets, taalbestanden en Docker-omgeving) en kan daardoor geheel via git beheerd en gedeployed worden.

## Functionaliteiten in vogelvlucht
- **Multi-provider AI**: selecteer Groq, OpenAI of Google Gemini en laad live model-lijsten. `Groq_AI_Model_Exclusions` filtert ongeschikte modellen en elke provider declareert eigen endpoint, API-sleutel en resp. JSON capabilities.
- **WooCommerce productmodal**: op de productbewerkscherm verschijnt de meta-box “Gebruik AI” met een modal waarin gebruikers prompts kunnen sturen, contextvelden (titel, beschrijvingen, attributen, merken, afbeeldingen) kunnen toggelen en resultaten per veld kunnen kopiëren of direct invullen.
- **Categorie- en merkteksten**: uitgebreide beheerschermen voor `product_cat` en gedetecteerde merk-taxonomieën bevatten overzichten met woordtellingen, bulk-acties en een termgenerator die topverkopers, interne links en (optioneel) Google-data toevoegt. Output splitst in bovenste beschrijving, onderste beschrijving en – indien Rank Math actief – SEO-velden.
- **Prompt builder & contextbeheer**: `Groq_AI_Prompt_Builder` bouwt system prompts op basis van winkelcontext, gefixeerde conversation ID’s en geselecteerde contextvelden. Productprompts eisen strikt JSON volgens `get_structured_response_instructions`; termprompts gebruiken desgewenst OpenAI/Groq response_format.
- **Modules**: de Rank Math-module is standaard beschikbaar en bepaalt focuskeywordlimieten plus pixel-limieten voor meta title/description. Modules zijn uitbreidbaar via filters en krijgen een eigen instellingenpagina.
- **Google-integratie**: OAuth 2.0 koppeling met Search Console en GA4 voegt queries, sessies en engaged sessions toe aan termcontext. Tokens worden ververst via `Groq_AI_Google_OAuth_Client` en resultaten gecachet (15 min).
- **Logging & audits**: alle generaties worden opgeslagen in `wp_groq_ai_generation_logs` met prompt, response, status en token usage. Er zijn admin-schermen voor log-overzichten en detailpagina’s.
- **Live updates**: `SitiWebUpdater` controleert GitHub releases (`SitiWeb/siti-ai-product-content-generator`) en verzorgt binnen WordPress één-klik updates inclusief re-activatie.

## Vereisten
- WordPress 6.4+ en WooCommerce (de plugin deactiveert zichzelf zonder WooCommerce).
- PHP 8.0+ (de Dockerfile gebruikt WordPress 6.9 op PHP 8.2).
- Minstens één AI API-sleutel (Groq, OpenAI of Google Gemini). Je kunt sleutels voor meerdere providers opslaan en later wisselen.
- Optioneel: Rank Math SEO (voor extra velden) en Google Cloud-project met Search Console & GA4 toegang voor OAuth.

## Installatie & activatie
### Plugin installeren
1. Download de laatste release (`siti-ai-product-content-generator-x.y.z.zip`) vanuit GitHub Releases of gebruik het zip-bestand dat door de workflow in `dist/` verschijnt.
2. Upload via **Plugins → Nieuwe plugin → Plugin uploaden** of plaats de map onder `wp-content/plugins/`.
3. Activeer **SitiAI Product Teksten** en controleer dat WooCommerce actief is.

### Basisconfiguratie
1. Ga naar **Instellingen → Siti AI**.
2. Kies een provider, stel het standaardmodel in (of laad live modellen via de knop), vul de bijbehorende API-sleutel in en kies optioneel andere aanbieders.
3. Vul winkelcontext, standaardprompt, maximale outputtokens en gewenste contextvelden. Via **Prompt & context** beheer je defaults voor attributen, merkdetectie, beeldcontext (`none`, `url`, `base64`) en het maximale aantal afbeeldingen.
4. Stel modules (Rank Math) in via **Instellingen → Siti AI → Modules** om keywordlimieten/pixellimieten te wijzigen en de module te activeren/deactiveren.
5. (Optioneel) Koppel Google OAuth (client ID/secret + refresh token) en configureer Search Console site + GA4 property. Gebruik de ingebouwde verbindingstest om scopes te bevestigen.
6. Gebruik op het tabblad **Prompt & context** de velden *Term omschrijving lengte* om de gewenste tekentaantallen voor de korte (top) en lange (bottom) categorie-/merktekst vast te leggen. De AI krijgt deze waardes met een marge van ±10%.

### Productteksten genereren
1. Open een WooCommerce-product en klik in de meta-box op **Gebruik AI**.
2. De modal toont de standaardprompt, contextselecties en (indien ingesteld) standaard attributen. Je kunt contextvelden tijdelijk toggelen zonder globale instellingen te wijzigen.
3. Na **Genereer tekst** verschijnt output per veld: titel (inclusief drie suggesties), slug, korte beschrijving, beschrijving en – indien Rank Math module – meta title, meta description en focus keywords. Met de knoppen kun je de inhoud kopiëren of rechtstreeks invoegen in de corresponderende WordPress velden.
4. Iedere call wordt gelogd (status, tokens, provider) en kan via het AI-logboek worden ingezien.
5. Ajax-acties: `groq_ai_generate_text` verwerkt productprompts, `groq_ai_refresh_models` haalt provider-specifieke modellen op.

### Categorie- en merkteksten
- Ga naar **Instellingen → Siti AI → Categorieën** of **Merken** om een overzicht te zien met productcounts en woordtellingen. Lege termen worden gemarkeerd.
- Bovenaan staat een bulk-paneel dat een achtergrondproces start (`groq_ai_bulk_generate_terms`) voor lege termen; optioneel kun je bestaande teksten forceren.
- Klik op een term om naar de generator te gaan. Daar kun je bovenste en onderste beschrijvingen, Rank Math velden en een term-specifieke prompt beheren. De knop **Genereer** roept `groq_ai_generate_term_text` aan, toont ruwe JSON-output en stelt je in staat de velden met één klik te vullen.
- Context bevat: termnaam/slug/productcount, bestaande beschrijvingen (ook custom meta), topverkopende producten (max 25), automatische interne link-suggesties, merkcontext en – indien geactiveerd – Search Console queries en GA4 sessies.
- Via de ingestelde tekentaantallen weet de AI hoeveel inhoud de korte en lange omschrijving ongeveer moeten bevatten; hij stuurt automatisch bij binnen ±10%.

### Modules & integraties
- **Rank Math**: bepaalt of focuskeywords + meta velden worden getoond/bewaard bij zowel producten als termen. Limieten (keywords, pixelbreedtess) worden afgedwongen in de promptinstructies en validatie.
- **Google-data**: caching en foutafhandeling gebeurt binnen de serviceclient. Errors verschijnen als WP notices en in het log. Zorg dat de redirect-URL (`/wp-admin/admin-post.php?action=groq_ai_google_oauth_callback`) in Google Cloud staat.
- **Response-format compatibiliteit**: toggle onder Algemene instellingen om JSON Schema mode te forceren wanneer een provider geen native `response_format` ondersteunt.

### AI-logboek & troubleshooting
- Via **Instellingen → Siti AI → AI-logboek** heb je een WP_List_Table met filters, zoekveld en pagination. Klik op een regel om de detailpagina te zien (prompt, response, tokens, foutmelding, gekoppeld product en gebruiker).
- De `Groq_AI_Generation_Logger` creëert automatisch de DB-tabel bij `plugins_loaded`; bij ontbrekende tabellen kun je `WP_DEBUG` gebruiken om fouten te lezen.
- Alle fouten worden ook verstuurd naar `WC_Logger` (indien aanwezig) met bron `groq-ai-product-text`.

## Hooks & extensies
- `groq_ai_brand_taxonomy` / `groq_ai_brand_taxonomy_candidates`: overschrijf detectie van merk-taxonomie.
- `groq_ai_product_brand_context`: wijzig de tekst die voor merkcontext wordt meegestuurd.
- `groq_ai_term_google_context`: voeg extra analytics of SEO-data toe aan termcontext.
- `groq_ai_model_exclusions`: pas geblokkeerde modellen per provider aan.
- `groq_ai_prompt_default_context_fields`: stel andere standaardcontextvelden in.
- `groq_ai_bulk_term_generation_options`: beïnvloed bulk-run opties (bijv. aantal top-producten).
- Algemene WordPress filters zoals `plugin_action_links` of `admin_menu` kunnen gebruikt worden voor extra UI-knoppen; `Groq_AI_Service_Container` maakt het eenvoudig om services te vervangen of uit te breiden.

## Lokale ontwikkeling
### Voorwaarden
- Docker Desktop of Docker Engine + Docker Compose v2.
- Node of build tooling is niet vereist; alle assets staan reeds gecompileerd in `assets/`.

### Containers starten
```bash
docker compose up --build -d
```
- WordPress: http://localhost:8082
- phpMyAdmin: http://localhost:8085
- MariaDB: poort 3307 (database, user, wachtwoord = `wordpress`)

### WordPress initialiseren
1. Bezoek http://localhost:8082 en volg de standaard WordPress-installatie (gebruik de `db` host en bovengenoemde databasegegevens).
2. Log in, activeer WooCommerce (indien niet automatisch) en activeer daarna **SitiAI Product Teksten**. De plugin is als bind-mount aanwezig onder `wp-content/plugins/siti-ai-product-content-generator`.

### Handige commando’s
- Shell binnen de WordPress-container: `docker compose exec wordpress bash`
- WP-CLI gebruiken: `docker compose exec wordpress wp plugin list`
- Logs volgen: `docker compose logs -f wordpress`
- Containers stoppen: `docker compose down`
- Helemaal opnieuw beginnen (verwijdert volumes): `docker compose down -v`

Alle code staat buiten de container, dus je kunt op de host `git status`, `git commit` etc. draaien. De Dockerfile (WordPress 6.9 / PHP 8.2) installeert hulpmiddelen zoals git, wp-cli en mariadb-client.

## Release & updates
1. Verhoog de `Version` header in `groq-ai-product-text.php` en commit de wijzigingen.
2. Push naar `main` of start handmatig de GitHub Action **Build & Release Plugin**. De workflow creëert een distributie-zip, maakt tag `vX.Y.Z` en publiceert een GitHub Release met asset.
3. Productiesites met de plugin krijgen een update-notificatie via `SitiWebUpdater` en kunnen vanuit het WordPress dashboard upgraden.

## Mappenstructuur
- `groq-ai-product-text.php`: hoofdbestand dat services, providers, admin-schermen en hooks initialiseert.
- `includes/Core`: service container, AJAX-controller en model-exclusiehulpen.
- `includes/Admin`: instellingenpagina’s, meta-box UI, logboek en termoverzichten.
- `includes/Providers`: implementaties voor Groq, OpenAI en Google (Gemini), inclusief live model listing en requestafhandeling.
- `includes/Services`: gedeelde services zoals prompt builder, settings manager, conversatiebeheer, logging en Google-clients.
- `assets/css` & `assets/js`: statische bestanden voor de WordPress admin experience.
- `languages`: `.po/.mo` bestanden voor vertalingen.
- `docker` + `docker-compose.yml`: lokale ontwikkelomgeving.
- `snippets` & `assets/img`: aanvullende hulpmiddelen en marketingmateriaal.

Met deze README heb je een startpunt voor zowel functionele gebruikers (hoe gebruik ik de plugin) als ontwikkelaars (hoe werkt de codebase, hoe ontwikkel ik lokaal, hoe release ik). Vragen of verbeteringen? Open een issue of start een PR.
