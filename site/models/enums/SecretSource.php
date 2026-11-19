<?php

/*
 * Where a secret on the provider form comes from: entered there (and written into settings.yaml), or read from an
 * environment variable (written as a "${NAME}" reference, which keeps it out of the file).
 */

declare(strict_types = 1);


enum SecretSource : string
{
    case ENTERED = 'entered';
    case ENVIRONMENT = 'environment';
}
