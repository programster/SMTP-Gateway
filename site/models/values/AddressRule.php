<?php

/*
 * One rule for which email addresses an email token may use: anyone at a domain, or a single address. Create one with
 * AddressRule::domain() or AddressRule::address(). (For anyone at all, an email token has no rules: see AnyAddress.)
 */

declare(strict_types = 1);


readonly class AddressRule
{
    private AddressRuleKind $m_kind;

    // The domain for a DOMAIN rule, or the address for an ADDRESS rule.
    private string $m_value;


    private function __construct(AddressRuleKind $kind, string $value)
    {
        $this->m_kind = $kind;
        $this->m_value = $value;
    }


    /**
     * Create a rule that matches any address at exactly this domain. Subdomains don't match, so "example.org" does
     * not match "someone@mail.example.org".
     *
     * @param string $domain - the domain, e.g. "example.org".
     *
     * @return AddressRule
     *
     * @throws InvalidInputException - if it isn't a valid domain name.
     */
    public static function domain(string $domain) : AddressRule
    {
        $domain = strtolower(trim($domain));

        if (
               filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || str_contains($domain, '.') === false
        )
        {
            throw new InvalidInputException("\"{$domain}\" isn't a valid domain name, such as \"example.org\".");
        }

        return new AddressRule(AddressRuleKind::DOMAIN, $domain);
    }


    /**
     * Create a rule that matches exactly one address.
     *
     * @param EmailAddress $address - the address.
     *
     * @return AddressRule
     */
    public static function address(EmailAddress $address) : AddressRule
    {
        return new AddressRule(AddressRuleKind::ADDRESS, (string)$address);
    }


    /**
     * Create a rule from its written form, as used in the web interface: "*@example.org" for anyone at a domain, or a
     * single address such as "someone@example.org".
     *
     * @param string $rule - the written form of the rule.
     *
     * @return AddressRule
     *
     * @throws InvalidInputException - if it isn't one of those forms, including a "*" on its own, as anyone is allowed
     * by having no rules at all.
     */
    public static function createFromString(string $rule) : AddressRule
    {
        $rule = trim($rule);

        if ($rule === '*')
        {
            throw new InvalidInputException('"*" isn\'t needed: to allow anyone, leave the list empty.');
        }
        elseif (str_starts_with($rule, '*@'))
        {
            $result = AddressRule::domain(substr($rule, 2));
        }
        elseif (str_contains($rule, '*'))
        {
            $msg =
                "\"{$rule}\" isn't a valid rule. A \"*\" can only be used as \"*@domain\" (anyone at a domain).";

            throw new InvalidInputException($msg);
        }
        else
        {
            $result = AddressRule::address(new EmailAddress($rule));
        }

        return $result;
    }


    /**
     * Create a rule from how it's stored in the database.
     *
     * @param AddressRuleKind $kind - the kind of rule.
     *
     * @param string $value - the domain or address.
     *
     * @return AddressRule
     *
     * @throws InvalidInputException - if the value isn't valid for the kind of rule.
     */
    public static function createFromStoredForm(AddressRuleKind $kind, string $value) : AddressRule
    {
        return match ($kind) {
            AddressRuleKind::DOMAIN => AddressRule::domain($value),
            AddressRuleKind::ADDRESS => AddressRule::address(new EmailAddress($value)),
        };
    }


    /**
     * Check whether an address is allowed by this rule.
     *
     * @param EmailAddress $address - the address to check.
     *
     * @return bool
     */
    public function allows(EmailAddress $address) : bool
    {
        return match ($this->m_kind) {
            AddressRuleKind::DOMAIN => $address->getDomain() === $this->m_value,
            AddressRuleKind::ADDRESS => $address->isSameAs(new EmailAddress($this->m_value)),
        };
    }


    /**
     * Get the rule in its written form, which createFromString() turns back into the same rule.
     *
     * @return string
     */
    public function __toString() : string
    {
        return match ($this->m_kind) {
            AddressRuleKind::DOMAIN => '*@' . $this->m_value,
            AddressRuleKind::ADDRESS => $this->m_value,
        };
    }


    # Accessors
    public function getKind() : AddressRuleKind { return $this->m_kind; }
    public function getValue() : string { return $this->m_value; }
}
