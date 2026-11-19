<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<SenderCheck>
 */
final readonly class SenderCheckCollection extends AbstractReadonlyCollection
{
    public function __construct(SenderCheck ...$senderChecks)
    {
        parent::__construct(...$senderChecks);
    }


    /**
     * Get the most serious level of the checks in it.
     *
     * @return SenderCheckLevel - OK if it's empty.
     */
    public function getWorstLevel() : SenderCheckLevel
    {
        $worst = SenderCheckLevel::OK;

        foreach ($this as $check)
        {
            /* @var $check SenderCheck */
            if ($check->getLevel()->getSeverity() > $worst->getSeverity())
            {
                $worst = $check->getLevel();
            }
        }

        return $worst;
    }
}
