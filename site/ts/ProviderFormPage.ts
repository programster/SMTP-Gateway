// The page for adding or editing a provider, whose rate limits are rows added one at a time.
import { CopyButtons } from './CopyButtons.js';
import { DynamicRows } from './DynamicRows.js';
import { Elements } from './Elements.js';
import { ProviderForm } from './ProviderForm.js';
import { SecretGenerator } from './SecretGenerator.js';
import { Smtp2GoConnectionChoice } from './Smtp2GoConnectionChoice.js';

const form = Elements.findById('provider-form', HTMLFormElement);
new ProviderForm(form);
new CopyButtons(form);
new SecretGenerator(form);
DynamicRows.createForPage();

// Not there when editing a provider of another type.
const smtp2GoFields = form.querySelector('fieldset[data-provider-type=smtp2go]');

if (smtp2GoFields instanceof HTMLFieldSetElement)
{
    new Smtp2GoConnectionChoice(smtp2GoFields);
}
