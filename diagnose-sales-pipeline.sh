#!/usr/bin/env bash
#
# diagnose-sales-pipeline.sh
#
# Traces WhatsApp -> Evolution -> Hybrid -> AI -> Evolution -> WhatsApp for the
# dishnet_sales number and prints a component table with the real errors.
#
# Run ON THE SERVER as root:
#
#     export EVO_KEY='your-evolution-api-key'
#     bash diagnose-sales-pipeline.sh
#
# Read-only by default. It queries Evolution, tests the public webhook
# endpoint, inspects the queue and worker, and checks the AI provider.
# It changes nothing unless you pass a flag:
#
#     --go               do everything remaining, in order, stopping at the
#                        first thing that genuinely fails
#     --fix-traefik      write the Traefik route for crm.dishnetsudan.com
#     --set-webhook      point dishnet_sales at this plugin's webhook
#     --send-test <num>  send one outbound WhatsApp message (digits only)
#
# Tokens and keys are masked in all output.
#
set -uo pipefail

DOMAIN="${DOMAIN:-crm.dishnetsudan.com}"
PLUGIN="${PLUGIN:-dishnet-hybrid-sudan}"
INSTANCE="${INSTANCE:-dishnet_sales}"
EVO_URL="${EVO_URL:-https://evo-evolution-api.rz2qqk.easypanel.host}"
EVO_KEY="${EVO_KEY:-}"

FIX_TRAEFIK=0; SET_WEBHOOK=0; SEND_TEST=""; GO=0
while [ $# -gt 0 ]; do
  case "$1" in
    --go)          GO=1; FIX_TRAEFIK=1; SET_WEBHOOK=1 ;;
    --fix-traefik) FIX_TRAEFIK=1 ;;
    --set-webhook) SET_WEBHOOK=1 ;;
    --send-test)   SEND_TEST="${2:-}"; shift ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
  shift
done

# ── result table ─────────────────────────────────────────────────────────────
declare -a ROWS
record() { ROWS+=("$1|$2|$3"); }          # component | PASS/FAIL/SKIP | detail
mask()   { sed -E 's/(token=|apikey: |Bearer )[A-Za-z0-9._-]{6,}/\1<masked>/g'; }
head2()  { printf '\n\033[1m== %s ==\033[0m\n' "$1"; }
say()    { printf '   %s\n' "$1"; }

if [ -z "$EVO_KEY" ]; then
  echo "Set EVO_KEY first:  export EVO_KEY='...'" >&2
  exit 2
fi

# ── 0. containers ────────────────────────────────────────────────────────────
head2 "0. Containers"
EVO_CT=$(docker ps --format '{{.Names}}' 2>/dev/null | grep -i 'evolution-api\.' | grep -v -- '-db\|-redis' | head -1)
UCRM_CT=$(docker ps --format '{{.Names}}' 2>/dev/null | grep -ix 'ucrm' | head -1)
TRAEFIK_CT=$(docker ps --format '{{.Names}}' 2>/dev/null | grep -i traefik | head -1)
say "evolution : ${EVO_CT:-NOT FOUND}"
say "ucrm      : ${UCRM_CT:-NOT FOUND}"
say "traefik   : ${TRAEFIK_CT:-NOT FOUND}"
[ -n "$EVO_CT" ]  && record "Evolution container" PASS "$EVO_CT"  || record "Evolution container" FAIL "not running"
[ -n "$UCRM_CT" ] && record "uCRM container"      PASS "$UCRM_CT" || record "uCRM container"      FAIL "not running"

PLUGIN_ROOT="/data/ucrm/data/plugins/$PLUGIN"
if [ -n "$UCRM_CT" ]; then
  docker exec "$UCRM_CT" test -f "$PLUGIN_ROOT/public.php" 2>/dev/null \
    && record "Plugin installed" PASS "$PLUGIN_ROOT" \
    || { record "Plugin installed" FAIL "$PLUGIN_ROOT/public.php not found"; say "!! plugin path wrong — check: docker exec $UCRM_CT ls /data/ucrm/data/plugins/"; }
fi

# ── 1. Evolution API itself ──────────────────────────────────────────────────
head2 "1. Evolution API"
ROOT_JSON=$(curl -s --max-time 15 "$EVO_URL/" 2>&1)
VER=$(echo "$ROOT_JSON" | grep -oE '"version":"[^"]+"' | cut -d'"' -f4)
if [ -n "$VER" ]; then
  say "version: $VER"; record "Evolution API" PASS "v$VER at $(echo "$EVO_URL" | sed -E 's#https?://##')"
else
  say "no version in response: $(echo "$ROOT_JSON" | head -c 200)"; record "Evolution API" FAIL "no response from $EVO_URL"
fi

# ── 2. does the instance exist, and is it connected? ─────────────────────────
head2 "2. Instance $INSTANCE"
INST_JSON=$(curl -s --max-time 20 -H "apikey: $EVO_KEY" "$EVO_URL/instance/fetchInstances" 2>&1)
COUNT=$(echo "$INST_JSON" | grep -oE '"(name|instanceName)":"[^"]+"' | wc -l)
say "instances visible: $COUNT"
echo "$INST_JSON" | grep -oE '"(name|instanceName)":"[^"]+"|"(connectionStatus|state)":"[^"]+"' | paste - - 2>/dev/null | sed 's/^/     /'

if echo "$INST_JSON" | grep -q "\"$INSTANCE\""; then
  record "$INSTANCE exists" PASS "listed by Evolution"
  STATE_JSON=$(curl -s --max-time 15 -H "apikey: $EVO_KEY" "$EVO_URL/instance/connectionState/$INSTANCE" 2>&1)
  STATE=$(echo "$STATE_JSON" | grep -oE '"state":"[^"]+"' | head -1 | cut -d'"' -f4)
  say "connectionState: ${STATE:-unknown}"
  if [ "$STATE" = "open" ]; then
    record "WhatsApp paired" PASS "state=open"
    record "Instance state"  PASS "open"
  else
    record "WhatsApp paired" FAIL "state=${STATE:-unknown} — scan the QR"
    record "Instance state"  FAIL "${STATE:-unknown}"
  fi
else
  record "$INSTANCE exists" FAIL "not present in Evolution — create it in the manager"
  record "WhatsApp paired"  SKIP "no instance"
  record "Instance state"   SKIP "no instance"
  say "!! THIS IS THE BLOCKER. Create it: Evolution manager -> Instances -> Create -> $INSTANCE"
fi

# ── 3. Traefik route for the domain ──────────────────────────────────────────
head2 "3. Traefik route for $DOMAIN"
DYN=/etc/easypanel/traefik/dynamic
RESOLVER=$(grep -A6 -i certificatesResolvers /etc/easypanel/traefik/traefik.yml 2>/dev/null \
           | grep -oE '^\s{2,}[a-zA-Z0-9_-]+:' | head -1 | tr -d ' :')
say "dynamic dir : $([ -d "$DYN" ] && echo "$DYN" || echo 'NOT FOUND')"
say "certResolver: ${RESOLVER:-could not read}"

if [ "$FIX_TRAEFIK" = "1" ]; then
  if [ ! -d "$DYN" ]; then
    say "!! $DYN does not exist — not writing blind. Find it with:"
    say "   docker inspect $TRAEFIK_CT --format '{{range .Mounts}}{{.Source}} -> {{.Destination}}{{println}}{{end}}'"
  else
    GW=$(ip -4 addr show docker0 2>/dev/null | awk '/inet /{print $2}' | cut -d/ -f1)
    GW="${GW:-172.17.0.1}"
    cat > "$DYN/uisp.yml" <<YAML
# Routes $DOMAIN to UISP on 8443 so it gets a real certificate.
# Devices keep using :8443 directly — only browsers and webhooks come here.
http:
  routers:
    uisp:
      rule: "Host(\`$DOMAIN\`)"
      entryPoints: ["websecure"]
      service: uisp
      tls:
        certResolver: ${RESOLVER:-letsencrypt}
  services:
    uisp:
      loadBalancer:
        servers:
          - url: "https://$GW:8443"
        serversTransport: uisp-selfsigned
  serversTransports:
    uisp-selfsigned:
      insecureSkipVerify: true
YAML
    say "wrote $DYN/uisp.yml (backend https://$GW:8443, resolver ${RESOLVER:-letsencrypt})"
    say "Traefik reloads dynamic config by itself. Give it ~30s, then re-run."
    sleep 20
  fi
fi

if [ "$GO" = "1" ]; then
  say "waiting for Let's Encrypt to issue (up to 2 minutes)..."
  for i in $(seq 1 24); do
    C=$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 "https://$DOMAIN/crm/_plugins/$PLUGIN/public.php" 2>/dev/null)
    I=$(echo | timeout 8 openssl s_client -connect "$DOMAIN:443" -servername "$DOMAIN" 2>/dev/null | openssl x509 -noout -issuer 2>/dev/null)
    if [ "$C" = "200" ] || [ "$C" = "302" ]; then
      echo "$I" | grep -qi "let's encrypt" && { say "certificate issued after $((i*5))s"; break; }
    fi
    sleep 5
  done
fi

DOM_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "https://$DOMAIN/crm/_plugins/$PLUGIN/public.php" 2>/dev/null)
DOM_CERT=$(echo | timeout 12 openssl s_client -connect "$DOMAIN:443" -servername "$DOMAIN" 2>/dev/null \
           | openssl x509 -noout -issuer 2>/dev/null)
say "https://$DOMAIN/... -> HTTP ${DOM_CODE:-no answer}"
say "certificate issuer : ${DOM_CERT:-could not read}"
if [ "$DOM_CODE" = "200" ] || [ "$DOM_CODE" = "302" ]; then
  record "Domain -> uCRM" PASS "HTTP $DOM_CODE"
  echo "$DOM_CERT" | grep -qi "let's encrypt\|R1[0-9]\|E[0-9]" \
    && record "Valid certificate" PASS "$(echo "$DOM_CERT" | sed 's/issuer=//')" \
    || record "Valid certificate" FAIL "not a public CA — Evolution will refuse it"
else
  record "Domain -> uCRM" FAIL "HTTP ${DOM_CODE:-no answer} — Traefik route missing (run with --fix-traefik)"
  record "Valid certificate" SKIP "no route"
fi

# ── 4. the public webhook endpoint ───────────────────────────────────────────
head2 "4. Webhook endpoint"
WH_BASE="https://$DOMAIN/crm/_plugins/$PLUGIN/public.php?page=evo_webhook"
say "$WH_BASE"

# No token: must be 401, which proves routing AND that the guard is live.
NOTOK=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -X POST "$WH_BASE" 2>/dev/null)
say "POST without token -> HTTP ${NOTOK:-no answer}"
case "$NOTOK" in
  401) record "Webhook reachable"      PASS "routed, guard active (401)"
       record "Webhook authentication" PASS "rejects an unauthenticated POST" ;;
  404) record "Webhook reachable"      FAIL "404 — route or plugin path wrong"
       record "Webhook authentication" SKIP "never reached" ;;
  000|"") record "Webhook reachable"   FAIL "no answer — Traefik route or TLS"
       record "Webhook authentication" SKIP "never reached" ;;
  *)   record "Webhook reachable"      FAIL "HTTP $NOTOK (expected 401)"
       record "Webhook authentication" FAIL "unexpected status" ;;
esac

# Same call from inside the Evolution container — that is the path that matters.
if [ -n "$EVO_CT" ]; then
  FROM_EVO=$(docker exec "$EVO_CT" sh -c \
    "curl -s -o /dev/null -w '%{http_code}' --max-time 20 -X POST '$WH_BASE' 2>/dev/null" 2>/dev/null)
  say "same POST from inside Evolution -> HTTP ${FROM_EVO:-blocked}"
  [ "$FROM_EVO" = "401" ] \
    && record "Evolution can reach it" PASS "401 from inside the container" \
    || record "Evolution can reach it" FAIL "HTTP ${FROM_EVO:-no answer} from inside the container"
fi

# ── 5. Evolution's webhook configuration ─────────────────────────────────────
head2 "5. Webhook configured on $INSTANCE"
WH_JSON=$(curl -s --max-time 15 -H "apikey: $EVO_KEY" "$EVO_URL/webhook/find/$INSTANCE" 2>&1)
echo "$WH_JSON" | mask | head -c 600 | sed 's/^/     /'; echo
WH_URL=$(echo "$WH_JSON" | grep -oE '"url":"[^"]*"' | head -1 | cut -d'"' -f4)
if [ -n "$WH_URL" ]; then
  echo "$WH_URL" | mask | sed 's/^/     url: /'
  echo "$WH_URL" | grep -q "page=evo_webhook" \
    && record "Webhook URL correct" PASS "routes through public.php" \
    || record "Webhook URL correct" FAIL "does not use public.php?page=evo_webhook"
  echo "$WH_JSON" | grep -qi "MESSAGES_UPSERT" \
    && record "MESSAGES_UPSERT enabled" PASS "subscribed" \
    || record "MESSAGES_UPSERT enabled" FAIL "not in the event list"
  record "Webhook configured" PASS "set on the instance"
else
  record "Webhook configured"     FAIL "none set on $INSTANCE"
  record "Webhook URL correct"    SKIP "not configured"
  record "MESSAGES_UPSERT enabled" SKIP "not configured"
fi

if [ "$SET_WEBHOOK" = "1" ] && [ "$GO" = "1" ] && [ "$NOTOK" != "401" ]; then
  say "!! not registering the webhook: the endpoint answered ${NOTOK:-nothing}, not 401."
  say "   Evolution would be pointed at something that cannot receive messages."
  say "   Fix the row marked FIX above, then re-run."
elif [ "$SET_WEBHOOK" = "1" ]; then
  SECRET=$(docker exec "$UCRM_CT" sh -c "cat /data/ucrm/data/plugins/$PLUGIN/data/webhook_secret 2>/dev/null || true" 2>/dev/null | tr -d '\r\n')
  if [ -z "$SECRET" ]; then
    say "!! no webhook secret yet — open the plugin page once so it generates one"
  else
    RESP=$(curl -s --max-time 20 -X POST "$EVO_URL/webhook/set/$INSTANCE" \
      -H "apikey: $EVO_KEY" -H 'Content-Type: application/json' \
      -d "{\"webhook\":{\"enabled\":true,\"url\":\"$WH_BASE&token=$SECRET\",\"byEvents\":false,\"base64\":false,\"events\":[\"MESSAGES_UPSERT\",\"MESSAGES_UPDATE\",\"CONNECTION_UPDATE\"]}}")
    echo "$RESP" | mask | head -c 400 | sed 's/^/     /'; echo
  fi
fi

if [ "$GO" = "1" ] && [ "$NOTOK" = "401" ]; then
  head2 "5b. Re-check after registering"
  sleep 3
  RE=$(curl -s --max-time 15 -H "apikey: $EVO_KEY" "$EVO_URL/webhook/find/$INSTANCE" 2>&1)
  echo "$RE" | grep -oE '"url":"[^"]*"' | head -1 | mask | sed 's/^/     now set to: /'
  echo "$RE" | grep -q "page=evo_webhook" \
    && say "registered correctly" \
    || say "!! registration did not stick — see the response above"
fi

# ── 6. queue and worker ──────────────────────────────────────────────────────
head2 "6. Queue and worker"
if [ -n "$UCRM_CT" ]; then
  Q=$(docker exec "$UCRM_CT" php -r '
    $d="/data/ucrm/data/plugins/'"$PLUGIN"'/data/plugin.sqlite3";
    if(!file_exists($d)){echo "NODB";exit;}
    $p=new PDO("sqlite:$d");
    $o=[]; foreach($p->query("SELECT status,COUNT(*) c FROM events WHERE event_type=\"ai.reply\" GROUP BY status") as $r) $o[]=$r["status"].":".$r["c"];
    echo $o?implode(" ",$o):"empty";
  ' 2>/dev/null)
  say "ai.reply queue: ${Q:-unreadable}"
  [ "$Q" = "NODB" ] && record "Plugin database" FAIL "plugin.sqlite3 missing — open the plugin page once" \
                    || record "Plugin database" PASS "readable"

  SEEN=$(docker exec "$UCRM_CT" php -r '
    $d="/data/ucrm/data/plugins/'"$PLUGIN"'/data/plugin.sqlite3";
    if(!file_exists($d)){echo 0;exit;}
    $p=new PDO("sqlite:$d");
    echo (int)$p->query("SELECT COUNT(*) FROM evo_webhook_seen")->fetchColumn();
  ' 2>/dev/null)
  say "webhook messages ever received: ${SEEN:-0}"
  [ "${SEEN:-0}" -gt 0 ] 2>/dev/null \
    && record "Evolution -> Hybrid" PASS "$SEEN inbound message(s) recorded" \
    || record "Evolution -> Hybrid" FAIL "nothing has ever arrived"

  EXEC_OK=$(docker exec "$UCRM_CT" php -r 'var_export(function_exists("exec"));' 2>/dev/null)
  say "exec() available: $EXEC_OK"
  [ "$EXEC_OK" = "true" ] && record "Worker can spawn" PASS "exec() available" \
                          || record "Worker can spawn" FAIL "exec() blocked"

  CRON=$(crontab -l 2>/dev/null | grep -c 'master.php')
  say "master.php crontab entries: $CRON"
  [ "$CRON" -gt 0 ] && record "Scheduler (cron)" PASS "installed" \
                    || record "Scheduler (cron)" FAIL "not installed — fallback is uCRM's 5-minute schedule"

  say "--- last 15 lines of the plugin log ---"
  docker exec "$UCRM_CT" sh -c "tail -15 /data/ucrm/data/plugins/$PLUGIN/data/ai_platform.log 2>/dev/null || echo '(no log yet)'" 2>/dev/null | mask | sed 's/^/     /'
fi

# ── 7. AI provider ───────────────────────────────────────────────────────────
head2 "7. AI provider"
if [ -n "$UCRM_CT" ]; then
  AI=$(docker exec "$UCRM_CT" php -r '
    $r="/data/ucrm/data/plugins/'"$PLUGIN"'/";
    $c=[]; foreach([$r."data/config.json",$r."data/kyc_config.json"] as $f)
      if(is_file($f)){$d=json_decode(file_get_contents($f),true); if(is_array($d))$c=array_merge($c,$d);}
    $p=($c["ai_provider"]??"claude")==="openai"?"openai":"claude";
    $k=trim((string)($c[$p==="openai"?"openai_api_key":"claude_api_key"]??""));
    echo $p."|".($k!==""?"set":"missing")."|".strlen($k);
  ' 2>/dev/null)
  PROV=$(echo "$AI" | cut -d'|' -f1); KEYST=$(echo "$AI" | cut -d'|' -f2)
  say "provider: $PROV   key: $KEYST"
  if [ "$KEYST" = "set" ]; then
    HOST=$([ "$PROV" = "openai" ] && echo "https://api.openai.com/v1/models" || echo "https://api.anthropic.com/v1/messages")
    RC=$(docker exec "$UCRM_CT" sh -c "curl -s -o /dev/null -w '%{http_code}' --max-time 15 '$HOST'" 2>/dev/null)
    say "reachable from uCRM container: HTTP ${RC:-no answer}"
    [ "${RC:-0}" -gt 0 ] 2>/dev/null && record "AI provider reachable" PASS "HTTP $RC (401 = reachable)" \
                                     || record "AI provider reachable" FAIL "no answer"
    record "AI provider key" PASS "$PROV key set"
  else
    record "AI provider key"       FAIL "no $PROV key — set it on the plugin Configuration screen"
    record "AI provider reachable" SKIP "no key"
  fi
fi

# ── 8. outbound send ─────────────────────────────────────────────────────────
head2 "8. Outbound send"
if [ -n "$SEND_TEST" ]; then
  OUT=$(curl -s --max-time 25 -X POST "$EVO_URL/message/sendText/$INSTANCE" \
    -H "apikey: $EVO_KEY" -H 'Content-Type: application/json' \
    -d "{\"number\":\"$SEND_TEST\",\"text\":\"Test from DishNet Hybrid\"}")
  echo "$OUT" | mask | head -c 400 | sed 's/^/     /'; echo
  echo "$OUT" | grep -qE '"key"|"messageTimestamp"|PENDING|SUCCESS' \
    && record "Hybrid -> Evolution -> WhatsApp" PASS "message accepted" \
    || record "Hybrid -> Evolution -> WhatsApp" FAIL "$(echo "$OUT" | head -c 160)"
else
  say "skipped — re-run with:  --send-test 249XXXXXXXXX"
  record "Hybrid -> Evolution -> WhatsApp" SKIP "not tested"
fi

# ── table ────────────────────────────────────────────────────────────────────
head2 "RESULT"
printf '   %-34s %-6s %s\n' "COMPONENT" "STATUS" "DETAIL"
printf '   %s\n' "------------------------------------------------------------------------"
FAILS=0
for r in "${ROWS[@]}"; do
  C="${r%%|*}"; REST="${r#*|}"; S="${REST%%|*}"; D="${REST#*|}"
  case "$S" in
    PASS) M="OK" ;;
    FAIL) M="FIX"; FAILS=$((FAILS+1)) ;;
    *)    M="--" ;;
  esac
  printf '   %-34s %-6s %s\n' "$C" "$M" "$D"
done
printf '\n   %d item(s) to fix.\n\n' "$FAILS"

if [ "$GO" = "1" ]; then
  printf '   --go finished. If every row above says OK, send "Hello" to the number\n'
  printf '   and watch:  docker logs -f %s 2>&1 | grep -i webhook\n\n' "${EVO_CT:-<evolution>}"
fi

printf '   Next, in order:\n'
printf '     1. Create %s in the Evolution manager if it is missing\n' "$INSTANCE"
printf '     2. Scan its QR from the plugin (WhatsApp AI tab)\n'
printf '     3. bash %s --fix-traefik      (if the domain row says FIX)\n' "$(basename "$0")"
printf '     4. bash %s --set-webhook\n' "$(basename "$0")"
printf '     5. Send "Hello" to the number, then re-run this script\n\n'

printf '   Watch webhooks arrive live:\n'
printf '     docker logs -f %s 2>&1 | grep -i webhook\n\n' "${EVO_CT:-<evolution-container>}"
exit 0
