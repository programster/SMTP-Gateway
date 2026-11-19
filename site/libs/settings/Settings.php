<?php

/*
 * The service's settings that are changed on the Settings page, from settings.yaml (see ServiceSettings). The
 * deployment's settings, which can't change while it runs (e.g. the database's details), are in Config.
 */

declare(strict_types = 1);


final class Settings
{
    /**
     * Get the settings. They're read from settings.yaml once, and again only when it changes, so a change made on the
     * Settings page applies to every process straight away, without a restart.
     *
     * @return ServiceSettings
     */
    public static function get() : ServiceSettings
    {
        return SettingsFile::loadServiceSettings();
    }
}
