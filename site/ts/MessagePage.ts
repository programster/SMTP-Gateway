// An email's page: tabs for its HTML and plain text, when its content is kept.
import { Elements } from './Elements.js';
import { Tabs } from './Tabs.js';

new Tabs(Elements.findById('email-content', HTMLElement));
