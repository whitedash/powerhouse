#!/usr/bin/env bash
#
# Section-panel guard (heuristic). Flags inline-styled, card-like containers in
# Inertia pages — a `style="…"` attribute that combines `border` with
# `border-radius`, which is the signature of a hand-rolled section panel that
# should be `<section class="card">` instead (see CONVENTIONS.md → Section panels).
#
# This is a REVIEW AID, not a hard gate: it has false positives (badges, inputs,
# code chips). Eyeball each hit — if it wraps a section/table/list, convert it.
#
# Usage:  composer audit:sections      (or:  bash scripts/audit-sections.sh)
# Exit 0 always; prints candidates for manual review.

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PAGES="$ROOT/resources/js/Pages"

echo "Section-panel guard — candidate hand-rolled panels (inline border + radius):"
echo "  (heuristic; review each — see CONVENTIONS.md → Section panels)"
echo

# style attributes that contain BOTH `border` and `border-radius`.
hits=$(grep -rnE 'style="[^"]*border[^"]*"' "$PAGES" --include=*.vue \
        | grep -E 'border-radius' \
        | grep -E 'border:' || true)

if [ -z "$hits" ]; then
    echo "  none found ✓"
else
    echo "$hits" | sed "s|$ROOT/||"
    echo
    echo "  $(echo "$hits" | wc -l | tr -d ' ') candidate(s). Convert any that wrap a section/table/list to <section class=\"card\">."
fi
