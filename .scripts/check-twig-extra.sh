#!/usr/bin/env bash
# REQ-TWIG-004 — fail when Twig applies but twig/extra-bundle (and demos) are not ready.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

has_twig=0
if find src -type f -name '*.twig' 2>/dev/null | grep -q .; then
  has_twig=1
elif find templates Resources -type f -name '*.twig' 2>/dev/null | grep -q .; then
  has_twig=1
fi

if [[ "$has_twig" -eq 0 ]]; then
  echo "check-twig-extra: Twig does not apply (no templates) — OK"
  exit 0
fi

fail=0

if ! grep -q '"twig/extra-bundle"' composer.json; then
  echo "ERROR: twig/extra-bundle missing from package composer.json require (REQ-TWIG-004)" >&2
  fail=1
fi

if ! grep -q '"twig/string-extra"' composer.json; then
  echo "ERROR: twig/string-extra missing from package composer.json require (REQ-TWIG-004 baseline)" >&2
  fail=1
fi

shopt -s nullglob
for demo_composer in demo/*/composer.json demo/*/*/composer.json; do
  [[ -f "$demo_composer" ]] || continue
  demo_dir="$(dirname "$demo_composer")"
  # Skip demos that never render Twig (no bundles.php / no twig)
  bundles_php="$demo_dir/config/bundles.php"
  if [[ ! -f "$bundles_php" ]]; then
    continue
  fi
  if ! grep -q 'TwigBundle' "$bundles_php" 2>/dev/null; then
    continue
  fi
  if ! grep -q '"twig/extra-bundle"' "$demo_composer"; then
    echo "ERROR: $demo_composer missing twig/extra-bundle (REQ-TWIG-004)" >&2
    fail=1
  fi
  if ! grep -q 'Twig\\\\Extra\\\\TwigExtraBundle\\\\TwigExtraBundle' "$bundles_php" \
    && ! grep -q 'Twig\\Extra\\TwigExtraBundle\\TwigExtraBundle' "$bundles_php"; then
    echo "ERROR: $bundles_php missing TwigExtraBundle (REQ-TWIG-004)" >&2
    fail=1
  fi
done

# Legal HTML is printed through |nowo_legal_kit_sanitize_html and diff / summary text autoescaped: no bundle template
# may use |raw outside a Twig comment (contract 1.4.0 §4.3 / §14; tests/Unit/TemplatesNoRawTest.php checks the same).
if [[ -d src/Resources/views ]]; then
  raw_hits="$(find src/Resources/views -type f -name '*.twig' -print0 \
    | xargs -0 -r perl -0777 -ne 'my $t = $_; $t =~ s/\{#.*?#\}//gs; print "$ARGV\n" if $t =~ /\|\s*raw\b/' || true)"
  if [[ -n "$raw_hits" ]]; then
    echo "ERROR: |raw in bundle templates (print legal HTML with |nowo_legal_kit_sanitize_html):" >&2
    echo "$raw_hits" >&2
    fail=1
  fi
fi

if [[ "$fail" -ne 0 ]]; then
  exit 1
fi

echo "check-twig-extra: OK"
