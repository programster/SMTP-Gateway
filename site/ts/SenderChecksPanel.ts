/*
 * The sender checks (see ViewSenderChecks): fetches them as JSON once the page has loaded, as they take a few
 * seconds, and shows each group of checks with its worst level. Groups with anything to fix start open.
 */
import { Elements } from './Elements.js';
import { JsonClient } from './JsonClient.js';

interface SenderCheck
{
    level: string;
    source: string;
    title: string;
    detail: string;
}

interface SenderCheckGroup
{
    heading: string;
    level: string;
    checks: SenderCheck[];
}

const LEVEL_LABELS: Readonly<Record<string, string>> = Object.freeze({
    ok: 'OK',
    info: 'Note',
    warning: 'Warning',
    error: 'Problem',
});

export class SenderChecksPanel
{
    private readonly m_url: string;
    private readonly m_client: JsonClient;
    private readonly m_results: HTMLElement;
    private readonly m_againButton: HTMLButtonElement;


    public constructor(root: HTMLElement)
    {
        this.m_url = Elements.getData(root, 'url');
        this.m_client = new JsonClient(Elements.getData(root, 'csrfToken'));
        this.m_results = Elements.findWithin(root, '[data-sender-check-results]', HTMLElement);
        this.m_againButton = Elements.findWithin(root, '[data-sender-check-again]', HTMLButtonElement);
        this.m_againButton.addEventListener('click', this.load.bind(this));
        this.load();
    }


    private async load(): Promise<void>
    {
        this.m_againButton.disabled = true;
        this.m_results.replaceChildren(SenderChecksPanel.createText('p', 'muted', 'Checking DNS and the providers…'));

        try
        {
            const data = await this.m_client.get(this.m_url);
            const groups: SenderCheckGroup[] = data.groups;
            const elements = groups.map(SenderChecksPanel.createGroup);

            if (elements.length === 0)
            {
                elements.push(SenderChecksPanel.createText('p', 'muted', 'There\'s nothing to check.'));
            }

            this.m_results.replaceChildren(...elements);
        }
        catch (error)
        {
            const message = (error instanceof Error) ? error.message : 'Something went wrong.';
            this.m_results.replaceChildren(SenderChecksPanel.createText('p', 'flash flash-error', message));
        }

        this.m_againButton.disabled = false;
    }


    private static createGroup(group: SenderCheckGroup): HTMLElement
    {
        const details = document.createElement('details');
        details.className = 'sender-check-group';
        details.open = (group.level === 'warning' || group.level === 'error');
        const summary = document.createElement('summary');
        summary.append(SenderChecksPanel.createBadge(group.level), ' ', group.heading);
        const list = document.createElement('ul');
        list.className = 'sender-check-list';

        for (const check of group.checks)
        {
            const item = document.createElement('li');
            const title = SenderChecksPanel.createText('strong', '', check.title);
            const source = SenderChecksPanel.createText('span', 'hint', check.source);
            item.append(SenderChecksPanel.createBadge(check.level), ' ', source, ': ', title);

            if (check.detail !== '')
            {
                item.append(SenderChecksPanel.createText('div', 'hint', check.detail));
            }

            list.append(item);
        }

        details.append(summary, list);
        return details;
    }


    private static createBadge(level: string): HTMLElement
    {
        return SenderChecksPanel.createText('span', `badge badge-check-${level}`, LEVEL_LABELS[level] || level);
    }


    private static createText(tag: string, className: string, text: string): HTMLElement
    {
        const element = document.createElement(tag);
        element.className = className;
        element.textContent = text;
        return element;
    }
}
