/*
 * The help dialogs on a page (see ViewHelpDialog): each [data-role=help-button] opens the dialog its data-dialog
 * names, which closes with its [data-role=close] button, the Escape key, or a click outside it.
 */

import { Elements } from './Elements.js';

export class HelpDialogs
{
    /**
     * Set up every help button on the page, and its dialog.
     */
    public static createForPage(): void
    {
        for (const button of Elements.findAllButtons('[data-role=help-button]'))
        {
            const dialog = Elements.findById(Elements.getData(button, 'dialog'), HTMLDialogElement);
            const close = Elements.findWithin(dialog, '[data-role=close]', HTMLButtonElement);
            button.addEventListener('click', HelpDialogs.open.bind(null, dialog));
            close.addEventListener('click', HelpDialogs.close.bind(null, dialog));
            dialog.addEventListener('click', HelpDialogs.closeIfOutside.bind(null, dialog));
        }
    }


    private static open(dialog: HTMLDialogElement): void
    {
        dialog.showModal();
    }


    private static close(dialog: HTMLDialogElement): void
    {
        dialog.close();
    }


    // A click on the backdrop is a click on the dialog itself, outside its box.
    private static closeIfOutside(dialog: HTMLDialogElement, event: MouseEvent): void
    {
        const box = dialog.getBoundingClientRect();

        const isOutside = (
               event.clientX < box.left
            || event.clientX > box.right
            || event.clientY < box.top
            || event.clientY > box.bottom
        );

        if (isOutside)
        {
            dialog.close();
        }
    }
}
