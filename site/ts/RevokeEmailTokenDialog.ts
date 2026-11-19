/*
 * The dialog that asks before an email token is revoked. As it opens, it loads how many of the token's emails are
 * waiting in the queue (which revoking it cancels), and its Revoke button is only enabled once that's shown (or once
 * it's said that it couldn't be loaded), so it's never confirmed without knowing.
 */

import { Elements } from './Elements.js';
import { JsonClient } from './JsonClient.js';

export class RevokeEmailTokenDialog
{
    private readonly m_dialog: HTMLDialogElement;
    private readonly m_queued: HTMLElement;
    private readonly m_confirm: HTMLButtonElement;
    private readonly m_queuedUrl: string;
    private readonly m_client: JsonClient;

    // Counts each time it's opened, so a count that arrives after it's been closed and opened again is ignored.
    private m_openings: number;


    /**
     * @param dialog - the dialog, with its data-queued-url, a [data-role=queued] for the count, and [data-role=confirm]
     * and [data-role=cancel] buttons.
     * @param button - the button that opens it.
     */
    public constructor(dialog: HTMLDialogElement, button: HTMLButtonElement)
    {
        this.m_dialog = dialog;
        this.m_queued = Elements.findWithin(dialog, '[data-role=queued]', HTMLElement);
        this.m_confirm = Elements.findWithin(dialog, '[data-role=confirm]', HTMLButtonElement);
        this.m_queuedUrl = Elements.getData(dialog, 'queuedUrl');
        const csrfToken = Elements.findWithin(dialog, 'input[name=csrf_token]', HTMLInputElement).value;
        this.m_client = new JsonClient(csrfToken);
        this.m_openings = 0;
        const cancel = Elements.findWithin(dialog, '[data-role=cancel]', HTMLButtonElement);
        button.addEventListener('click', this.open.bind(this));
        cancel.addEventListener('click', this.close.bind(this));
    }


    private async open(): Promise<void>
    {
        this.m_openings++;
        const opening = this.m_openings;
        this.m_confirm.disabled = true;
        this.m_queued.textContent = 'Checking how many of its emails are in the queue…';
        this.m_dialog.showModal();
        let message: string;

        try
        {
            const data = await this.m_client.get(this.m_queuedUrl);

            if (typeof data?.queuedEmails !== 'number')
            {
                throw new Error('The server didn\'t say how many there are.');
            }

            message = RevokeEmailTokenDialog.describeQueued(data.queuedEmails);
        }
        catch (error)
        {
            const reason = (error instanceof Error) ? error.message : String(error);
            message = `How many of its emails are in the queue couldn't be checked: ${reason}`;
        }

        if (opening === this.m_openings)
        {
            this.m_queued.textContent = message;
            this.m_confirm.disabled = false;
        }
    }


    private close(): void
    {
        this.m_dialog.close();
    }


    private static describeQueued(count: number): string
    {
        let message: string;

        if (count === 0)
        {
            message = 'None of its emails are waiting in the queue, so none will be cancelled.';
        }
        else if (count === 1)
        {
            message = '1 of its emails is waiting in the queue, and will be cancelled.';
        }
        else
        {
            message = `${count} of its emails are waiting in the queue, and will be cancelled.`;
        }

        return message;
    }
}
