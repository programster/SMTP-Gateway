<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<DocumentationSection>
 */
final readonly class DocumentationSectionCollection extends AbstractReadonlyCollection
{
    public function __construct(DocumentationSection ...$sections)
    {
        parent::__construct(...$sections);
    }
}
