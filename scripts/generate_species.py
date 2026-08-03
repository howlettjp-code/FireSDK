#!/usr/bin/env python3
"""Regenerates the Species registry for all three SDKs from Fire's own
service-discovery document. Run manually whenever the model catalog
changes — this is a deliberate, checked-in regenerate step, not something
that runs at install time (a normal `pip install`/`composer install`/
`npm install` needs zero network access).

Source of truth: GET /v1/capabilities (no auth) — the same document a
client bootstraps against. Grouped by `family` (Fire's own taxonomy
field); the constant name is the sanitized `species_name`. Genus is
available via GET /v1/models at runtime but deliberately not baked into
this static structure — family is the stable, meaningful grouping;
folding in genus too just adds naming-collision surface for little gain
(species_name is already globally unique and self-descriptive).

Usage:
    python3 scripts/generate_species.py [--base-url URL]
"""

from __future__ import annotations

import argparse
import json
import re
import urllib.request
from pathlib import Path
from typing import Any

REPO_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_BASE_URL = "https://fire.test1.prosaga.net"


def fetch_models(base_url: str) -> list[dict[str, Any]]:
    with urllib.request.urlopen(f"{base_url.rstrip('/')}/v1/capabilities", timeout=30) as resp:
        body = json.load(resp)
    return body["models"]


def sanitize_const(name: str) -> str:
    """species_name -> UPPER_SNAKE_CASE constant name."""
    s = re.sub(r"[^A-Za-z0-9]+", "_", name).strip("_").upper()
    if not s:
        s = "UNKNOWN"
    if s[0].isdigit():
        s = "_" + s
    return s


def sanitize_class(name: str) -> str:
    """family -> PascalCase class/namespace-segment name."""
    parts = [p for p in re.split(r"[^A-Za-z0-9]+", name) if p]
    s = "".join(p[0].upper() + p[1:] for p in parts) or "Unknown"
    if s[0].isdigit():
        s = "X" + s
    return s


def sanitize_js_key(name: str) -> str:
    """family -> lowerCamel-ish JS object key (valid identifier, no quotes needed)."""
    parts = [p for p in re.split(r"[^A-Za-z0-9]+", name) if p]
    if not parts:
        return "unknown"
    s = parts[0].lower() + "".join(p[0].upper() + p[1:] for p in parts[1:])
    if s[0].isdigit():
        s = "_" + s
    return s


def group_by_family(models: list[dict[str, Any]]) -> dict[str, list[dict[str, Any]]]:
    grouped: dict[str, list[dict[str, Any]]] = {}
    for m in sorted(models, key=lambda m: (m["family"], m["species_name"])):
        grouped.setdefault(m["family"], []).append(m)
    return grouped


def dedupe_consts(models: list[dict[str, Any]]) -> list[tuple[str, str]]:
    """Returns [(const_name, species_name), ...], numbering any collision
    from sanitization (rare — species_name is already unique, but two
    different raw names could theoretically sanitize to the same const)."""
    seen: dict[str, int] = {}
    out = []
    for m in models:
        base = sanitize_const(m["species_name"])
        seen[base] = seen.get(base, 0) + 1
        name = base if seen[base] == 1 else f"{base}_{seen[base]}"
        out.append((name, m["species_name"]))
    return out


def generate_python(grouped: dict[str, list[dict[str, Any]]], total: int) -> str:
    lines = [
        '"""GENERATED FILE — do not edit by hand.',
        "",
        "Run `python3 scripts/generate_species.py` (from the repo root) to",
        f"refresh. Source: GET /v1/capabilities — {total} species across",
        f"{len(grouped)} families as of the last regenerate.",
        '"""',
        "",
        "",
        "class Species:",
    ]
    for family in sorted(grouped):
        cls = sanitize_class(family)
        lines.append(f"    class {cls}:")
        for const, species_name in dedupe_consts(grouped[family]):
            lines.append(f'        {const} = "{species_name}"')
        lines.append("")
    return "\n".join(lines).rstrip() + "\n"


def generate_php_family(family: str, models: list[dict[str, Any]], total: int, family_count: int) -> str:
    cls = sanitize_class(family)
    lines = [
        "<?php",
        "",
        "declare(strict_types=1);",
        "",
        "namespace ModusPromethean\\FireSdk\\Species;",
        "",
        "/**",
        " * GENERATED FILE — do not edit by hand.",
        " *",
        " * Run `python3 scripts/generate_species.py` (from the repo root) to",
        f" * refresh. Source: GET /v1/capabilities — {total} species across",
        f" * {family_count} families as of the last regenerate.",
        " */",
        f"final class {cls}",
        "{",
    ]
    for const, species_name in dedupe_consts(models):
        lines.append(f"    public const {const} = '{species_name}';")
    lines.append("}")
    lines.append("")
    return "\n".join(lines)


def generate_js(grouped: dict[str, list[dict[str, Any]]], total: int) -> str:
    lines = [
        "// GENERATED FILE — do not edit by hand.",
        "//",
        "// Run `python3 scripts/generate_species.py` (from the repo root) to",
        f"// refresh. Source: GET /v1/capabilities — {total} species across",
        f"// {len(grouped)} families as of the last regenerate.",
        "",
        "export const Species = Object.freeze({",
    ]
    for family in sorted(grouped):
        key = sanitize_js_key(family)
        lines.append(f"  {key}: Object.freeze({{")
        for const, species_name in dedupe_consts(grouped[family]):
            lines.append(f"    {const}: '{species_name}',")
        lines.append("  }),")
    lines.append("});")
    lines.append("")
    return "\n".join(lines)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", default=DEFAULT_BASE_URL)
    args = parser.parse_args()

    models = fetch_models(args.base_url)
    grouped = group_by_family(models)
    total = len(models)

    # Python
    py_path = REPO_ROOT / "python" / "fire_sdk" / "species.py"
    py_path.write_text(generate_python(grouped, total))
    print(f"wrote {py_path.relative_to(REPO_ROOT)}")

    # PHP — one file per family; wipe the dir first so a family that
    # disappears from the catalog doesn't leave a stale class behind.
    php_dir = REPO_ROOT / "php" / "src" / "Species"
    if php_dir.exists():
        for f in php_dir.glob("*.php"):
            f.unlink()
    php_dir.mkdir(parents=True, exist_ok=True)
    for family, models_in_family in grouped.items():
        cls = sanitize_class(family)
        (php_dir / f"{cls}.php").write_text(generate_php_family(family, models_in_family, total, len(grouped)))
    print(f"wrote {len(grouped)} files to {php_dir.relative_to(REPO_ROOT)}/")

    # JS
    js_path = REPO_ROOT / "js" / "src" / "species.js"
    js_path.write_text(generate_js(grouped, total))
    print(f"wrote {js_path.relative_to(REPO_ROOT)}")

    print(f"\n{total} species across {len(grouped)} families: {', '.join(sorted(grouped))}")


if __name__ == "__main__":
    main()
