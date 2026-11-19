<?php

/*
 * What the email token form (ViewEmailTokenFields) is for, which changes what some of its hints say.
 */

declare(strict_types = 1);


enum EmailTokenFormPurpose
{
    // Creating a token, whose IP addresses start with the address being used now.
    case CREATING;

    // Changing an existing token's name and restrictions.
    case EDITING;
}
