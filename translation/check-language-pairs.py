#!/usr/bin/env python3
"""Smoke-test LibreTranslate language detection and all EN/FR/AR directions."""

import json
import os
import sys
import urllib.error
import urllib.request

BASE_URL = os.environ.get("TRANSLATION_BASE_URL", "http://127.0.0.1:5001").rstrip("/")
API_KEY = os.environ.get("TRANSLATION_API_KEY", "")
CASES = [
    ("ar", "fr", "طاجين الدجاج مع الزيتون"),
    ("ar", "en", "طاجين الدجاج مع الزيتون"),
    ("fr", "ar", "Tajine de poulet aux olives"),
    ("fr", "en", "Tajine de poulet aux olives"),
    ("en", "ar", "Chicken tagine with olives"),
    ("en", "fr", "Chicken tagine with olives"),
]


def post_json(path, payload):
    request = urllib.request.Request(
        f"{BASE_URL}{path}",
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        headers={"Content-Type": "application/json", "Accept": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=20) as response:
        return json.loads(response.read().decode("utf-8"))


def main():
    try:
        with urllib.request.urlopen(f"{BASE_URL}/health", timeout=5) as response:
            if response.status != 200:
                raise RuntimeError(f"Health endpoint returned HTTP {response.status}")
        print(f"LibreTranslate health: OK ({BASE_URL})")

        failures = []
        for expected_source, target, sample in CASES:
            try:
                payload = {
                    "q": sample,
                    "source": "auto",
                    "target": target,
                    "format": "text",
                }
                if API_KEY:
                    payload["api_key"] = API_KEY
                result = post_json("/translate", payload)
                translated = result.get("translatedText")
                detected = (result.get("detectedLanguage") or {}).get("language")
                if not isinstance(translated, str) or not translated.strip():
                    raise RuntimeError("empty translatedText")
                if detected != expected_source:
                    raise RuntimeError(
                        f"expected detected language {expected_source!r}, got {detected!r}"
                    )
                print(f"PASS {expected_source.upper()} -> {target.upper()}: {translated}")
            except (urllib.error.URLError, TimeoutError, ValueError, RuntimeError) as error:
                failures.append(f"{expected_source}->{target}: {error}")
                print(f"FAIL {expected_source.upper()} -> {target.upper()}: {error}")

        if failures:
            print("\nSome language checks failed. Verify installed Argos models.", file=sys.stderr)
            return 1

        print("\nAll six language-direction smoke tests passed.")
        return 0
    except (urllib.error.URLError, TimeoutError, ValueError, RuntimeError) as error:
        print(f"LibreTranslate check failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
