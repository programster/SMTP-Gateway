<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<SenderCheckGroup>
 */
final readonly class SenderCheckGroupCollection extends AbstractReadonlyCollection
{
    public function __construct(SenderCheckGroup ...$senderCheckGroups)
    {
        parent::__construct(...$senderCheckGroups);
    }


    /**
     * Get it in the form the web interface's JSON endpoints return it in.
     *
     * @return array{groups: list<array>}
     */
    public function toResponseArray() : array
    {
        $groups = [];

        foreach ($this as $group)
        {
            /* @var $group SenderCheckGroup */
            $groups[] = $group->toArray();
        }

        return ['groups' => $groups];
    }
}
