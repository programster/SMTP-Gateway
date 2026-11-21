#!/usr/bin/env bash
#
# Runs every test suite, each against a fresh, throwaway test stack of its own, several at once. Each stack is the
# development stack (docker-dev-compose.yml) under its own project name and ports, with the settings in tests/test.env
# and any its suite changes, its own settings.yaml, its own logs and its own state (see
# docker-compose.tests.yml). They never send real email, and can run alongside your own development stack.
#
# The suites are put in a queue (programster/pgsql-task-queue, in a PostgreSQL container of its own for the run),
# longest first, and the runner's workers (tests/runner) each take the next suite, start a fresh stack for it, run it,
# and remove the stack, so a suite never shares a stack with another, and no worker sits idle while there are suites
# left. Most of the suites' time is spent waiting on real rate limit windows, so this takes about as long as the
# slowest suite, plus its stack's start up.
#
# The runner runs in a container (tests/runner/Dockerfile), with the host's Docker socket and network, and the
# repository mounted at the same path as here, so the stacks' bind mounts and ports are the same as if it ran here.
#
# Usage: tests/run-tests.sh [--workers=N] [--keep] [--load]
#   --workers=N  run N stacks at once (12 by default).
#   --keep       leave the test stacks running at the end, e.g. to look around one, or re-run a suite (see the README).
#   --load       also run the load test (tests/load/load_test.py), on a stack of its own.
#   --remove     just remove the test stacks left running by --keep.
#
# Needs Docker (with Compose 2.24 or later), with its socket at /var/run/docker.sock.

set -uo pipefail

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$TESTS_DIR")"
RUN_DIR="$TESTS_DIR/.run"
RUNNER_IMAGE="smtpapitest-runner"
QUEUE_CONTAINER="smtpapitest-queue"
QUEUE_PORT="15499"
QUEUE_PASSWORD="test-queue-password"
IS_KEEPING=false
IS_REMOVING=false
RUNNER_ARGUMENTS=()

for argument in "$@"; do
    case "$argument" in
        --keep) IS_KEEPING=true; RUNNER_ARGUMENTS+=("$argument") ;;
        --load|--workers=*) RUNNER_ARGUMENTS+=("$argument") ;;
        --remove) IS_REMOVING=true ;;
        *) echo "Unknown option: $argument (expected --workers=N, --keep, --load or --remove)" >&2; exit 2 ;;
    esac
done


# Remove every test stack: each compose project whose name starts with "smtpapitest-", with its volumes.
remove_stacks() {
    local project

    for project in $(docker compose ls --all --quiet --filter name=smtpapitest- 2>/dev/null); do
        docker compose -p "$project" down -v --remove-orphans > /dev/null 2>&1 &
    done

    wait
}


remove_queue() {
    docker rm -f -v "$QUEUE_CONTAINER" > /dev/null 2>&1
}


# At the end (or on Ctrl-C), remove the queue, and the stacks unless they're being kept.
clean_up() {
    remove_queue

    if [[ "$IS_KEEPING" == "false" ]]; then
        remove_stacks
    fi
}


if [[ "$IS_REMOVING" == "true" ]]; then
    remove_stacks
    remove_queue
    rm -rf "$RUN_DIR"
    echo "Removed the test stacks."
    exit 0
fi

# Start from nothing: no stacks or queue left over from an earlier run.
remove_stacks
remove_queue
rm -rf "$RUN_DIR"
mkdir -p "$RUN_DIR/build"
trap clean_up EXIT

# Every stack uses the same app image, so it's built once, alongside the runner's image and the queue's database.
echo "Building the images, and starting the queue..."
cp "$TESTS_DIR/test.env" "$RUN_DIR/build/stack.env"
printf 'TEST_STACK="build"\nCOMPOSE_PROJECT_NAME="smtpapitest-build"\n' >> "$RUN_DIR/build/stack.env"

docker compose \
    --project-directory "$ROOT_DIR" \
    --env-file "$RUN_DIR/build/stack.env" \
    -f "$ROOT_DIR/docker-dev-compose.yml" \
    -f "$TESTS_DIR/docker-compose.tests.yml" \
    build app > "$RUN_DIR/build/app.log" 2>&1 &
app_build=$!

docker build -t "$RUNNER_IMAGE" "$TESTS_DIR/runner" > "$RUN_DIR/build/runner.log" 2>&1 &
runner_build=$!

docker run -d --name "$QUEUE_CONTAINER" \
    -e POSTGRES_USER=queue -e POSTGRES_PASSWORD="$QUEUE_PASSWORD" -e POSTGRES_DB=queue \
    -p "127.0.0.1:$QUEUE_PORT:5432" \
    postgres:18-trixie > "$RUN_DIR/build/queue.log" 2>&1

for image_build in "$app_build:app" "$runner_build:runner"; do
    if ! wait "${image_build%%:*}"; then
        cat "$RUN_DIR/build/${image_build#*:}.log" >&2
        exit 1
    fi
done

# The queue is ready once it accepts connections over TCP, as PostgreSQL's image only listens on its socket while it's
# first setting the database up.
for _ in $(seq 1 30); do
    docker exec "$QUEUE_CONTAINER" pg_isready -h 127.0.0.1 -U queue -d queue > /dev/null 2>&1 && break
    sleep 1
done

docker run --rm \
    --network host \
    --user "$(id -u):$(id -g)" \
    --group-add "$(stat -c %g /var/run/docker.sock)" \
    -v /var/run/docker.sock:/var/run/docker.sock \
    -v "$ROOT_DIR:$ROOT_DIR" \
    -w "$ROOT_DIR" \
    -e HOME=/tmp \
    -e QUEUE_DB_HOST=127.0.0.1 \
    -e QUEUE_DB_PORT="$QUEUE_PORT" \
    -e QUEUE_DB_NAME=queue \
    -e QUEUE_DB_USER=queue \
    -e QUEUE_DB_PASSWORD="$QUEUE_PASSWORD" \
    "$RUNNER_IMAGE" php tests/runner/run.php "${RUNNER_ARGUMENTS[@]}"
