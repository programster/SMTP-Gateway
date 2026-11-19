<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<DocumentationHeading>
 */
final readonly class DocumentationHeadingCollection extends AbstractReadonlyCollection
{
    public function __construct(DocumentationHeading ...$headings)
    {
        parent::__construct(...$headings);
    }
}
