<?php

/*
 * Whether a condition sent to the API may have "allowed": null, which removes it. Only a PATCH may, as it changes the
 * conditions it names and keeps the rest: anywhere else, a condition is removed by leaving it out.
 */

declare(strict_types = 1);


enum ConditionRemoval
{
    // "allowed": null is refused, like any other "allowed" that isn't a list.
    case REFUSED;

    // "allowed": null removes the condition, so the field has no restriction.
    case ALLOWED;
}
