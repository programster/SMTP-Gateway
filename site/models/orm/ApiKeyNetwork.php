<?php

/*
 * A network that a management API key may be used from.
 */

declare(strict_types = 1);

use Programster\PgsqlObjects\TableInterface;


class ApiKeyNetwork extends \Programster\PgsqlObjects\AbstractRecord
{
    private string $m_apiKeyId;
    private string $m_network;


    public function getTableHandler() : TableInterface
    {
        return ApiKeyNetworkTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'api_key_id' => function () : string { return $this->m_apiKeyId; },
            'network' => function () : string { return $this->m_network; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'api_key_id' => function (string $value) : void { $this->m_apiKeyId = $value; },
            'network' => function (string $value) : void { $this->m_network = $value; },
        ];
    }


    # Accessors
    public function getApiKeyId() : string { return $this->m_apiKeyId; }
    public function getNetwork() : string { return $this->m_network; }
}
