<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<SendGridWebhook>
 */
final readonly class SendGridWebhookCollection extends AbstractReadonlyCollection
{
    public function __construct(SendGridWebhook ...$sendGridWebhooks)
    {
        parent::__construct(...$sendGridWebhooks);
    }
}
