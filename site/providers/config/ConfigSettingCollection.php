<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<ConfigSetting>
 */
final readonly class ConfigSettingCollection extends AbstractReadonlyCollection
{
    public function __construct(ConfigSetting ...$configSettings)
    {
        parent::__construct(...$configSettings);
    }
}
