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
# The GHCR pull credential is likewise injected here, from `gh auth token`, never committed.
#
# Usage:
#   .do/apply.sh create                   # first time: creates the app
#   .do/apply.sh update                   # subsequent: updates the live spec in place
#   DO_TAG=fe3fa94 .do/apply.sh update    # pin a specific image tag instead of the spec's
#
# DO_TAG exists so a deploy can be pinned to an exact image (build one with .do/push.sh) before the
# branch merges. Leave it unset and you get the spec's tag (latest).
set -euo pipefail

cd "$(dirname "$0")/.."
ACTION="${1:-update}"
ENV_FILE=".env.do"

# DO_TAG rewrites the live spec to a fixed tag. The GitHub Actions CD workflow redeploys :latest and
# never touches the spec, so a spec pinned to some other tag makes every future auto-deploy a no-op
# against a stale image. Warn loudly; reset by re-running with DO_TAG unset.
if [ -n "${DO_TAG:-}" ]; then
    echo "==> WARNING: DO_TAG=$DO_TAG pins the live spec off :latest and disables CD auto-deploy" >&2
    echo "==>          re-run '.do/apply.sh update' with DO_TAG unset to restore latest-tracking" >&2
fi

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

# GHCR is private, so App Platform needs a pull credential. Taken from the gh CLI at apply time
# (it already carries read:packages) rather than stored anywhere.
GHCR_CREDS="bvdr:$(gh auth token)"
export GHCR_CREDS

python3 - "$ENV_FILE" "$RENDERED" "${DO_TAG:-}" <<'PY'
import os, sys, yaml

env_file, out_file, tag = sys.argv[1], sys.argv[2], sys.argv[3]

# Only these keys are allowed through, so a stray line in .env.do can never become an app env var.
SECRETS = ("APP_KEY", "REVERB_APP_ID", "REVERB_APP_KEY", "REVERB_APP_SECRET", "MAIL_PASSWORD", "R2_ACCESS_KEY_ID", "R2_SECRET_ACCESS_KEY", "GEMINI_API_KEY")

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

# Every component (web, reverb, migrate, scheduler) must run the same image, or the pre-deploy
# migration could apply a different schema than the code that then serves it. Workers are included:
# a worker with no registry_credentials cannot pull the private GHCR image, which fails the whole
# deploy with "Image does not exist or is private" on any fresh pull.
for component in (*spec.get("services", []), *spec.get("jobs", []), *spec.get("workers", [])):
    image = component.get("image")
    if not image:
        continue
    image["registry_credentials"] = os.environ["GHCR_CREDS"]
    if tag:
        image["tag"] = tag

yaml.safe_dump(spec, open(out_file, "w"), sort_keys=False)
PY

echo "==> ${ACTION}ing app from rendered spec (secrets + GHCR credentials injected)"
if [ "$ACTION" = "create" ]; then
    fw-do apps create --spec "$RENDERED" --format ID,Spec.Name,DefaultIngress
else
    APP_ID="$(fw-do apps list --format ID,Spec.Name --no-header | awk '$2=="familywell"{print $1}')"
    [ -n "$APP_ID" ] || { echo "no app named 'familywell' found; run: $0 create"; exit 1; }
    fw-do apps update "$APP_ID" --spec "$RENDERED" --format ID,Spec.Name,DefaultIngress
fi
