#!/bin/sh
# Fetches the model artifact the YOLO detector serves, into the artifacts directory the ML
# container mounts read-only.
#
# The download is pinned to a release asset and its SHA-256, so every clone that runs this
# gets the same bytes. An artifact that is already there is kept, and one that does not
# match the pin is never replaced: the fetch fails and says so instead.
#
# Run through the stack: docker compose --profile artifacts run --rm ml-artifacts
set -eu

: "${MODEL_URL:?MODEL_URL must name the artifact to fetch}"
: "${MODEL_SHA256:?MODEL_SHA256 must pin the artifact's checksum}"
# The artifact keeps the name it has in the release, which is the name ML_MODEL_PATH
# points at by default.
: "${MODEL_NAME:=$(basename "${MODEL_URL}")}"
: "${ARTIFACT_DIR:=/artifacts}"

target="${ARTIFACT_DIR}/${MODEL_NAME}"

if [ -f "${target}" ]; then
    # Short options: the check runs in the curl image, whose BusyBox sha256sum has no
    # long ones.
    if echo "${MODEL_SHA256}  ${target}" | sha256sum -c -s -; then
        echo "${target} is already there and matches ${MODEL_SHA256}"
        exit 0
    fi

    echo "error: ${target} exists but does not match ${MODEL_SHA256}; remove it or pin the artifact you meant." >&2
    exit 1
fi

echo "fetching ${MODEL_URL}"
mkdir -p "${ARTIFACT_DIR}"

downloaded="${target}.download"
trap 'rm -f "${downloaded}"' EXIT INT TERM
curl --fail --location --silent --show-error --retry 3 --output "${downloaded}" "${MODEL_URL}"

if ! echo "${MODEL_SHA256}  ${downloaded}" | sha256sum -c -s -; then
    echo "error: the fetched artifact does not match ${MODEL_SHA256}; nothing was installed." >&2
    exit 1
fi

mv "${downloaded}" "${target}"
trap - EXIT INT TERM

echo "fetched ${target} (${MODEL_SHA256})"
