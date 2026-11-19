<?php

/*
 * One rate limit of an email token.
 */

declare(strict_types = 1);

use Programster\PgsqlObjects\TableInterface;


class EmailTokenRateLimit extends \Programster\PgsqlObjects\AbstractRecord
{
    private string $m_emailTokenId;
    private string $m_unit;
    private int $m_maximum;
    private int $m_periodSeconds;


    public function getTableHandler() : TableInterface
    {
        return EmailTokenRateLimitTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'email_token_id' => function () : string { return $this->m_emailTokenId; },
            'unit' => function () : string { return $this->m_unit; },
            'maximum' => function () : int { return $this->m_maximum; },
            'period_seconds' => function () : int { return $this->m_periodSeconds; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'email_token_id' => function (string $value) : void { $this->m_emailTokenId = $value; },
            'unit' => function (string $value) : void { $this->m_unit = $value; },
            'maximum' => function (int $value) : void { $this->m_maximum = $value; },
            'period_seconds' => function (int $value) : void { $this->m_periodSeconds = $value; },
        ];
    }


    # Accessors
    public function getEmailTokenId() : string { return $this->m_emailTokenId; }
    public function getUnit() : string { return $this->m_unit; }
    public function getMaximum() : int { return $this->m_maximum; }
    public function getPeriodSeconds() : int { return $this->m_periodSeconds; }
}
