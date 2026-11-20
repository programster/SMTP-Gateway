#!/usr/bin/env bash
# Builds the app's image from this checkout, and pushes it to the repository in DOCKER_IMAGE_NAME (from .env, or
# .env.example without one), whatever its tag, with the tags you choose. It asks for the tags (suggesting the
# release's version, its minor version and "latest" when HEAD is at a release's git tag), and asks again before
# building or pushing anything.
#
# It refuses to run when:
#  - the working tree has changes, or untracked files, as they'd go into the image without being in any release, or
#  - the tag in .env.example's DOCKER_IMAGE_NAME isn't one of the tags being published, as a fresh install of this
#    release would then run some other image.
#
# Log in first, with "docker login".
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

# Print DOCKER_IMAGE_NAME's value from an env file (without its quotes), or nothing if it isn't set there.
readImageName()
{
    sed -n 's/^DOCKER_IMAGE_NAME=["'"'"']\{0,1\}\([^"'"'"']*\)["'"'"']\{0,1\} *$/\1/p' "$1" | tail -n 1
}

# Print an image name without its tag. A ":" only starts the tag after the last "/", as a registry's port
# (e.g. registry.example.org:5000/smtp-gateway) has one too.
withoutTag()
{
    local name="$1"
    local lastPart="${name##*/}"

    if [[ "$lastPart" == *:* ]]; then
        echo "${name%:*}"
    else
        echo "$name"
    fi
}

if [ -f .env ]; then
    imageNameSource=".env"
else
    imageNameSource=".env.example"
fi

imageName="$(readImageName "$imageNameSource")"

if [ -z "$imageName" ]; then
    echo "${imageNameSource} doesn't set DOCKER_IMAGE_NAME, so there's no repository to push to." >&2
    exit 1
fi

REPOSITORY="$(withoutTag "$imageName")"

# Without a "/", Docker Hub takes the name as one of its official images (library/<name>), which can't be pushed to.
if [[ "$REPOSITORY" != */* ]]; then
    echo "${imageNameSource}'s DOCKER_IMAGE_NAME (\"${imageName}\") has no Docker Hub username or registry," >&2
    echo "e.g. yourname/smtp-gateway:0.5.0, so there's nowhere it can be pushed to." >&2
    exit 1
fi

if [ -n "$(git status --porcelain)" ]; then
    echo "The working tree has uncommitted changes or untracked files, which would go into the image:" >&2
    git status --short >&2
    echo "Commit or remove them (or check out the release's git tag) first." >&2
    exit 1
fi

releaseImageName="$(readImageName .env.example)"
releaseTag="${releaseImageName#"$(withoutTag "$releaseImageName")"}"
releaseTag="${releaseTag#:}"

if [ -z "$releaseTag" ]; then
    echo ".env.example's DOCKER_IMAGE_NAME (\"${releaseImageName}\") has no tag, e.g. :0.5.0." >&2
    exit 1
fi

# Suggest the release's tags when HEAD is at a git tag that's a version, e.g. 0.5.0 suggests "0.5.0 0.5 latest".
gitTag="$(git describe --tags --exact-match 2>/dev/null || true)"

if [[ "$gitTag" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    suggestion="${gitTag} ${gitTag%.*} latest"
else
    suggestion=""
    echo "Note: HEAD isn't at a release's git tag (e.g. 0.5.0), so there's no suggestion."
fi

echo "Commit: $(git log -1 --format='%h %s')"
echo "Pushing to: ${REPOSITORY} (from ${imageNameSource})"
echo ".env.example runs tag: ${releaseTag}"
echo

if [ -n "$suggestion" ]; then
    read -r -p "Tags to publish, separated by spaces [${suggestion}]: " tagsInput
    tagsInput="${tagsInput:-$suggestion}"
else
    read -r -p "Tags to publish, separated by spaces (e.g. 0.5.0 0.5 latest): " tagsInput
fi

read -r -a tags <<< "$tagsInput"

if [ "${#tags[@]}" -eq 0 ]; then
    echo "No tags given, so nothing was published." >&2
    exit 1
fi

includesReleaseTag=false

for tag in "${tags[@]}"; do
    # Docker's rule for a tag: letters, digits, "_", "." and "-", not starting with "." or "-", up to 128 characters.
    if ! [[ "$tag" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$ ]]; then
        echo "\"${tag}\" isn't a valid tag, so nothing was published." >&2
        exit 1
    fi

    if [ "$tag" = "$releaseTag" ]; then
        includesReleaseTag=true
    fi
done

if [ "$includesReleaseTag" != true ]; then
    echo ".env.example's DOCKER_IMAGE_NAME has the tag ${releaseTag}, which isn't one of the tags given," >&2
    echo "so a fresh install of this release wouldn't run this image. Change its tag, or include ${releaseTag}." >&2
    exit 1
fi

echo
echo "This will build the image from this checkout and push it as:"

for tag in "${tags[@]}"; do
    echo "  ${REPOSITORY}:${tag}"
done

read -r -p "Continue? [y/N] " confirmation

if [[ ! "$confirmation" =~ ^[Yy]$ ]]; then
    echo "Nothing was published."
    exit 1
fi

tagArguments=()

for tag in "${tags[@]}"; do
    tagArguments+=(-t "${REPOSITORY}:${tag}")
done

# The labels that describe this build (see the end of the Dockerfile). The version is the release's, from .env.example,
# as it's always one of the tags published.
buildArguments=(
    --build-arg "IMAGE_VERSION=${releaseTag}"
    --build-arg "IMAGE_REVISION=$(git rev-parse HEAD)"
    --build-arg "IMAGE_CREATED=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
)

# --pull gets the latest base images, so the published image has their latest security fixes.
docker build --pull "${buildArguments[@]}" "${tagArguments[@]}" .

for tag in "${tags[@]}"; do
    docker push "${REPOSITORY}:${tag}"
done

echo
echo "Published:"

for tag in "${tags[@]}"; do
    echo "  ${REPOSITORY}:${tag}"
done
