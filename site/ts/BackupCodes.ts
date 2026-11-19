/*
 * The newly generated backup codes, shown once: buttons to print them, download them as a text file, or copy them.
 */

import { Elements } from './Elements.js';

export class BackupCodes
{
    private readonly m_codes: string[];
    private readonly m_email: string;
    private readonly m_siteUrl: string;
    private readonly m_copyButton: HTMLButtonElement;


    public constructor(root: HTMLElement)
    {
        const list = Elements.findWithin(root, '[data-role=codes]', HTMLElement);
        const printButton = Elements.findWithin(root, '[data-role=print]', HTMLButtonElement);
        const downloadButton = Elements.findWithin(root, '[data-role=download]', HTMLButtonElement);
        this.m_copyButton = Elements.findWithin(root, '[data-role=copy]', HTMLButtonElement);
        this.m_email = Elements.getData(root, 'email');
        this.m_siteUrl = Elements.getData(root, 'siteUrl');
        this.m_codes = [];

        for (const item of Array.from(list.querySelectorAll('li')))
        {
            this.m_codes.push((item.textContent || '').trim());
        }

        if (this.m_codes.length === 0)
        {
            throw new Error('Missing the backup codes');
        }

        const print = function (): void {
            window.print();
        };

        printButton.addEventListener('click', print);
        downloadButton.addEventListener('click', this.download.bind(this));
        this.m_copyButton.addEventListener('click', this.copy.bind(this));
    }


    // Save them as a text file, e.g. "backup-codes.txt".
    private download(): void
    {
        const blob = new Blob([this.createText()], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'backup-codes.txt';
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }


    private async copy(): Promise<void>
    {
        await navigator.clipboard.writeText(this.m_codes.join('\n'));
        this.m_copyButton.textContent = 'Copied';
    }


    private createText(): string
    {
        const lines = [
            `Your backup codes for ${this.m_siteUrl}`,
            `For ${this.m_email}`,
            `Generated ${new Date().toLocaleString()}`,
            '',
            'Each code works once, instead of your second factor, when you log in.',
            'Generating new codes makes these stop working.',
            '',
        ];

        return lines.concat(this.m_codes).join('\n') + '\n';
    }
}
