<?php

/*
 * How the secrets in the providers' settings (passwords, keys and tokens) are written out.
 */

declare(strict_types = 1);


enum SecretOutput
{
    // Written as they are: for saving settings.yaml itself.
    case AS_IS;

    // Replaced with "<unchanged>", for showing and editing the configuration. Saving "<unchanged>" keeps the secret.
    case MASKED;
}
