<?php

declare(strict_types = 1);

use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<ConfiguredWebhook>
 */
final readonly class ConfiguredWebhookCollection extends AbstractReadonlyCollection
{
    public function __construct(ConfiguredWebhook ...$configuredWebhooks)
    {
        parent::__construct(...$configuredWebhooks);
    }


    /**
     * Get the webhooks that post to an address.
     *
     * @param string $url - the address, or an empty string, which none post to.
     *
     * @return ConfiguredWebhookCollection
     */
    public function filterPostingTo(string $url) : ConfiguredWebhookCollection
    {
        $postsToUrl = function (ConfiguredWebhook $webhook) use ($url) : bool {
            return $webhook->postsTo($url);
        };

        return $this->filter($postsToUrl);
    }
}
