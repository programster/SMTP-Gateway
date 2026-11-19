<?php

/*
 * The API documentation, shown with Redoc (loaded from a CDN). It's a whole page of its own, rather than inside
 * the site's template, as Redoc lays out the whole page.
 */

declare(strict_types = 1);


class ViewRedocPage extends Programster\AbstractView\AbstractView
{
    public const string SCRIPT_URL = 'https://cdn.jsdelivr.net/npm/redoc@2.1.5/bundles/redoc.standalone.js';


    public function __construct()
    {
    }


    protected function renderContent() : void
    {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API documentation - <?= Html::e(SERVICE_NAME) ?></title>
</head>
<body>
    <p><a href="/docs/swagger">Try the API with Swagger UI</a> &middot; <a href="/openapi.json">openapi.json</a></p>
    <redoc spec-url="/openapi.json"></redoc>
    <script src="<?= Html::e(self::SCRIPT_URL) ?>"></script>
</body>
</html>
<?php
    }
}
