#!/bin/bash
# FastMagento DB-query profiler.
# Verifies pages are served from OpenSearch with as few MySQL queries as possible.
# Requires Magento db_logger enabled in app/etc/env.php:
#   'db_logger' => ['output'=>'file','log_everything'=>1,'query_time_threshold'=>'0','include_stacktrace'=>1]
# (query-profile.sh enable   turns it on;  query-profile.sh disable   turns it off.)
#
# Usage:
#   query-profile.sh enable
#   query-profile.sh <path>            e.g.  /605-jeh-001.html
#   query-profile.sh disable
#
# It hits the URL twice (warm FPC), then reports: total queries, queries per table
# (top 20), the product/EAV queries that OpenSearch should eliminate, and the top
# classes that fired SQL (from the stack traces) — so you know exactly what to extend.

set -euo pipefail
ROOT=/var/www/html/diyoffroad
HOST=www.diyoffroad.loc
LOG="$ROOT/var/debug/db.log"
CMD="${1:-}"

toggle() { php -r '
  $f="'"$ROOT"'/app/etc/env.php"; $c=include $f;
  if ("'"$1"'"==="on") {
    $c["db_logger"]=["output"=>"file","log_everything"=>1,"query_time_threshold"=>"0","include_stacktrace"=>1];
  } else { unset($c["db_logger"]); }
  file_put_contents($f, "<?php\nreturn ".var_export($c,true).";\n");
  echo "db_logger '"$1"'\n";
'; php -d memory_limit=2G "$ROOT/bin/magento" cache:flush >/dev/null 2>&1 || true; }

case "$CMD" in
  enable)  toggle on;  exit 0;;
  disable) toggle off; exit 0;;
  "") echo "usage: query-profile.sh enable|disable|<path>"; exit 1;;
esac

PATHQ="$CMD"
# warm, then measure the 2nd hit
curl -s -o /dev/null -m 60 -H "Host: $HOST" "http://127.0.0.1$PATHQ" || true
: > "$LOG" 2>/dev/null || true
code=$(curl -s -o /dev/null -w '%{http_code}' -m 60 -H "Host: $HOST" "http://127.0.0.1$PATHQ" || true)

total=$(grep -c '## ' "$LOG" 2>/dev/null || echo 0)
echo "=== $PATHQ  (HTTP $code) ==="
echo "total SQL queries on this request: $total"
echo
echo "-- queries per table (top 20) --"
grep -oiE '(FROM|JOIN|INTO|UPDATE)\s+`?[a-z0-9_]+`?' "$LOG" 2>/dev/null | awk '{print tolower($2)}' | tr -d '`' | sort | uniq -c | sort -rn | head -20
echo
echo "-- product/EAV/catalog queries (OpenSearch should eliminate these) --"
grep -icE '(catalog_product|catalog_category|eav_|cataloginventory|catalogrule|url_rewrite)' "$LOG" 2>/dev/null | xargs echo "count:"
echo
echo "-- top classes that fired SQL (from stack traces) --"
grep -oE '(ParkkTech|Magento|Webkul|Mirasvit|Mageplaza)\\\\[A-Za-z0-9_\\\\]+' "$LOG" 2>/dev/null | sort | uniq -c | sort -rn | head -15
