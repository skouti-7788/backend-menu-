# Local translation service (LibreTranslate)

This service is for local development and validation. It is intentionally bound to
127.0.0.1 so it is not exposed to the public internet.

## Start

Requirements: Docker Engine and the Docker Compose plugin.

```bash
docker compose -f docker-compose.translation.yml up -d
docker compose -f docker-compose.translation.yml logs -f libretranslate
```

The first startup can take several minutes while the Arabic, French, and English
language models are prepared. Wait until the container health status is healthy.

## Verify the service

```bash
curl -fsS http://127.0.0.1:5000/health
```

Expected response:

```json
{"status":"ok"}
```

Test automatic detection and translation:

```bash
curl -sS http://127.0.0.1:5000/translate \
  -H 'Content-Type: application/json' \
  -d '{"q":["طاجين الدجاج","Tajine de poulet","Chicken Tagine"],"source":"auto","target":"en","format":"text"}'
```

LibreTranslate supports a string or an array for `q`, and returns matching
`translatedText` and `detectedLanguage` values for batch requests.

## Laravel environment

For a local Laravel backend, set:

```dotenv
TRANSLATION_API_URL=http://127.0.0.1:5000/translate
TRANSLATION_API_KEY=
TRANSLATION_PROVIDER=libretranslate
```

Then clear cached configuration:

```bash
php artisan config:clear
```

Do not set the backend's translation URL to 127.0.0.1 when Laravel runs on a
different Docker container. In that case, use the Compose service hostname
`http://libretranslate:5000/translate` on the shared Docker network.

## Stop

```bash
docker compose -f docker-compose.translation.yml down
```

The named volume is retained. To remove downloaded models as well:

```bash
docker compose -f docker-compose.translation.yml down -v
```

## Production

This local Compose file does not make LibreTranslate reachable from Render.
Deploy LibreTranslate as a separate private Render service in the same region as
the Laravel API, then set the API's `TRANSLATION_API_URL` to that private
service's internal address. Do not expose an unauthenticated translation endpoint
to the public internet. No credentials belong in this repository.
