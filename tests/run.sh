#!/bin/sh
# Runs every access-model test inside a PHP 8.1 container with intl, mbstring, pdo_sqlite and poppler.
# Needs a one-time:  podman build -t il-phptest -f tests/Dockerfile.phptest tests
cd "$(dirname "$0")/.." || exit 1
fail=0
for t in ai_consent ai_foundation ai_openai chat search search_index_upgrade frontend_sync import_reliability import_upload item_refcount import_dedupe import_text bibtex_ris upload_limit import_security safe_download logs_retention hardening connectors logs project_notes fix_round project_access item_access project_roles member_roles own_item facet_leak privacy; do
  printf '%-22s' "$t"
  out=$(podman run --rm -v "$PWD":/w -w /w il-phptest php tests/${t}_test.php 2>&1)
  echo "$out" | grep -E "ALL PASSED|FAILED" || { echo "(no result)"; fail=1; }
  echo "$out" | grep -q "ALL PASSED" || fail=1
done
exit $fail
