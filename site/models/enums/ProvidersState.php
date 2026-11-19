<?php

/*
 * Whether the providers can be used to send email, for showing on the dashboard (see ProvidersOverview).
 */

declare(strict_types = 1);


enum ProvidersState
{
    // They're set up, and valid.
    case USABLE;

    // None have been set up yet: a fresh install, until the first is added.
    case NOT_CONFIGURED;

    // They're set up, but aren't valid (e.g. settings.yaml was edited by hand).
    case INVALID;
}
