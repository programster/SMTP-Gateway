// An email token's page, with the checks of its allowed senders.
import { Elements } from './Elements.js';
import { SenderChecksPanel } from './SenderChecksPanel.js';

new SenderChecksPanel(Elements.findById('sender-checks', HTMLElement));
