<?php

/*
 * A provider that couldn't be connected to recently, so is being skipped until unavailable_until.
 */

declare(strict_types = 1);

use Programster\PgsqlObjects\TableInterface;


class ProviderStatus extends \Programster\PgsqlObjects\AbstractRecord
{
    private string $m_provider;
    private string $m_unavailableUntil;
    private string $m_lastError;
    private string $m_updatedAt;


    public function getTableHandler() : TableInterface
    {
        return ProviderStatusTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'provider' => function () : string { return $this->m_provider; },
            'unavailable_until' => function () : string { return $this->m_unavailableUntil; },
            'last_error' => function () : string { return $this->m_lastError; },
            'updated_at' => function () : string { return $this->m_updatedAt; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'provider' => function (string $value) : void { $this->m_provider = $value; },
            'unavailable_until' => function (string $value) : void { $this->m_unavailableUntil = $value; },
            'last_error' => function (string $value) : void { $this->m_lastError = $value; },
            'updated_at' => function (string $value) : void { $this->m_updatedAt = $value; },
        ];
    }


    # Accessors
    public function getProvider() : string { return $this->m_provider; }
    public function getUnavailableUntil() : string { return $this->m_unavailableUntil; }
    public function getLastError() : string { return $this->m_lastError; }
    public function getUpdatedAt() : string { return $this->m_updatedAt; }
}
