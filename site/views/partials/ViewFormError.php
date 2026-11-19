<?php

/*
 * The error shown at the top of a form when what was sent wasn't valid. Shows nothing when there's no error.
 */

declare(strict_types = 1);


class ViewFormError extends Programster\AbstractView\AbstractView
{
    private readonly string $m_error;


    /**
     * @param string $error - the error, or an empty string for none.
     */
    public function __construct(string $error)
    {
        $this->m_error = $error;
    }


    protected function renderContent() : void
    {
        if ($this->m_error !== '')
        {
?>
<p class="flash flash-error" role="alert"><?= Html::e($this->m_error) ?></p>
<?php
        }
    }
}
