#!/usr/bin/env bash
# Section-panel guard v2.
# Check 1 (HARD, exit 1): <div> wrappers whose class TOKEN list contains
#   card or table-card — the wrapper-tag rule. Path-exempt Pages/Portal/, Pages/Referrer/.
# Check 2 (allowlisted): inline border+radius heuristic, a net for hand-rolled
#   panels that do not use .card at all.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PAGES="$ROOT/resources/js/Pages"
ALLOW="$ROOT/scripts/audit-sections.allow"
fail=0

echo "Check 1 — <div> section-panel wrappers (must be <section>):"
c1=$(grep -rnE '<div[^>]*class="([^"]* )?(card|table-card)( [^"]*)?"' "$PAGES" --include='*.vue' \
      | grep -v '/Pages/Portal/' | grep -v '/Pages/Referrer/' || true)
if [ -z "$c1" ]; then echo "  none ✓"; else
    echo "$c1" | sed "s|$ROOT/||"
    echo "  $(echo "$c1" | wc -l | tr -d ' ') violation(s) — change the wrapper to <section>."
    fail=1
fi
echo
echo "Check 2 — inline border+radius containers (heuristic, allowlisted):"
raw=$(grep -rnE 'style="[^"]*border[^"]*"' "$PAGES" --include='*.vue' \
       | grep -E 'border-radius' | grep -E 'border:' || true)
c2=""
while IFS= read -r hit; do
    [ -z "$hit" ] && continue
    ok=0
    while IFS='|' read -r glob rx _reason; do
        if [ -z "$glob" ] || [[ "$hit" == *"$glob"* ]]; then
            echo "$hit" | grep -qE "$rx" && { ok=1; break; }
        fi
    done < <(grep -v '^#' "$ALLOW" | grep -v '^[[:space:]]*$')
    [ "$ok" -eq 0 ] && c2+="$hit"$'\n'
done <<< "$raw"
c2="${c2%$'\n'}"
if [ -z "$c2" ]; then
    echo "  none ✓  ($(echo "$raw" | grep -c .) raw hit(s) suppressed by allowlist)"
else
    echo "$c2" | sed "s|$ROOT/||"
    echo "  $(echo "$c2" | grep -c .) candidate(s) — convert, or add a reviewed allowlist entry."
    fail=1
fi
exit $fail
