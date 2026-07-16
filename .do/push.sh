#!/usr/bin/env bash
# Build the production image and push it to GHCR, tagged with the current commit.
#
# This exists only because App Platform cannot build from the private repo directly (see the header
# of .do/app.yaml). Once DigitalOcean's GitHub app is authorized against bvdr/familywell, this whole
# script goes away and App Platform builds on push instead.
#
# Usage:
#   .do/push.sh                       # build + push :<short-sha> and :latest
#   .do/push.sh && .do/apply.sh update   # ship it
set -euo pipefail

cd "$(dirname "$0")/.."
IMAGE="ghcr.io/bvdr/familywell"
TAG="$(git rev-parse --short HEAD)"

if ! git diff --quiet HEAD; then
    # The tag names a commit, so a dirty tree would produce an image that tag lies about.
    echo "working tree is dirty; commit first so :$TAG matches what is actually in the image" >&2
    exit 1
fi

echo "==> building $IMAGE:$TAG"
docker build -t "$IMAGE:$TAG" -t "$IMAGE:latest" .

# --password-stdin: the token is never a process argument and never hits the terminal.
gh auth token | docker login ghcr.io -u bvdr --password-stdin >/dev/null

echo "==> pushing $IMAGE:$TAG and :latest"
docker push -q "$IMAGE:$TAG"
docker push -q "$IMAGE:latest"

echo "==> pushed. Deploy it with:  DO_TAG=$TAG .do/apply.sh update"
