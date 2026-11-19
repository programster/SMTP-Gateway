<?php

/*
 * What the providers page's editor is editing: the providers section of settings.yaml (as it usually does), or the
 * whole file, when it isn't valid YAML, so no section of it can be saved on its own until it's fixed.
 */

declare(strict_types = 1);


enum ProvidersEditScope : string
{
    case SECTION = 'section';
    case WHOLE_FILE = 'file';
}
