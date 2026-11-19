<?php

/*
 * Creates the whole schema: the web interface's accounts (with their second factors and password reset links), the
 * API keys for the management API, the email tokens other services send email with (with their restrictions, rate
 * limits and priority), the global rate limits, the emails sent through the service (with each attempt to send one,
 * and the events the providers report), the rejected requests, the providers' status, and the log.
 *
 * API keys and email tokens are each one string: a prefix, the ID, and the secret (see Token). The ID is the row's
 * primary key, e.g. "et_8af651aadbe3ff25", and is safe to show. Only the SHA-256 hash of the secret is stored.
 */

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlLogger\PgSqlLogger;


class InitialSchemaMigration implements Programster\PgsqlMigrations\MigrationInterface
{
    /**
     * Run queries for upgrading the database version.
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError - if there is an issue with the query.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionConnectionError - if database is not actually connected.
     */
    public function up(\PgSql\Connection $connectionResource) : void
    {
        $db = new PgSqlConnection($connectionResource);

        $queries = [
            // The accounts that can log in to the web interface, identified by their email address (stored in
            // lower case, and unique), which they log in with, and where password reset links go. Each can have
            // TOTP and/or security keys (WebAuthn) as a second factor. The TOTP secret is only set once it has been
            // confirmed with a code, and the last time step used is kept, so the same code can't be used twice.
            // session_version goes up whenever the password changes, which logs the account out everywhere else.
            'CREATE TABLE "admin_user" (
                "id" uuid NOT NULL PRIMARY KEY,
                "email" varchar(254) NOT NULL CHECK ("email" = lower("email")),
                "password_hash" text NOT NULL,
                "totp_secret" varchar(128) NULL,
                "totp_last_used_step" bigint NULL,
                "session_version" integer NOT NULL DEFAULT 0,
                "created_at" timestamptz NOT NULL DEFAULT now()
            )',

            'CREATE UNIQUE INDEX "admin_user_email_idx" ON "admin_user" ("email")',

            // Security keys (e.g. YubiKeys) registered with WebAuthn as a second factor for an admin user.
            'CREATE TABLE "admin_webauthn_credential" (
                "id" uuid NOT NULL PRIMARY KEY,
                "admin_user_id" uuid NOT NULL REFERENCES "admin_user" ("id") ON DELETE CASCADE,
                "name" varchar(100) NOT NULL,
                "credential_id" text NOT NULL UNIQUE,
                "public_key_pem" text NOT NULL,
                "signature_counter" bigint NOT NULL DEFAULT 0,
                "created_at" timestamptz NOT NULL DEFAULT now(),
                "last_used_at" timestamptz NULL
            )',

            // The links sent to reset a forgotten password. Only the SHA-256 hash of each token is stored, and each
            // can only be used once, before it expires.
            'CREATE TABLE "password_reset_token" (
                "id" uuid NOT NULL PRIMARY KEY,
                "admin_user_id" uuid NOT NULL REFERENCES "admin_user" ("id") ON DELETE CASCADE,
                "token_hash" char(64) NOT NULL UNIQUE,
                "client_ip" varchar(45) NOT NULL,
                "created_at" timestamptz NOT NULL DEFAULT now(),
                "expires_at" timestamptz NOT NULL,
                "used_at" timestamptz NULL
            )',

            'CREATE INDEX "password_reset_token_user_idx" ON "password_reset_token" ("admin_user_id", "created_at")',

            // The keys for the management API (/api/v1), used as bearer tokens, and the networks each may be used
            // from.
            'CREATE TABLE "api_key" (
                "id" varchar(19) NOT NULL PRIMARY KEY CHECK ("id" ~ \'^ak_[0-9a-f]{16}$\'),
                "name" varchar(255) NOT NULL,
                "secret_hash" char(64) NOT NULL,
                "created_at" timestamptz NOT NULL DEFAULT now(),
                "last_used_at" timestamptz NULL,
                "revoked_at" timestamptz NULL
            )',

            'CREATE TABLE "api_key_network" (
                "id" uuid NOT NULL PRIMARY KEY,
                "api_key_id" varchar(19) NOT NULL REFERENCES "api_key" ("id") ON DELETE CASCADE,
                "network" cidr NOT NULL,
                UNIQUE ("api_key_id", "network")
            )',

            // The tokens other services send email with, over SMTP (with the ID as the username, and the whole token
            // as the password) or the send endpoint (as a bearer token). priority is how important the token's
            // emails are: from 1 (the default) to 9998. A higher priority's emails are sent before a lower one's.
            'CREATE TABLE "email_token" (
                "id" varchar(19) NOT NULL PRIMARY KEY CHECK ("id" ~ \'^et_[0-9a-f]{16}$\'),
                "name" varchar(255) NOT NULL,
                "secret_hash" char(64) NOT NULL,
                "created_at" timestamptz NOT NULL DEFAULT now(),
                "last_used_at" timestamptz NULL,
                "revoked_at" timestamptz NULL,
                "priority" integer NOT NULL DEFAULT 1 CHECK ("priority" BETWEEN 1 AND 9998)
            )',

            'CREATE TABLE "email_token_network" (
                "id" uuid NOT NULL PRIMARY KEY,
                "email_token_id" varchar(19) NOT NULL REFERENCES "email_token" ("id") ON DELETE CASCADE,
                "network" cidr NOT NULL,
                UNIQUE ("email_token_id", "network")
            )',

            // Which addresses a token may send as (sender), use as the reply-to, and send to (recipient). A rule is
            // either anyone, a whole domain, or a single address.
            'CREATE TABLE "email_token_address_rule" (
                "id" uuid NOT NULL PRIMARY KEY,
                "email_token_id" varchar(19) NOT NULL REFERENCES "email_token" ("id") ON DELETE CASCADE,
                "field" varchar(20) NOT NULL CHECK ("field" IN (\'sender\', \'reply_to\', \'recipient\')),
                "kind" varchar(20) NOT NULL CHECK ("kind" IN (\'any\', \'domain\', \'address\')),
                "value" varchar(320) NULL,
                CHECK (("kind" = \'any\') = ("value" IS NULL)),
                UNIQUE NULLS NOT DISTINCT ("email_token_id", "field", "kind", "value")
            )',

            // A token's rate limits, each counting either emails (requests to the provider) or recipients.
            'CREATE TABLE "email_token_rate_limit" (
                "id" uuid NOT NULL PRIMARY KEY,
                "email_token_id" varchar(19) NOT NULL REFERENCES "email_token" ("id") ON DELETE CASCADE,
                "maximum" integer NOT NULL CHECK ("maximum" > 0),
                "period_seconds" integer NOT NULL CHECK ("period_seconds" > 0),
                "unit" varchar(20) NOT NULL DEFAULT \'emails\' CHECK ("unit" IN (\'emails\', \'recipients\')),
                UNIQUE ("email_token_id", "unit", "period_seconds")
            )',

            // The limits on everything sent, across every token.
            'CREATE TABLE "global_rate_limit" (
                "id" uuid NOT NULL PRIMARY KEY,
                "maximum" integer NOT NULL CHECK ("maximum" > 0),
                "period_seconds" integer NOT NULL CHECK ("period_seconds" > 0),
                "unit" varchar(20) NOT NULL DEFAULT \'emails\' CHECK ("unit" IN (\'emails\', \'recipients\')),
                UNIQUE ("unit", "period_seconds")
            )',

            // Every email accepted for sending. The raw message is base64 encoded, as an email doesn\'t have to be
            // valid UTF-8, and is only kept for as long as the retention settings say (its attachments for as long
            // as theirs say: attachments_removed_at is when they were removed, or it was found to have none).
            //  - provider is the name of the provider it was sent through.
            //  - tried_providers lists the providers already tried in the current round of attempts, so the next one
            //    is used, and retry_round counts the rounds, each of which tries every provider it can.
            //  - priority is the priority it was queued with: its token's, or 9999 for the service's own emails
            //    (e.g. password reset links), which go before everything else, and have no token.
            'CREATE TABLE "email_message" (
                "id" uuid NOT NULL PRIMARY KEY,
                "email_token_id" varchar(19) NULL REFERENCES "email_token" ("id"),
                "source" varchar(10) NOT NULL CHECK ("source" IN (\'smtp\', \'api\', \'system\')),
                "client_ip" inet NOT NULL,
                "envelope_sender" varchar(320) NOT NULL,
                "recipients" jsonb NOT NULL,
                "recipient_count" integer NOT NULL CHECK ("recipient_count" > 0),
                "subject" text NOT NULL DEFAULT \'\',
                "size_bytes" integer NOT NULL,
                "raw_message_base64" text NULL,
                "status" varchar(20) NOT NULL
                    CHECK ("status" IN (\'queued\', \'sending\', \'sent\', \'failed\', \'cancelled\')),
                "was_queued" boolean NOT NULL DEFAULT false,
                "attempts" integer NOT NULL DEFAULT 0,
                "next_attempt_at" timestamptz NOT NULL DEFAULT now(),
                "dispatched_at" timestamptz NULL,
                "sent_at" timestamptz NULL,
                "provider" varchar(50) NULL,
                "provider_message_id" varchar(255) NULL,
                "last_error" text NULL,
                "created_at" timestamptz NOT NULL DEFAULT now(),
                "tried_providers" jsonb NOT NULL DEFAULT \'[]\',
                "retry_round" integer NOT NULL DEFAULT 0,
                "attachments_removed_at" timestamptz NULL,
                "removed_attachment_count" integer NOT NULL DEFAULT 0,
                "priority" integer NOT NULL DEFAULT 1 CHECK ("priority" BETWEEN 1 AND 9999),
                CONSTRAINT "email_message_system_token_check"
                    CHECK (("source" = \'system\') = ("email_token_id" IS NULL))
            )',

            'CREATE INDEX "email_message_queue_idx" ON "email_message" ("next_attempt_at") WHERE "status" = \'queued\'',

            'CREATE INDEX "email_message_email_token_dispatched_idx"'
                . ' ON "email_message" ("email_token_id", "dispatched_at")',

            'CREATE INDEX "email_message_dispatched_idx" ON "email_message" ("dispatched_at")',
            'CREATE INDEX "email_message_created_idx" ON "email_message" ("created_at")',
            'CREATE INDEX "email_message_provider_message_idx" ON "email_message" ("provider_message_id")',
            'CREATE INDEX "email_message_provider_dispatched_idx" ON "email_message" ("provider", "dispatched_at")',

            // Finds the emails whose attachments are still to be checked, as they're looked for every five minutes,
            // and are only ever a few of the table's rows.
            'CREATE INDEX "email_message_attachments_pending_idx" ON "email_message" ("created_at")'
                . ' WHERE "raw_message_base64" IS NOT NULL AND "attachments_removed_at" IS NULL',

            // Each time an email is handed to a provider, so each provider's rate limits count every attempt it was
            // given, failed ones included, even after the email moved on to another provider. It's also each
            // email's history of attempts.
            'CREATE TABLE "email_attempt" (
                "id" uuid NOT NULL PRIMARY KEY,
                "email_message_id" uuid NOT NULL REFERENCES "email_message" ("id") ON DELETE CASCADE,
                "provider" varchar(50) NOT NULL,
                "recipient_count" integer NOT NULL CHECK ("recipient_count" > 0),
                "dispatched_at" timestamptz NOT NULL DEFAULT now(),
                "finished_at" timestamptz NULL,
                "outcome" varchar(20) NOT NULL CHECK ("outcome" IN (
                    \'sending\', \'sent\', \'temporary_failure\', \'refused\', \'unavailable\', \'interrupted\'
                )),
                "error" text NULL
            )',

            'CREATE INDEX "email_attempt_message_idx" ON "email_attempt" ("email_message_id", "dispatched_at")',

            // For the per-provider rate limits, which count a provider's attempts within a window.
            'CREATE INDEX "email_attempt_provider_idx" ON "email_attempt" ("provider", "dispatched_at")',

            // What happened to each email after it was handed to a provider, as reported by its webhook. provider is
            // the name of the provider, so a webhook's events are recorded against the right one.
            'CREATE TABLE "email_event" (
                "id" uuid NOT NULL PRIMARY KEY,
                "email_message_id" uuid NULL REFERENCES "email_message" ("id") ON DELETE CASCADE,
                "provider" varchar(50) NOT NULL,
                "provider_event_id" varchar(255) NULL,
                "event_type" varchar(30) NOT NULL CHECK ("event_type" IN (
                    \'processed\', \'delivered\', \'deferred\', \'bounced\', \'dropped\', \'opened\', \'clicked\',
                    \'spam_report\', \'unsubscribed\', \'other\'
                )),
                "recipient" varchar(320) NULL,
                "detail" text NULL,
                "occurred_at" timestamptz NOT NULL,
                "created_at" timestamptz NOT NULL DEFAULT now(),
                UNIQUE ("provider", "provider_event_id")
            )',

            'CREATE INDEX "email_event_message_idx" ON "email_event" ("email_message_id")',
            'CREATE INDEX "email_event_occurred_idx" ON "email_event" ("occurred_at")',

            // Requests to send email that were turned away, so that misuse (or a misconfigured service) shows up.
            'CREATE TABLE "rejected_request" (
                "id" uuid NOT NULL PRIMARY KEY,
                "email_token_id" varchar(19) NULL REFERENCES "email_token" ("id") ON DELETE SET NULL,
                "source" varchar(10) NOT NULL CHECK ("source" IN (\'smtp\', \'api\')),
                "client_ip" inet NOT NULL,
                "reason" varchar(30) NOT NULL CHECK ("reason" IN (
                    \'invalid_credentials\', \'revoked_email_token\', \'ip_not_allowed\', \'sender_not_allowed\',
                    \'reply_to_not_allowed\', \'recipient_not_allowed\', \'message_too_large\', \'invalid_message\',
                    \'provider_rejected\', \'too_many_recipients\'
                )),
                "detail" text NOT NULL DEFAULT \'\',
                "created_at" timestamptz NOT NULL DEFAULT now()
            )',

            'CREATE INDEX "rejected_request_created_idx" ON "rejected_request" ("created_at")',

            // When a provider couldn't be connected to, so it's skipped for a while, rather than every email waiting
            // for its connection to time out first.
            'CREATE TABLE "provider_status" (
                "id" uuid NOT NULL PRIMARY KEY,
                "provider" varchar(50) NOT NULL UNIQUE,
                "unavailable_until" timestamptz NOT NULL,
                "last_error" text NOT NULL DEFAULT \'\',
                "updated_at" timestamptz NOT NULL DEFAULT now()
            )',

            // The table that programster/pgsql-logger writes the logs to ("log", with its "log_level" type and an
            // index on created_at), using the SQL the logger gives, so the schema always matches what it expects.
            PgSqlLogger::getCreateTablesSql(),
        ];

        foreach ($queries as $query)
        {
            $db->query($query);
        }
    }


    /**
     * Run queries for downgrading the database version.
     *
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError - if there is an issue with the query.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionConnectionError - if database is not actually connected.
     */
    public function down(\PgSql\Connection $connectionResource) : void
    {
        $db = new PgSqlConnection($connectionResource);

        $tables = [
            'log',
            'provider_status',
            'rejected_request',
            'email_event',
            'email_attempt',
            'email_message',
            'global_rate_limit',
            'email_token_rate_limit',
            'email_token_address_rule',
            'email_token_network',
            'email_token',
            'api_key_network',
            'api_key',
            'password_reset_token',
            'admin_webauthn_credential',
            'admin_user',
        ];

        foreach ($tables as $table)
        {
            $db->query('DROP TABLE "' . $table . '"');
        }

        $db->query('DROP TYPE "log_level"');
    }
}
