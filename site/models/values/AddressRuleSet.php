<?php

/*
 * The rules for which addresses are allowed in one part of an email (its sender, reply-to or recipients). An
 * address is allowed if any of the rules allows it. It can't be empty, as that would read as "nobody": for no
 * restriction, use AnyAddress instead.
 */

declare(strict_types = 1);


readonly class AddressRuleSet implements AddressRestrictionInterface
{
    private AddressRuleCollection $m_rules;


    /**
     * @param AddressRule ...$rules - the rules. At least one is required. Duplicates are dropped.
     *
     * @throws InvalidInputException - if no rules were given.
     */
    public function __construct(AddressRule ...$rules)
    {
        if (count($rules) === 0)
        {
            throw new InvalidInputException('At least one rule is required. To allow anyone, leave it out.');
        }

        $unique = [];
        $seen = [];

        foreach ($rules as $rule)
        {
            if (!isset($seen[(string)$rule]))
            {
                $seen[(string)$rule] = true;
                $unique[] = $rule;
            }
        }

        $this->m_rules = new AddressRuleCollection(...$unique);
    }


    /**
     * Create the rules from the written form used in the web interface: one rule per line, or separated by commas.
     * Blank lines are ignored. See AddressRule::createFromString() for the form of each rule.
     *
     * @param string $text - the rules, e.g. "*@example.org\nsomeone@example.com".
     *
     * @return AddressRuleSet
     *
     * @throws InvalidInputException - if a rule is invalid, or there are none.
     */
    public static function createFromText(string $text) : AddressRuleSet
    {
        $rules = [];

        foreach (preg_split('/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $part)
        {
            $rules[] = AddressRule::createFromString($part);
        }

        return new AddressRuleSet(...$rules);
    }


    /**
     * Check whether any of the rules allows an address.
     *
     * @param EmailAddress $address - the address to check.
     *
     * @return bool
     */
    public function allows(EmailAddress $address) : bool
    {
        $allowed = false;

        foreach ($this->m_rules as $rule)
        {
            /* @var $rule AddressRule */
            if ($allowed === false && $rule->allows($address))
            {
                $allowed = true;
            }
        }

        return $allowed;
    }


    public function allowsAll(EmailAddressCollection $addresses) : bool
    {
        $allowed = true;

        foreach ($addresses as $address)
        {
            /* @var $address EmailAddress */
            $allowed = ($allowed && $this->allows($address));
        }

        return $allowed;
    }


    public function allowsSomeOf(AddressRule $rule) : bool
    {
        $allowed = false;

        foreach ($this->m_rules as $own)
        {
            /* @var $own AddressRule */
            $allowed = ($allowed || $own->overlaps($rule));
        }

        return $allowed;
    }


    public function describe() : string
    {
        return $this->toText();
    }


    /**
     * Get the rules in their written form, one per line.
     *
     * @return string
     */
    public function toText() : string
    {
        $lines = [];

        foreach ($this->m_rules as $rule)
        {
            $lines[] = (string)$rule;
        }

        return implode("\n", $lines);
    }


    # Accessors
    public function getRules() : AddressRuleCollection { return clone $this->m_rules; }
}
