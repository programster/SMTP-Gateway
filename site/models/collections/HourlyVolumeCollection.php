<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<HourlyVolume>
 */
final readonly class HourlyVolumeCollection extends AbstractReadonlyCollection
{
    public function __construct(HourlyVolume ...$hourlyVolumes)
    {
        parent::__construct(...$hourlyVolumes);
    }
}
