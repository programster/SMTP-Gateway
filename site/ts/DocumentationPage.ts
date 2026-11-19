// A page of the documentation, with the list of its sections beside it marking those in view.
import { DocumentationContents } from './DocumentationContents.js';
import { Elements } from './Elements.js';

new DocumentationContents(
    Elements.findById('documentation', HTMLElement),
    Elements.findById('documentation-contents', HTMLElement)
);
