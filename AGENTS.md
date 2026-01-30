# AGENTS – Repo handleiding

## Context & scope
- Deze repository bevat de volledige WordPress-plugin **SitiAI Product Teksten** inclusief assets, taalbestanden en Docker-configs. Alle pluginbestanden leven hier (geen submodules).
- Doel: AI-gestuurde content genereren voor WooCommerce-producten en -termen met ondersteuning voor Groq, OpenAI en Google Gemini plus Rank Math & Google integraties.
- Belangrijkste entrypoints: `groq-ai-product-text.php` (bootstrap), `includes/` (services, admin UI, providers) en `assets/` (admin CSS/JS).

## Lokale ontwikkelworkflow
1. Vereisten: Docker Desktop/Engine met Compose v2. Verdere tooling (npm, composer) is niet nodig; assets staan reeds gecompileerd.
2. Start omgeving:
   ```bash
   docker compose up --build -d
   ```
   - WordPress: http://localhost:8082
   - phpMyAdmin: http://localhost:8085
   - MariaDB poort 3307 (db/user/pass = `wordpress`)
3. WordPress installatie afronden via de browser, WooCommerce + deze plugin activeren.
4. Handige commando’s:
   - `docker compose exec wordpress bash`
   - `docker compose exec wordpress wp plugin list`
   - `docker compose logs -f wordpress`
   - Stoppen/herinitialiseren: `docker compose down` / `docker compose down -v`
5. Code staat buiten containers (bind mount). Gebruik git op de host (`git status`, `git commit`, …).

## Code style & patronen
- Hanteer WordPress/PHP Coding Standards: tabs voor indent, `esc_html__`, `esc_attr__`, `wp_nonce_field`, etc. Tekstdomein = `siti-ai-product-content-generator`.
- Alle adminstrings moeten vertaalbaar zijn via `__()`/`_e()`.
- Integreer met bestaande services via `Groq_AI_Service_Container`; voeg nieuwe services via `$container->set()` in `groq-ai-product-text.php`.
- Houd prompts/JSON-structuren consistent met `Groq_AI_Prompt_Builder`. Als je outputfields toevoegt, zorg ook voor updates in `get_structured_response_instructions()` en de UI (`assets/js/admin.js`).
- AJAX-acties zitten in `Groq_AI_Ajax_Controller`; vervolgacties moeten capability checks, nonce-validatie en wp_send_json_* gebruiken.
- Voor settings gebruik je altijd `Groq_AI_Settings_Manager` zodat defaults, sanitization en modules consistent blijven.
- Houd rekening met de Rank Math module (optioneel) en Google OAuth flows (Search Console / GA clients). Voeg configuratie-opties toe via de bestaande adminpagina’s en filters.

## Testen & QA
- Geen geautomatiseerde test-suite beschikbaar. Valideer wijzigingen door de Docker-WordPress te gebruiken.
- PHP-lint: `docker compose exec wordpress php -l /var/www/html/wp-content/plugins/siti-ai-product-content-generator/<bestand>`.
- Controleer AI-flows handmatig: productmodal, categorie/merk generator, bulk acties en AI-logboek.
- Controleer database-migraties: logtabel `wp_groq_ai_generation_logs` wordt bij init aangemaakt. Gebruik WP-CLI of phpMyAdmin om schemawijzigingen te verifiëren.
- Houd WooCommerce actief; de plugin deactiveert zichzelf als WooCommerce ontbreekt.

## Release & versiebeheer
1. Pas `Version` (en eventueel `Stable tag`) aan in `groq-ai-product-text.php`.
2. Commit veranderingen en push naar `main` of start handmatig de workflow **Build & Release Plugin** (GitHub Actions).
3. Workflow bouwt zip, maakt tag `vX.Y.Z` en publiceert een release. Live sites krijgen updates via `SitiWebUpdater`.
4. Bewaak backwards compatibility; logs en prompts worden opgeslagen in WordPress options/meta.

## Overige tips & valkuilen
- `rg` is momenteel niet geïnstalleerd in deze omgeving; gebruik `grep`/`fd` voor zoekopdrachten.
- Geef altijd capability-checks en nonce-validatie wanneer je nieuwe admin-acties toevoegt.
- Filters ter beschikking: `groq_ai_brand_taxonomy`, `groq_ai_model_exclusions`, `groq_ai_term_google_context`, enz. Gebruik die in plaats van hardcodings.
- Afbeeldingscontext kan `url`, `base64` of `none` zijn. Nieuwe providers moeten dit ondersteunen of duidelijk aangeven dat het unsupported is.
- Denk aan caching (transients) zoals `Groq_AI_Google_Context_Builder` doet; intensieve API-calls moeten nooit in loops zonder caching draaien.
- Logging (`Groq_AI_Generation_Logger`) is essentieel voor support. Als je nieuwe AI-calls toevoegt, log status, tokens en fouten daar.
