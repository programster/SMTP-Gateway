// The logs: the Levels dropdown, choosing the kind of regular expression, and choosing how many entries are on a page,
// which shows them straight away, so its button isn't needed.
import { Elements } from './Elements.js';
import { LogLevelDropdown } from './LogLevelDropdown.js';
import { RegexTypePicker } from './RegexTypePicker.js';

new LogLevelDropdown(Elements.findById('log-level-dropdown', HTMLDetailsElement));
new RegexTypePicker(
    Elements.findById('regex-option', HTMLElement),
    Elements.findById('regex-type-dialog', HTMLDialogElement)
);

const form = Elements.findById('page-size-form', HTMLFormElement);
const select = Elements.findWithin(form, '[data-role=page-size]', HTMLSelectElement);
Elements.findWithin(form, '[data-role=page-size-submit]', HTMLButtonElement).hidden = true;

const showPage = function (): void {
    form.submit();
};

select.addEventListener('change', showPage);
