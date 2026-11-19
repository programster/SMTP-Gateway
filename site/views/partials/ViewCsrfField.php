<?php

/*
 * The hidden CSRF token field that every form that changes something must include.
 */

declare(strict_types = 1);


class ViewCsrfField extends Programster\AbstractView\AbstractView
{
    public function __construct()
    {
    }


    protected function renderContent() : void
    {
?>
<input type="hidden" name="csrf_token" value="<?= Html::e(WebSession::getCsrfToken()) ?>">
<?php
    }
}
