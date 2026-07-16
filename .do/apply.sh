#!/usr/bin/env bash
# Apply .do/app.yaml to DigitalOcean App Platform with secrets injected at apply time.
#
# Why this exists: the App Platform spec is DECLARATIVE. `doctl apps update --spec .do/app.yaml`
# with the committed file would delete every secret env var, because the committed file has none.
# So we render spec + secrets into a temp file, apply that, and shred it. Nothing secret is ever
# printed, and nothing secret is ever committed.
#
# Secrets live in .env.do (gitignored). First run generates them; later runs reuse them, so this
# script is idempotent and safe to re-run.
#
# Usage:
#   .do/apply.sh create   # first time: creates the app
#   .do/apply.sh update   # subsequent: updates the live spec in place
set -euo pipefail

cd "$(dirname "$0")/.."
ACTION="${1:-update}"
ENV_FILE=".env.do"

if [ ! -f "$ENV_FILE" ]; then
    echo "==> $ENV_FILE not found, generating production secrets"
    # Generated locally and written straight to the gitignored file: never echoed, never committed.
    # APP_KEY must match Laravel's expected base64:<32 raw bytes> shape.
    umask 077
    {
        echo "APP_KEY=base64:$(python3 -c 'import os,base64; print(base64.b64encode(os.urandom(32)).decode())')"
        echo "REVERB_APP_ID=$(python3 -c 'import secrets; print(secrets.randbelow(900000)+100000)')"
        echo "REVERB_APP_KEY=$(python3 -c 'import secrets; print(secrets.token_hex(16))')"
        echo "REVERB_APP_SECRET=$(python3 -c 'import secrets; print(secrets.token_hex(32))')"
    } >"$ENV_FILE"
    echo "==> wrote $ENV_FILE (gitignored). Back it up: losing APP_KEY makes existing encrypted data unreadable."
fi

# Render into the private temp dir, not /tmp, and always clean up even on failure.
RENDERED="$(mktemp)"
trap 'rm -f "$RENDERED"' EXIT

python3 - "$ENV_FILE" "$RENDERED" <<'PY'
import sys, yaml

env_file, out_file = sys.argv[1], sys.argv[2]

# Only these keys are allowed through, so a stray line in .env.do can never become an app env var.
SECRETS = ("APP_KEY", "REVERB_APP_ID", "REVERB_APP_KEY", "REVERB_APP_SECRET")

values = {}
for line in open(env_file):
    line = line.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    k, v = line.split("=", 1)
    if k.strip() in SECRETS:
        values[k.strip()] = v.strip()

missing = [k for k in SECRETS if k not in values]
if missing:
    sys.exit(f"{env_file} is missing: {', '.join(missing)}")

spec = yaml.safe_load(open(".do/app.yaml"))
spec.setdefault("envs", []).extend(
    {"key": k, "value": values[k], "type": "SECRET", "scope": "RUN_TIME"} for k in SECRETS
)

yaml.safe_dump(spec, open(out_file, "w"), sort_keys=False)
PY

echo "==> ${ACTION}ing app from rendered spec (4 secrets injected)"
if [ "$ACTION" = "create" ]; then
    fw-do apps create --spec "$RENDERED" --format ID,Spec.Name,DefaultIngress
else
    APP_ID="$(fw-do apps list --format ID,Spec.Name --no-header | awk '$2=="familywell"{print $1}')"
    [ -n "$APP_ID" ] || { echo "no app named 'familywell' found; run: $0 create"; exit 1; }
    fw-do apps update "$APP_ID" --spec "$RENDERED" --format ID,Spec.Name,DefaultIngress
fi
