// The page for creating an email token: its lists and rate limits are rows added one at a time, and the curl command
// that would create the same token through the API is shown below it, as it's filled in.
import { ApiCommandPreview } from './ApiCommandPreview.js';
import { DynamicRows } from './DynamicRows.js';
import { Elements } from './Elements.js';

DynamicRows.createForPage();
const form = Elements.findById('email-token-form', HTMLFormElement);
new ApiCommandPreview(form, Elements.findById('api-command', HTMLElement));
