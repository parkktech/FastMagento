#!/usr/bin/env bash
# Warm HTTP /totals-information benchmark against the live php-fpm path.
# Builds a guest cart once, then times totals-information N times (median).
# Usage: totals-http-bench.sh [label] [N] [sku] [qty]
set -u
HOST="www.diyoffroad.loc"
BASE="http://127.0.0.1"
LABEL="${1:-run}"
N="${2:-25}"
SKU="${3:-200-JEH-001}"
QTY="${4:-2}"
H=(-H "Host: $HOST" -H "Content-Type: application/json")

j() { curl -s "${H[@]}" "$@"; }

# 1. guest cart
CART=$(j -X POST "$BASE/rest/V1/guest-carts" | tr -d '"')
if [ -z "$CART" ] || [ "${#CART}" -lt 10 ]; then echo "FAILED to create guest cart: $CART"; exit 1; fi
echo "[$LABEL] cart=$CART"

# 2. add item
ADD=$(j -X POST "$BASE/rest/V1/guest-carts/$CART/items" \
  -d "{\"cartItem\":{\"sku\":\"$SKU\",\"qty\":$QTY,\"quote_id\":\"$CART\"}}")
echo "[$LABEL] add: $(echo "$ADD" | cut -c1-80)"

BODY='{"addressInformation":{"address":{"country_id":"US","region_id":12,"region":"California","region_code":"CA","postcode":"90001","city":"Los Angeles","street":["1 Test St"],"firstname":"T","lastname":"T","telephone":"5555550100"},"shipping_method_code":"flatrate","shipping_carrier_code":"flatrate"}}'

# warm
j -X POST "$BASE/rest/V1/guest-carts/$CART/totals-information" -d "$BODY" -o /dev/null
# sanity: show grand total once
GT=$(j -X POST "$BASE/rest/V1/guest-carts/$CART/totals-information" -d "$BODY" | grep -o '"grand_total":[0-9.]*' | head -1)
echo "[$LABEL] $GT"

# time N calls
TIMES=()
for i in $(seq 1 "$N"); do
  T=$(curl -s "${H[@]}" -o /dev/null -w "%{time_total}" -X POST \
      "$BASE/rest/V1/guest-carts/$CART/totals-information" -d "$BODY")
  TIMES+=("$T")
done

# median + min via awk
printf "%s\n" "${TIMES[@]}" | sort -n | awk -v label="$LABEL" '
{ a[NR]=$1 }
END {
  n=NR; min=a[1]; max=a[n];
  med=(n%2)? a[int(n/2)+1] : (a[n/2]+a[n/2+1])/2;
  sum=0; for(i=1;i<=n;i++) sum+=a[i];
  printf "[%s] totals-information warm: median=%.0f ms  min=%.0f ms  max=%.0f ms  mean=%.0f ms  (n=%d)\n",
    label, med*1000, min*1000, max*1000, (sum/n)*1000, n;
}'
