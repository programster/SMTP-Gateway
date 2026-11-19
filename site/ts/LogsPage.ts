// The logs: choosing how many entries are on a page shows them straight away, so its button isn't needed.
import { Elements } from './Elements.js';

const form = Elements.findById('page-size-form', HTMLFormElement);
const select = Elements.findWithin(form, '[data-role=page-size]', HTMLSelectElement);
Elements.findWithin(form, '[data-role=page-size-submit]', HTMLButtonElement).hidden = true;

const showPage = function (): void {
    form.submit();
};

select.addEventListener('change', showPage);
