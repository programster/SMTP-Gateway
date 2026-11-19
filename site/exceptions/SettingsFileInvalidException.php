<?php

/*
 * Thrown when settings.yaml as a whole can't be read: it isn't valid YAML, or has keys that aren't sections, so none of
 * its sections can be trusted. A section that's invalid on its own (e.g. a provider missing a setting) throws a plain
 * ConfigurationException instead, as the other sections can still be used.
 */

declare(strict_types = 1);


final class SettingsFileInvalidException extends ConfigurationException
{
}
