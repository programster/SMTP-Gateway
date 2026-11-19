/*
 * Shows the OpenAPI document with Swagger UI, which its page loads from a CDN as the global SwaggerUIBundle.
 */

import { Elements } from './Elements.js';

declare global
{
    interface Window
    {
        SwaggerUIBundle: (options: object) => unknown;
    }
}

const container = Elements.findById('swagger-ui', HTMLElement);
window.SwaggerUIBundle({ url: Elements.getData(container, 'specUrl'), domNode: container, deepLinking: true });
