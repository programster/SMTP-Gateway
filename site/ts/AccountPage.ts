import { Elements } from './Elements.js';
import { SecurityKeyRegistration } from './SecurityKeyRegistration.js';
import { SecurityKeyRemoval } from './SecurityKeyRemoval.js';

new SecurityKeyRegistration(Elements.findById('security-key-registration', HTMLElement));

// The dialog for removing a security key is only on the page when there's a key to remove.
const removeButtons: HTMLButtonElement[] = [];
const removeElements = document.querySelectorAll('[data-role=remove-security-key]');

for (let index = 0; index < removeElements.length; index++)
{
    const element = removeElements[index];

    if (element instanceof HTMLButtonElement)
    {
        removeButtons.push(element);
    }
}

if (removeButtons.length > 0)
{
    new SecurityKeyRemoval(Elements.findById('remove-security-key-dialog', HTMLDialogElement), ...removeButtons);
}
