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

## Deploy LibreTranslate on Render (separate private service)

The Docker Compose file above is for local development only. It does not create a
Render service. Create a separate **Private Service** in the same Render workspace
as the Laravel API:

1. Choose **Deploy an existing image** and use
   `libretranslate/libretranslate:latest`.
2. Set the service name to `libretranslate`. Choose the **same region** as the
   Laravel API so the API can reach it over Render's private network.
3. Set the Docker command/arguments to:

   ```bash
   --host 0.0.0.0 --port 5000 --load-only en,fr,ar --disable-web-ui
   ```

   Confirm that the selected Render image deployment mode accepts a Docker
   command override. If it does not, use a small Dockerfile based on the
   LibreTranslate image that sets these arguments as its CMD.

4. Attach a persistent disk at
   `/home/libretranslate/.local/share` only after confirming this is the
   writable data directory used by the selected LibreTranslate image version.
   Persistent disks require a paid Render service.
5. Keep the service private and do not expose it to the public internet.
   Verify the actual private hostname assigned by Render; do not assume it is
   always `libretranslate`.
6. In the Laravel API service's environment settings, set the URL using the
   actual private hostname and port, for example:

   ```dotenv
   TRANSLATION_API_URL=http://libretranslate:5000/translate
   TRANSLATION_PROVIDER=libretranslate
   TRANSLATION_API_KEY=
   ```

   Leave the API key empty for a self-hosted LibreTranslate instance unless you
   explicitly enable API-key protection on the translation service.

7. Deploy the translation service and wait for its first startup to finish
   downloading the requested language models. Check the service logs before
   testing the API from Laravel.

### Verify the live service

From a shell that can reach the private Render network, check:

```bash
curl --fail http://libretranslate:5000/health
curl --fail http://libretranslate:5000/languages
```

Then test the six directions individually using the curl example above, changing
the source text and target language. Confirm the `detectedLanguage.language`
field matches the actual source language for each sample.

**Do not mark translation production-ready based only on Laravel CI.** CI tests
the Laravel integration with mocked HTTP responses; a live LibreTranslate
deployment and real language-model checks are separate release gates.
