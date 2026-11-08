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

if ! grep -qF "strpos(\$requestUri, '?')" "$MODULE"; then
  echo "production export-url fix missing; abort proof" >&2
  exit 1
fi

export ROOT
python3 - <<'PY'
from pathlib import Path
import os
path = Path(os.environ["ROOT"]) / "statsbestproducts.php"
text = path.read_text()
old = """        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $separator = (strpos($requestUri, '?') === false) ? '?' : '&';
        $exportUrl = Tools::safeOutput($requestUri . $separator . 'export=1');

        return '<div class=\"panel-heading\">' . $this->displayName . '</div>
\t\t' . $this->engine($engine_params) . '
\t\t<a class=\"btn btn-default export-csv\" href=\"' . $exportUrl . '\">"""
new = """        return '<div class=\"panel-heading\">' . $this->displayName . '</div>
\t\t' . $this->engine($engine_params) . '
\t\t<a class=\"btn btn-default export-csv\" href=\"' . Tools::safeOutput($_SERVER['REQUEST_URI'] . '&export=1') . '\">"""
if old not in text:
    raise SystemExit('expected export-url block not found')
path.write_text(text.replace(old, new, 1))
PY

set +e
USE_SUDO_DOCKER="${USE_SUDO_DOCKER:-0}" "$ROOT/tests/proof-run-filter.sh" HookDisplayTest::testHookInsertsQuestionMarkWhenUriHasNoQueryString
code1=$?
USE_SUDO_DOCKER="${USE_SUDO_DOCKER:-0}" "$ROOT/tests/proof-run-filter.sh" HookDisplayTest::testHookDoesNotNoticeWhenRequestUriIsUnset
code2=$?
set -e
if [ "$code1" -eq 0 ] || [ "$code2" -eq 0 ]; then
  echo "proof failed: export href test passed with reverted hook" >&2
  exit 1
fi
echo "proof-export-href: expected failures observed"
