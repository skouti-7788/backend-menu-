# Menu Online translation service

This folder contains a standalone local Docker Compose setup for LibreTranslate.
It is intentionally separate from the Laravel API container.

## Run locally

From the repository root:

```bash
docker compose -f translation/docker-compose.yml up -d
docker compose -f translation/docker-compose.yml logs -f libretranslate
```

The service listens on `http://127.0.0.1:5001` on the host. The first startup may
download Argos language models and can take time.

Check readiness:

```bash
curl --fail http://127.0.0.1:5001/health
curl --fail http://127.0.0.1:5001/languages
```

Test source-language detection and translation:

```bash
curl -sS http://127.0.0.1:5001/translate \
  -H 'Content-Type: application/json' \
  -d '{"q":"طاجين الدجاج","source":"auto","target":"fr","format":"text"}'
```

Stop the service:

```bash
docker compose -f translation/docker-compose.yml down
```

The named volumes keep downloaded language models when the container is recreated.
To remove the downloaded models too, use `docker compose -f translation/docker-compose.yml down -v`.

## Laravel configuration

Set these variables in the Laravel environment (local `.env` or Render environment):

```dotenv
TRANSLATION_API_URL=http://127.0.0.1:5001/translate
TRANSLATION_API_KEY=
TRANSLATION_PROVIDER=libretranslate
```

For a hosted deployment, replace the local URL with the private/internal URL of
the separately hosted LibreTranslate service. Do not expose the translation
service publicly unless access is protected. Keep API credentials in the hosting
provider's secret environment settings; never commit them.

## Important deployment notes

- This Compose file is for local development/testing. It does not deploy a
  translation service to Render automatically.
- Deploy LibreTranslate as a separate service in the same private network/region
  as the Laravel API where possible, then use its internal `/translate` URL.
- Confirm `/health` and `/languages` before configuring Laravel.
- Confirm that the installed Argos models provide the language directions your
  restaurants need. LibreTranslate can pivot through English when a direct
  language pair is unavailable.
- The Laravel integration detects each field independently. A meal name and
  description can therefore be entered in different languages.
- Self-hosted LibreTranslate normally does not require an API key. A hosted
  provider may require one.
