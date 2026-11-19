<?php

/*
 * The API documentation, shown with Swagger UI (loaded from a CDN), which can also send requests to try the API.
 */

declare(strict_types = 1);


class ViewSwaggerPage extends Programster\AbstractView\AbstractView
{
    public const string ASSET_BASE_URL = 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14';


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
    <link rel="stylesheet" href="<?= Html::e(self::ASSET_BASE_URL) ?>/swagger-ui.css">
</head>
<body>
    <p><a href="/docs">Read the API documentation with Redoc</a> &middot; <a href="/openapi.json">openapi.json</a></p>
    <div id="swagger-ui" data-spec-url="/openapi.json"></div>
    <script src="<?= Html::e(self::ASSET_BASE_URL) ?>/swagger-ui-bundle.js"></script>
    <script type="module" src="/js/SwaggerPage.js"></script>
</body>
</html>
<?php
    }
}
