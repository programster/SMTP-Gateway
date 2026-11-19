/*
 * The list of a documentation page's sections beside it ("On this page"). It marks every section whose own content is
 * in view (from its heading to the next heading), so it's clear where on the page you are: "SendGrid" isn't marked
 * while only its subsection "SendGrid's event webhook" is in view, as that's what's being read.
 *
 * A section's subsections are only listed while any of it is in view (its own content, or its subsections', up to the
 * next heading at its level or above), unless its caret has opened them, which keeps them open after it's scrolled away
 * from, until the caret closes them again. The caret only shows or hides them: it's the section's name that goes to
 * it.
 */

import { Elements } from './Elements.js';

interface Section
{
    readonly heading: HTMLElement;
    readonly link: HTMLAnchorElement;
    readonly level: number;

    // Whether any of it is in view, its subsections included, as of the last update.
    isAnyInView: boolean;
}


// Why a section's subsections are shown or hidden.
enum Expansion
{
    // Shown while any of the section is in view, and hidden when none of it is.
    AUTOMATIC,

    // Opened with the caret, so shown whether or not the section is in view.
    OPENED,

    // Closed with the caret while the section was in view, so hidden until it's out of view, and then automatic again.
    CLOSED,
}


interface Subsections
{
    readonly section: Section;
    readonly toggle: HTMLButtonElement;
    readonly list: HTMLElement;
    expansion: Expansion;
}


export class DocumentationContents
{
    // How much of a section has to be on screen to count as in view, so a sliver of the section above the one just
    // scrolled to (e.g. by clicking its name) isn't marked too.
    private static readonly MIN_VISIBLE_PIXELS = 16;

    private readonly m_article: HTMLElement;
    private readonly m_sections: Section[];
    private readonly m_subsections: Subsections[];
    private m_isUpdateWaiting: boolean;


    /**
     * @param article - the page's content, with the headings in it.
     * @param contents - the list, whose links each have the data-heading-id of the heading they go to, and whose
     * entries with subsections have a [data-role=toggle] caret and a [data-role=subsections] list.
     */
    public constructor(article: HTMLElement, contents: HTMLElement)
    {
        this.m_article = article;
        this.m_sections = [];
        this.m_subsections = [];
        this.m_isUpdateWaiting = false;
        const links = contents.querySelectorAll('a[data-heading-id]');

        for (let index = 0; index < links.length; index++)
        {
            const link = links[index];

            if (link instanceof HTMLAnchorElement)
            {
                const heading = Elements.findById(link.dataset.headingId ?? '', HTMLElement);
                const level = Number(heading.tagName.substring(1));
                this.m_sections.push({ heading: heading, link: link, level: level, isAnyInView: false });
            }
        }

        const toggles = contents.querySelectorAll('[data-role=toggle]');

        for (let index = 0; index < toggles.length; index++)
        {
            const toggle = toggles[index];

            if (toggle instanceof HTMLButtonElement)
            {
                this.setUpSubsections(toggle);
            }
        }

        const requestUpdate = this.requestUpdate.bind(this);
        window.addEventListener('scroll', requestUpdate, { passive: true });
        window.addEventListener('resize', requestUpdate);
        this.update();
    }


    private setUpSubsections(toggle: HTMLButtonElement): void
    {
        const entry = toggle.closest('li');

        if (entry === null)
        {
            throw new Error('A caret in #documentation-contents isn\'t in a list entry.');
        }

        const link = Elements.findWithin(entry, 'a[data-heading-id]', HTMLAnchorElement);
        const section = this.m_sections.find(this.isSectionFor.bind(this, link));

        if (section === undefined)
        {
            throw new Error(`No section for the caret beside "${link.textContent}" in #documentation-contents.`);
        }

        const subsections: Subsections = {
            section: section,
            toggle: toggle,
            list: Elements.findById(toggle.getAttribute('aria-controls') ?? '', HTMLElement),
            expansion: Expansion.AUTOMATIC,
        };

        this.m_subsections.push(subsections);
        toggle.addEventListener('click', this.toggle.bind(this, subsections));
    }


    // Open the subsections if they're hidden, or close them if they're shown, whatever showed them.
    private toggle(subsections: Subsections): void
    {
        // What's in view now, rather than as of the last frame, in case it's just been scrolled.
        this.markSectionsInView();
        const isShown = !subsections.list.hidden;
        const isInView = subsections.section.isAnyInView;

        if (!isShown)
        {
            subsections.expansion = Expansion.OPENED;
        }
        else if (isInView)
        {
            subsections.expansion = Expansion.CLOSED;
        }
        else
        {
            subsections.expansion = Expansion.AUTOMATIC;
        }

        this.showEverySubsections();
    }


    // Update once per frame at most, however many scroll events there are.
    private requestUpdate(): void
    {
        if (!this.m_isUpdateWaiting)
        {
            this.m_isUpdateWaiting = true;
            window.requestAnimationFrame(this.update.bind(this));
        }
    }


    private update(): void
    {
        this.m_isUpdateWaiting = false;
        this.markSectionsInView();
        this.showEverySubsections();
    }


    private markSectionsInView(): void
    {
        const margin = DocumentationContents.MIN_VISIBLE_PIXELS;
        const viewportHeight = window.innerHeight;

        for (let index = 0; index < this.m_sections.length; index++)
        {
            const section = this.m_sections[index];
            const top = section.heading.getBoundingClientRect().top;
            const ownEnd = this.findEnd(index + 1);
            const end = this.findSectionEnd(index);
            section.link.classList.toggle('is-in-view', top < viewportHeight - margin && ownEnd > margin);
            section.isAnyInView = (top < viewportHeight - margin && end > margin);
        }
    }


    private showEverySubsections(): void
    {
        for (const subsections of this.m_subsections)
        {
            this.showSubsections(subsections);
        }
    }


    private showSubsections(subsections: Subsections): void
    {
        const isInView = subsections.section.isAnyInView;

        // Closed with the caret only lasts until the section's out of view: next time, it opens as it comes into view.
        if (subsections.expansion === Expansion.CLOSED && !isInView)
        {
            subsections.expansion = Expansion.AUTOMATIC;
        }

        const isShown = (
               subsections.expansion === Expansion.OPENED
            || (subsections.expansion === Expansion.AUTOMATIC && isInView)
        );

        subsections.list.hidden = !isShown;
        subsections.toggle.setAttribute('aria-expanded', isShown ? 'true' : 'false');
    }


    // Find where a section ends, on screen, subsections included: at the next heading at its level or above.
    private findSectionEnd(index: number): number
    {
        const level = this.m_sections[index].level;
        let next = index + 1;

        while (next < this.m_sections.length && this.m_sections[next].level > level)
        {
            next++;
        }

        return this.findEnd(next);
    }


    // Find where the section before a heading ends, on screen: at that heading, or at the end of the page, for none.
    private findEnd(nextIndex: number): number
    {
        let end: number;

        if (nextIndex < this.m_sections.length)
        {
            end = this.m_sections[nextIndex].heading.getBoundingClientRect().top;
        }
        else
        {
            end = this.m_article.getBoundingClientRect().bottom;
        }

        return end;
    }


    private isSectionFor(link: HTMLAnchorElement, section: Section): boolean
    {
        return section.link === link;
    }
}
