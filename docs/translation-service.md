# Automatic translation providers (MyMemory test / LibreTranslate self-hosted)

MyMemory is the temporary provider for the Menu Online translation experiment. LibreTranslate is the self-hosted provider option.
Laravel calls MyMemory from the backend; no API key is required for the public
`/get` endpoint. Do not call the provider directly from the browser.

## Environment

```dotenv
TRANSLATION_API_URL=https://api.mymemory.translated.net/get
TRANSLATION_CONTACT_EMAIL=
TRANSLATION_PROVIDER=mymemory
# For self-hosted LibreTranslate, use provider=libretranslate, its /translate URL, and an optional private API key.
```

A valid contact email in `TRANSLATION_CONTACT_EMAIL` can raise the documented
daily quota from 5,000 to 50,000 characters. Keep it in deployment secrets,
not in source control. Leave it empty to use the anonymous quota.

After changing environment variables:

```bash
php artisan config:clear
```

## Behaviour

- Supported target languages: `ar`, `fr`, and `en`.
- Source language is detected independently for each field with
  `langpair=autodetect|TARGET`.
- Empty fields are skipped.
- Same-language results are kept as the original text.
- Input is split at sentence/word boundaries into chunks of at most 450 bytes;
  MyMemory documents a 500-byte maximum for each `q` value.
- A field is returned as translated only if every chunk succeeds. Otherwise,
  the original field is returned.
- Successful translations are cached for 30 days; provider failures cache the
  original text for 5 minutes.
- All restaurant content translations are generated and cached automatically by the backend. Restaurant users do not enter or manage translated fields.

## Limits and caution

Anonymous `/get` usage is documented as 5,000 characters/day. Providing a
valid `de` email is documented as 50,000 characters/day. Each `q` value is
limited to 500 bytes.

This is a temporary integration for testing, not production approval. Review
the provider's terms and data-sharing practices before sending real restaurant
content or enabling it in production.

Official references:
- https://mymemory.translated.net/doc/spec.php
- https://mymemory.translated.net/doc/usagelimits.php
- https://mymemory.translated.net/terms-and-conditions
