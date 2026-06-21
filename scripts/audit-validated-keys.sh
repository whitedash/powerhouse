#!/usr/bin/env bash
# audit:validated-keys — excludeUnvalidatedArrayKeys tripwire.
#
# WHY: when a validation ruleset has a rule on a CHILD of a wildcard array element
# (e.g. `steps.*.fields.*.content`), Laravel's excludeUnvalidatedArrayKeys prunes
# every UN-ruled sibling out of validated(). If downstream code then reads a pruned
# sibling, data is silently lost (the multi-step form-save bug). The safe shape is:
# rule EVERY persisted sibling key, so validated() returns the complete element.
#
# WHAT (HARD, exit 1): detect every wildcard-array path `X.*` that carries at least
# one child rule `X.*.<key>`, and fail if that `file::X.*` is not signed off in
# scripts/audit-validated-keys.allow. Each allowlist line is a human assertion that
# all persisted sibling keys under that array are ruled (or the blob is read from
# raw input). Scalar-list wildcards (`foo.*` with no `foo.*.<key>`) are NOT flagged.
#
# LIMITATION: this is a STATIC scan of literal `'a.*.b' => ...` rule keys. It is a
# REVIEW TRIPWIRE for new static nested validators, NOT a completeness proof:
#   - it cannot verify that the ruled keys actually equal the persisted keys;
#   - dynamically-interpolated rule keys (e.g. "actions.$i.config.$k") are invisible
#     to the scan — those sites must be reasoned about by hand (see WorkflowController).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP="$ROOT/app"
ALLOW="$ROOT/scripts/audit-validated-keys.allow"
fail=0

echo "audit:validated-keys — wildcard-array validators carrying child rules:"

detected="$(mktemp)"
trap 'rm -f "$detected"' EXIT

# Find every literal rule key of the form  '<prefix>.*.<rest>' =>  (single OR double
# quoted, followed by =>). For each, the at-risk array is the key minus its final
# `.segment`; keep it only when that prefix still ends in `.*` (i.e. the matched key
# was a CHILD of a wildcard array element, not a trailing scalar-list wildcard).
grep -rHoE "['\"][A-Za-z0-9_.]+\.\*\.[A-Za-z0-9_.*]+['\"][[:space:]]*=>" "$APP" --include='*.php' 2>/dev/null \
  | while IFS= read -r hit; do
      file="${hit%%:*}"
      key="$(printf '%s' "$hit" | grep -oE "['\"][A-Za-z0-9_.*]+['\"]" | head -1 | tr -d "\"'")"
      [ -z "$key" ] && continue
      prefix="${key%.*}"                 # drop the final .segment
      case "$prefix" in
        *.\*) printf '%s::%s\n' "${file#"$ROOT"/}" "$prefix" ;;
      esac
    done | sort -u > "$detected"

# Normalised allowlist tokens (strip trailing "# reason" and surrounding space).
allow_tokens="$(grep -v '^[[:space:]]*#' "$ALLOW" 2>/dev/null \
  | sed -E 's/[[:space:]]*#.*$//; s/[[:space:]]+$//; s/^[[:space:]]+//' \
  | grep -v '^[[:space:]]*$' || true)"

count=0
while IFS= read -r entry; do
  [ -z "$entry" ] && continue
  count=$((count + 1))
  if ! printf '%s\n' "$allow_tokens" | grep -Fxq "$entry"; then
    echo "  UNSIGNED: $entry"
    echo "      └─ rule every persisted sibling key (or read the blob from raw input),"
    echo "         then add this line to scripts/audit-validated-keys.allow with a reason."
    fail=1
  fi
done < "$detected"

if [ "$fail" -eq 0 ]; then
  echo "  none unsigned ✓  ($count wildcard-array validator(s), all signed off)"
fi

# Soft warning: allowlist entries that no longer match anything (drifted/removed).
while IFS= read -r tok; do
  [ -z "$tok" ] && continue
  if ! grep -Fxq "$tok" "$detected"; then
    echo "  note: stale allowlist entry (no longer detected): $tok"
  fi
done <<< "$allow_tokens"

exit $fail
