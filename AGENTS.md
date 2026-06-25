# AGENTS.md

## Project overview

This repository contains a single WordPress plugin: **HTML To Elementor Fidelity Importer**
(in `html-to-elementor-fidelity-importer/`). It renders arbitrary HTML/CSS/JS pages in headless
Chromium (Node.js + Puppeteer), segments the rendered layout into sections, generates valid
Elementor container data, and imports it into WordPress.

Two cooperating parts:

- **PHP plugin** (`includes/`, PSR-4 namespace `HtmlToElementor\`): admin UI, REST API
  (`/wp-json/h2e/v1`), WP-CLI (`wp h2e ...`), section→Elementor JSON generation, import/export.
- **Node Chromium service** (`chromium-service/`): `cli.js` (spawned per-conversion by PHP) and
  `server.js` (optional long-running HTTP mode). Renders + extracts DOM/computed-styles/box-model.

Standard commands live in `README.md`, `chromium-service/README.md`, root `package.json`
scripts, and `composer.json`. Prefer those over duplicating here.

## Cursor Cloud specific instructions

System tooling used during setup (Node 18+ ships on the base image; PHP 8.3, Composer and WP-CLI
were installed via apt and are captured in the VM snapshot): `php`, `composer`, `wp` (WP-CLI),
`node`, `npm`.

Dependency refresh (composer + chromium-service npm) is handled by the startup update script, so
you normally do not need to install project deps manually.

### Running the conversion pipeline WITHOUT WordPress (fastest end-to-end check)

The Node renderer and the PHP JSON generator can be exercised directly:

```bash
cd html-to-elementor-fidelity-importer
node chromium-service/cli.js --input tests/fixtures/sample.html --out /tmp/layout.json
php tests/harness.php /tmp/layout.json preserve   # or: widgets
```

- Puppeteer downloads its own Chromium into `~/.cache/puppeteer` during `npm install`; the
  renderer launches it with `--no-sandbox` (required in this container).
- The PHP plugin loads via Composer's autoloader when present, otherwise a bundled PSR-4 fallback
  (`includes/Support/Autoloader.php`) — so it runs even if `composer install` was skipped.

### Lint / test

```bash
cd html-to-elementor-fidelity-importer
shopt -s globstar; for f in **/*.php; do php -l "$f" >/dev/null || echo "LINT FAIL $f"; done
./vendor/bin/phpunit          # unit tests for the generator/detector (needs composer install)
```

### Full WordPress + Elementor harness (for real import testing)

There is no committed WordPress install. To test the real import end-to-end, provision a throwaway
WordPress with the SQLite drop-in (no MySQL/Docker needed) and symlink the plugin:

```bash
wp core download --path=/tmp/wp --allow-root
wp config create --path=/tmp/wp --dbname=wp --dbuser=root --dbpass= --skip-check --force --allow-root
# SQLite drop-in (wp-cli can't reach a DB yet, so fetch the plugin via curl):
curl -sSL -o /tmp/sqlite.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
unzip -q -o /tmp/sqlite.zip -d /tmp/wp/wp-content/plugins/
cp /tmp/wp/wp-content/plugins/sqlite-database-integration/db.copy /tmp/wp/wp-content/db.php
sed -i "s|{SQLITE_IMPLEMENTATION_FOLDER_PATH}|/tmp/wp/wp-content/plugins/sqlite-database-integration|g" /tmp/wp/wp-content/db.php
sed -i "s|{SQLITE_PLUGIN}|sqlite-database-integration/load.php|g" /tmp/wp/wp-content/db.php
wp core install --path=/tmp/wp --url=http://localhost:8088 --title="H2E Demo" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --allow-root
wp plugin install elementor --activate --path=/tmp/wp --allow-root
ln -sfn "$PWD/html-to-elementor-fidelity-importer" /tmp/wp/wp-content/plugins/html-to-elementor-fidelity-importer
wp plugin activate html-to-elementor-fidelity-importer --path=/tmp/wp --allow-root
wp h2e import "$PWD/html-to-elementor-fidelity-importer/tests/fixtures/sample.html" --title="Demo" --status=publish --path=/tmp/wp --allow-root
wp server --host=0.0.0.0 --port=8088 --path=/tmp/wp --allow-root   # then view http://localhost:8088/?page_id=<id>
```

Gotchas learned:

- The SQLite integration plugin must be installed via `curl`+`unzip` BEFORE `wp core install`,
  because `wp plugin install` itself needs a working DB connection.
- The plugin spawns `node` via `proc_open`; `node` must be on the PATH of the PHP/WP-CLI process.
- Imported pages use the `elementor_canvas` template and store data in `_elementor_data`. Elementor
  renders the containers + HTML widgets on the frontend automatically once Elementor is active.
