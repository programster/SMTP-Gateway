<?php

/*
 * One stored address rule of an email token. Use EmailTokenRepository to get them as an EmailTokenPolicy.
 */

declare(strict_types = 1);

use Programster\PgsqlObjects\TableInterface;


class EmailTokenAddressRuleRecord extends \Programster\PgsqlObjects\AbstractRecord
{
    private string $m_emailTokenId;
    private string $m_field;
    private string $m_kind;
    private string $m_value;


    public function getTableHandler() : TableInterface
    {
        return EmailTokenAddressRuleRecordTable::getInstance();
    }


    protected function getAccessorFunctions() : array
    {
        return [
            'email_token_id' => function () : string { return $this->m_emailTokenId; },
            'field' => function () : string { return $this->m_field; },
            'kind' => function () : string { return $this->m_kind; },
            'value' => function () : string { return $this->m_value; },
        ];
    }


    protected function getSetFunctions() : array
    {
        return [
            'email_token_id' => function (string $value) : void { $this->m_emailTokenId = $value; },
            'field' => function (string $value) : void { $this->m_field = $value; },
            'kind' => function (string $value) : void { $this->m_kind = $value; },
            'value' => function (string $value) : void { $this->m_value = $value; },
        ];
    }


    # Accessors
    public function getEmailTokenId() : string { return $this->m_emailTokenId; }
    public function getField() : string { return $this->m_field; }
    public function getKind() : string { return $this->m_kind; }
    public function getValue() : string { return $this->m_value; }
}
