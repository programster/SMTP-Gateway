<?php

/*
 * One section of a page of the documentation, for the list of its sections beside it ("On this page"): its heading,
 * and the sections within it (e.g. "SendGrid's API key", within "SendGrid"), from the headings below its level that
 * come before the next heading at its level or above.
 */

declare(strict_types = 1);


final readonly class DocumentationSection
{
    private DocumentationHeading $m_heading;
    private DocumentationSectionCollection $m_subsections;


    /**
     * @param DocumentationHeading $heading - its heading.
     *
     * @param DocumentationSectionCollection $subsections - the sections within it, in order (none, for most).
     */
    public function __construct(DocumentationHeading $heading, DocumentationSectionCollection $subsections)
    {
        $this->m_heading = $heading;
        $this->m_subsections = $subsections;
    }


    # Accessors
    public function getHeading() : DocumentationHeading { return $this->m_heading; }
    public function getSubsections() : DocumentationSectionCollection { return $this->m_subsections; }
}
