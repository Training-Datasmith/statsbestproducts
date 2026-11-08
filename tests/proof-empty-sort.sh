#!/usr/bin/env bash
set -euo pipefail

if [ "${RUN_PROOF:-0}" != "1" ]; then
  echo "set RUN_PROOF=1 to run proofs" >&2
  exit 1
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MODULE="$ROOT/statsbestproducts.php"
BACKUP="$(mktemp)"
chmod 0600 "$BACKUP"
cp "$MODULE" "$BACKUP"
restore() {
  cp "$BACKUP" "$MODULE"
  rm -f "$BACKUP"
}
trap restore EXIT

if ! grep -q 'is_string($this->_sort)' "$MODULE"; then
  echo "production fix guard missing; abort proof" >&2
  exit 1
fi

python3 - "$MODULE" <<'PY'
import sys
path = sys.argv[1]
text = open(path, encoding="utf-8").read()
old = "if (is_string($this->_sort) && $this->_sort !== '' && Validate::IsName($this->_sort)) {"
new = "if (Validate::IsName($this->_sort)) {"
if old not in text:
    raise SystemExit("expected sort guard not found")
open(path, "w", encoding="utf-8").write(text.replace(old, new, 1))
PY

set +e
USE_SUDO_DOCKER="${USE_SUDO_DOCKER:-0}" "$ROOT/tests/proof-run-filter.sh" GetDataTest::testEmptySortDoesNotEmitOrderBy
code=$?
set -e
if [ "$code" -eq 0 ]; then
  echo "proof failed: test passed with reverted sort guard" >&2
  exit 1
fi
echo "proof-empty-sort: expected failure observed"
