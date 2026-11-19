<?php

/*
 * How the secrets in settings.yaml (passwords and keys written out in full, rather than as "${ENV_VAR}" references)
 * are written out.
 */

declare(strict_types = 1);


enum SecretOutput
{
    // Written as they are. Only for saving settings.yaml itself.
    case AS_IS;

    // Replaced with "<unchanged>", for showing and editing the configuration. Saving "<unchanged>" keeps the secret.
    case MASKED;

    // Replaced with a reference to an environment variable to put the secret in, so the file is safe to commit.
    case EXPORT;
}
