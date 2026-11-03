# Deploy LibreTranslate for the Render backend

This guide deploys the translation provider separately from the Laravel API.
Keep the provider private: the public menu must call Laravel, never LibreTranslate
directly.

## 1. Create the provider service

In the Render dashboard, create a **Private Service** from the official
`libretranslate/libretranslate:v1.9.6` Docker image.

Use the same Render region as the Laravel API so the API can reach the provider
over Render's private network.

Configure the service to listen on port `5000` and add these environment
variables:

| Variable | Value |
| --- | --- |
| `LT_LOAD_ONLY` | `en,fr,ar` |
| `LT_THREADS` | `2` |
| `LT_DISABLE_WEB_UI` | `true` |

Do not create a public web service for an unauthenticated translation endpoint.
Do not add provider credentials to Git.

## 2. Wait for language models

The first start can take several minutes while the English, French, and Arabic
models are downloaded and initialized. Check the service logs and verify its
health endpoint from within the private network:

```bash
curl -fsS http://<private-service-hostname>:5000/health
```

Expected response:

```json
{"status":"ok"}
```

Then test automatic source detection and translation:

```bash
curl -fsS http://<private-service-hostname>:5000/translate \
  -H 'Content-Type: application/json' \
  -d '{"q":"طاجين الدجاج","source":"auto","target":"fr","format":"text"}'
```

Confirm that the response contains a non-empty `translatedText` and that
`detectedLanguage.language` identifies Arabic (`ar`).

## 3. Configure the Laravel API service

In the Render environment settings for the Laravel API, add:

```dotenv
TRANSLATION_PROVIDER=libretranslate
TRANSLATION_API_URL=http://<private-service-hostname>:5000/translate
TRANSLATION_API_KEY=<optional-private-provider-key>
```

Replace the hostname with the actual private hostname shown by Render. Do not
guess the hostname and do not use `127.0.0.1`: the provider and Laravel API are
separate services.

Save the environment changes and wait for the Laravel API to redeploy. Laravel
reads these values through `config/services.php`.

## 4. Production smoke checks

Run these checks against the deployed Laravel API and a test restaurant:

1. Request `GET /api/menu/<slug>?lang=en`, then repeat for `fr` and `ar`.
2. Use source text in Arabic, French, and English across meal names and
   descriptions; verify each requested language.
3. Confirm translations are generated automatically from the original content for each selected language.
4. Confirm prices, phone numbers, addresses, opening hours, and links are
   identical across language requests.
5. Temporarily make the provider unavailable in staging and confirm the menu
   falls back to original text rather than failing.
6. Check Laravel logs for provider timeouts or model errors.

The Laravel service currently uses a 2-second connection timeout, a 4-second
request timeout, a 30-day cache for successful translations, and a 5-minute
cache for provider-failure fallbacks.

## Important operational notes

- Keep the provider private and allow only the Laravel API to reach it.
- Verify whether the Render service needs a persistent disk for downloaded
  language models; without persistence, a replacement or restart may require
  downloading models again.
- Monitor memory and startup logs during the first model load. Increase the
  service resources if the provider cannot load all three language models.
- First-time cache misses currently translate synchronously during a public
  menu request. Test menu latency with a realistic number of meals before
  production traffic; background pre-translation is a future optimization.
- Never put the provider URL or key in the React public-menu environment.
