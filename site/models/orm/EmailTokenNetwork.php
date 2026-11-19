<?php

/*
 * A network that an email token may be used from.
 */

declare(strict_types = 1);

use Programster\PgsqlObjects\TableInterface;


class EmailTokenNetwork extends \Programster\PgsqlObjects\AbstractRecord
{
    private string $m_emailTokenId;
    private string $m_network;


    public function getTableHandler() : TableInterface
    {
        return EmailTokenNetworkTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'email_token_id' => function () : string { return $this->m_emailTokenId; },
            'network' => function () : string { return $this->m_network; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'email_token_id' => function (string $value) : void { $this->m_emailTokenId = $value; },
            'network' => function (string $value) : void { $this->m_network = $value; },
        ];
    }


    # Accessors
    public function getEmailTokenId() : string { return $this->m_emailTokenId; }
    public function getNetwork() : string { return $this->m_network; }
}
