// The page shown once after creating an email token: buttons to copy its credentials, and the checks of its senders.
import { CopyButtons } from './CopyButtons.js';
import { Elements } from './Elements.js';
import { SenderChecksPanel } from './SenderChecksPanel.js';

new CopyButtons(Elements.findById('created-secret', HTMLElement));
new SenderChecksPanel(Elements.findById('sender-checks', HTMLElement));
