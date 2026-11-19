<?php

/*
 * Thrown when no providers have been set up yet: settings.yaml doesn't exist, or has no providers section. It's the
 * normal state of a fresh install until the first provider is added in the web interface (or through the API), so
 * it's a configuration problem like any other to most code, but those that show it can tell it apart.
 */

declare(strict_types = 1);


final class ProvidersNotConfiguredException extends ConfigurationException
{
}
