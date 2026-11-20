<?php

/*
 * A provider that an email token may send through, by the provider's id (see ProviderId).
 */

declare(strict_types = 1);

use Programster\PgsqlObjects\TableInterface;


class EmailTokenProvider extends \Programster\PgsqlObjects\AbstractRecord
{
    private string $m_emailTokenId;
    private string $m_providerId;


    public function getTableHandler() : TableInterface
    {
        return EmailTokenProviderTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'email_token_id' => function () : string { return $this->m_emailTokenId; },
            'provider_id' => function () : string { return $this->m_providerId; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'email_token_id' => function (string $value) : void { $this->m_emailTokenId = $value; },
            'provider_id' => function (string $value) : void { $this->m_providerId = $value; },
        ];
    }


    # Accessors
    public function getEmailTokenId() : string { return $this->m_emailTokenId; }
    public function getProviderId() : string { return $this->m_providerId; }
}
