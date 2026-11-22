<?php

declare(strict_types = 1);

use Programster\Http\HttpCode;


class ViewErrorPage extends Programster\AbstractView\AbstractView
{
    private readonly HttpCode $m_status;
    private readonly string $m_message;


    public function __construct(HttpCode $status, string $message)
    {
        $this->m_status = $status;
        $this->m_message = $message;
    }


    protected function renderContent() : void
    {
        $title = match ($this->m_status) {
            HttpCode::NOT_FOUND => 'Page not found',
            HttpCode::FORBIDDEN => 'Not allowed',
            HttpCode::BAD_REQUEST => 'Something was wrong with that request',
            default => 'Something went wrong',
        };
?>
<div class="card narrow">
    <h1><?= Html::e($title) ?></h1>
    <p><?= Html::e($this->m_message) ?></p>
    <p><a href="/">Go to the dashboard</a></p>
</div>
<?php
    }
}
