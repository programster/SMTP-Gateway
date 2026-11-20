<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<ProviderId>
 */
final readonly class ProviderIdCollection extends AbstractReadonlyCollection
{
    public function __construct(ProviderId ...$providerIds)
    {
        parent::__construct(...$providerIds);
    }


    /**
     * Check whether an id is in the collection.
     * @param ProviderId $id - the id.
     * @return bool
     */
    public function includes(ProviderId $id) : bool
    {
        $isIncluded = false;

        foreach ($this as $candidate)
        {
            /* @var $candidate ProviderId */
            $isIncluded = ($isIncluded || $candidate->equals($id));
        }

        return $isIncluded;
    }


    /**
     * Get the ids as strings, e.g. for a query or JSON.
     * @return string[]
     */
    public function toStrings() : array
    {
        $values = [];

        foreach ($this as $id)
        {
            /* @var $id ProviderId */
            $values[] = $id->getValue();
        }

        return $values;
    }
}
