# Development

```bash
docker compose -f docker-dev-compose.yml up
```

This is for testing the gateway without sending any real email. It uses `config-dev/settings.yaml`, whose
providers relay every email to one of two [Mailpit](https://mailpit.axllent.org) containers (the
maintained successor to MailHog), which catch them all: the primary at http://localhost:8025, and the
secondary at http://localhost:8026. Both have Mailpit's **Chaos** turned on, so either can be made to
refuse emails with an error code of your choosing (in its web interface, or with `PUT /api/v1/chaos`),
to try the overflow and fallback modes.

It bind-mounts `site/`, so run `composer install` in `site/` on the host first. It also runs the
TypeScript compiler in watch mode (`site/ts` to `site/public/js`).

The database tables are created by the migrations in `site/database/migrations`, which run on every
start up.

## Publishing the image

`docker-compose.yml` runs the image named by `DOCKER_IMAGE_NAME`, which `.env.example` sets to the
published image for the release, e.g. `programster/smtp-gateway:0.5.0`, so each release is built and
pushed to Docker Hub. To publish a release:

1. Change the tag in `.env.example`'s `DOCKER_IMAGE_NAME` to the new release's, so a fresh install of
   the release runs it, and commit that.
2. Tag the commit with the release's version (e.g. `0.5.0`).
3. Log in to Docker Hub (`docker login`), and run:

```bash
./publish-image.sh
```

It pushes to the repository in your `.env`'s `DOCKER_IMAGE_NAME` (or `.env.example`'s, without a
`.env`), whatever its tag, so you can publish to a registry of your own. It asks which tags to publish
as, suggesting the release's version, its minor version and `latest` (e.g. `0.5.0 0.5 latest`) when
the commit has a version's git tag, and shows what it will push and asks again before building. It
refuses to run when the working tree has changes or untracked files, as they'd go into the image without
being in the release, or when the tag in `.env.example`'s `DOCKER_IMAGE_NAME` isn't one of the tags
given.

Anyone who'd rather build the image themselves can use `docker-compose.build.yml` instead, as the
[README](../README.md#quick-start) says.

## Tests

```bash
tests/run-tests.sh
```

This runs every suite against a fresh, throwaway test stack of its own, 8 at once, then removes them.
It needs Docker (with Compose 2.24 or later, and its socket at `/var/run/docker.sock`), and
`composer install` run in `site/`. Everything else (PHP for the runner, and Python for the integration
tests) is in the runner's own image, `tests/runner/Dockerfile`, which it builds. Most of the time is
spent waiting on real rate limit windows rather than working, so it takes about as long as the slowest
suite, plus its stack's start up (about 20 seconds).

The suites are put in a queue ([programster/pgsql-task-queue](https://github.com/programster/package-pgsql-task-queue),
in a PostgreSQL container of its own for the run), the longest first, and the runner's workers
(`tests/runner`) each take the next suite, start a fresh stack for it, run it, and remove the stack.
So a suite never shares a stack with another, as they change things the whole stack shares, such as
the global rate limits, `settings.yaml`, the Mailpit containers and the admin's password, and no
worker sits idle while there are suites left. Each stack:

* is the development stack, under its own project name (`smtpapitest-e2e`, `smtpapitest-providers`,
  and so on, after its suite) and ports (from 18180 for the first suite's web interface, 100 apart for
  each suite),
* takes its settings from `tests/test.env`, not your `.env`, with any its suite changes, and
* has its own `settings.yaml` (from `tests/settings.yaml`, with its suite's retention settings) and its
  own logs, state and output, in
  `tests/.run/<suite>`, along with `result.json`, saying how long its stack and tests took.

So they never send real email, and can run alongside your own development stack. The `no-providers`
suite's stack starts with no `settings.yaml`, as a fresh install does. Three of the suites have their
retention settings changed: `content-retention-kept` and `attachments-not-kept` keep emails'
content for 30 days without their attachments, and `attachments-kept` keeps the attachments for 7 days.
The suites, their settings and how long each takes are listed in `tests/runner/run.php`.

There are two kinds of test:

* **Integration tests** (`tests/integration`, in Python with only its standard library) test the
  service from the outside, through the web interface, the API and the SMTP server.
* **Unit tests** (`tests/unit`, in PHP) test classes directly, and run inside the app container.

The Google login test sends a made-up code to Google, which refuses it. Without internet access it
fails to reach Google instead, which the service refuses in the same way.

Options:

* `--workers=N` runs N stacks at once, rather than 8. Each stack is 4 containers.
* `--keep` leaves the test stacks running at the end, so you can look around one, or run a suite
  again, e.g. `cd tests && set -a && . .run/providers/stack.env && set +a &&
  TEST_STATE_DIR=.run/providers/state python3 -m integration.providers` (which needs Python 3.10 or
  later on the host). Remove them with `tests/run-tests.sh --remove`. Most suites can be run again on
  their own, except `e2e`, which expects a fresh stack.
* `--load` also runs a load test, on a stack of its own: 30 SMTP connections at once, each sending 10
  emails (`LOAD_TEST_EMAILS_PER_CONNECTION`), alongside 16 API clients, reporting the time taken and
  the containers' memory use.
