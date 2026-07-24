#!/usr/bin/env bash
# SmartBulk — API smoke script.
# Hits all read-only endpoints and verifies they return 200 + ok=true.
# Prerequisite: logged-in BO session cookie in $SMARTBULK_COOKIE.
#
# Usage:
#   SMARTBULK_BASE='http://localhost:8888/admin-dev/modules/smartbulk/api' \
#   SMARTBULK_COOKIE='PrestaShop-XXX=...; cookie2=...' \
#   bash tests/smoke.sh

set -euo pipefail

BASE="${SMARTBULK_BASE:-http://localhost:8888/admin-dev/modules/smartbulk/api}"
COOKIE="${SMARTBULK_COOKIE:?Set SMARTBULK_COOKIE to a logged-in BO session cookie}"

check() {
    local label="$1"
    local path="$2"
    local needle="$3"
    if curl -fsS -b "$COOKIE" "$BASE$path" | grep -q "$needle"; then
        printf '  \033[32m✓\033[0m %s\n' "$label"
    else
        printf '  \033[31m✗\033[0m %s\n' "$label"
        return 1
    fi
}

echo "Smoke-testing $BASE …"
check "ping"               "/ping"                  '"ok":true'
check "lookups/languages"  "/lookups/languages"     '"languages"'
check "lookups/brands"     "/lookups/brands"        '"brands"'
check "lookups/categories" "/lookups/categories"    '"categories"'
check "lookups/tax-rules"  "/lookups/tax-rules"     '"tax_rules"'
check "field-catalog"      "/lookups/field-catalog" '"fields"'
check "bulk/fields"        "/bulk/fields"           '"fields"'
check "bulk/templates"     "/bulk/templates"        '"templates"'
check "health/scan"        "/health/scan"           '"report"'
check "health/snapshots"   "/health/snapshots"      '"snapshots"'
check "dashboard"          "/dashboard"             '"data"'
check "history?limit=1"    "/history?limit=1"       '"items"'
check "schedules"          "/schedules"             '"schedules"'
check "segments/presets"   "/segments/presets"      '"presets"'
check "prompts"            "/prompts"               '"prompts"'

echo
echo "All endpoints OK."
