# SitiAI Product Teksten (WordPress plugin)

Deze repository bevat de WordPress plugin waarmee productteksten via SitiAI kunnen worden gegenereerd. De plugincode leeft volledig in deze map en kan daarom veilig via git beheerd worden.

## Plugin installeren en gebruiken

### Systeemeisen

- WordPress 6.4 of hoger.
- WooCommerce (de plugin controleert dit en deactiveert zichzelf als WooCommerce ontbreekt).
- Minimaal één API-sleutel voor Groq, OpenAI of Google Gemini.
- (Optioneel) Rank Math SEO wanneer je de extra SEO-velden wilt gebruiken.

### Installatie

1. Download de nieuwste release (`siti-ai-product-content-generator-x.y.z.zip`) vanaf de [GitHub Releases](https://github.com/SitiWeb/siti-ai-product-content-generator/releases) of gebruik het zip-bestand dat door de workflow in `dist/` wordt geplaatst.
2. Ga in WordPress naar **Plugins → Nieuwe plugin → Plugin uploaden** en upload het zipbestand. Je kunt de map ook handmatig naar `wp-content/plugins/` uploaden.
3. Activeer **SitiAI Product Teksten** en controleer dat WooCommerce actief is.

### Configuratie

1. Navigeer naar **Instellingen → Siti AI**.
2. Kies een AI-aanbieder, vul de bijbehorende API-sleutel in en (optioneel) klik op **Live modellen ophalen** om beschikbare modellen te laden.
3. Stel een standaard prompt en winkelcontext in zodat het AI-venster vooraf gevuld is.
4. Selecteer welke productvelden standaard als context dienen (titel, beschrijvingen, attributen, …).
5. Gebruik de knop **Ga naar modules** om bijvoorbeeld de Rank Math integratie aan of uit te zetten en de limieten aan te passen.
6. Via **Bekijk AI-logboek** zie je alle eerdere generaties inclusief foutmeldingen of token usage.

### Productteksten genereren

1. Open een product in WooCommerce en gebruik de meta-box **Gebruik AI** om de modal te openen.
2. Vul (of hergebruik) een prompt, kies welke contextvelden meegestuurd worden en klik op **Genereer tekst**.
3. De resultaten verschijnen per veld (titel, korte beschrijving, beschrijving en – indien geactiveerd – Rank Math velden). Gebruik **Kopieer** of **Vul … in** om velden direct over te nemen.
4. Via de geavanceerde sectie kun je contextvelden tijdelijk uitschakelen; dit heeft alleen effect voor de huidige generatie.
5. Iedere generatie wordt opgeslagen in het AI-logboek zodat je binnen WordPress kunt terugzoeken wat er is gebeurd.

## Ontwikkelvereisten

- Docker Desktop of Docker Engine + Docker Compose v2

## Ontwikkelen in de Docker omgeving

1. Start de containers (WordPress + MariaDB + phpMyAdmin):
   ```bash
   docker compose up --build -d
   ```
2. Open http://localhost:8080 om de WordPress installatie te doorlopen. Gebruik `db` als host en de volgende databasegegevens:
   - database: `wordpress`
   - gebruiker: `wordpress`
   - wachtwoord: `wordpress`
3. Activeer in het WordPress dashboard de plugin **SitiAI Product Teksten** (deze repository wordt in de container gemount naar `wp-content/plugins/siti-ai-product-content-generator`).

### Handige commando's

- Shell in de WordPress container om bijvoorbeeld `wp` CLI of git te draaien binnen de container:
  ```bash
  docker compose exec wordpress bash
  ```
- WP-CLI is al aanwezig:
  ```bash
  docker compose exec wordpress wp plugin list
  ```
- Bekijk de database via phpMyAdmin op http://localhost:8081 (gebruik dezelfde DB-gebruiker/WW als hierboven).
- Containers stoppen:
  ```bash
  docker compose down
  ```

## Werken met git

De pluginbestanden blijven op de host staan en worden alleen als bind-mount in de container gebruikt. Daardoor kun je git gewoon op je machine gebruiken:

```bash
git status
git add .
git commit -m "Beschrijf je wijziging"
git push origin <branch>
```

Je kunt optioneel vanuit de container git gebruiken (zelfde codepad) wanneer je liever binnen Docker werkt.

## Tips

- De databank (`db_data`) en WordPress bestanden (`wordpress_data`) worden in Docker volumes opgeslagen zodat je data behouden blijft tussen sessies.
- Wil je helemaal opnieuw beginnen? Voer `docker compose down -v` uit om de volumes te verwijderen.

## Releasen via GitHub Actions

De workflow `.github/workflows/release.yml` bouwt automatisch een distributie-zip van de plugin, maakt een git-tag (`vX.Y.Z`) op basis van de versie in `groq-ai-product-text.php` en publiceert een GitHub Release met het zipbestand als asset.

1. Werk de `Version`-header in `groq-ai-product-text.php` bij en commit de wijzigingen.
2. Push naar `main` of start handmatig de workflow **Build & Release Plugin** via **Actions → Run workflow** (optioneel met extra release notes).
3. De workflow slaat releases over wanneer een tag met dezelfde versie al bestaat.
