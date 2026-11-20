<?php

/*
 * What the provider form's two sender lists are filled in with: the domains, and the addresses, it takes (see
 * ProviderDefinition::takesAll()). Both empty means it takes any sender.
 */

declare(strict_types = 1);


final readonly class ProviderSenderRows
{
    // The rows' names on the form (see ViewValueRows), read back by ProviderFormInput.
    public const string DOMAINS_PREFIX = 'allowed_sender_domains';
    public const string ADDRESSES_PREFIX = 'allowed_sender_addresses';

    /** @var string[] */
    private array $m_domains;

    /** @var string[] */
    private array $m_addresses;


    /**
     * @param string[] $domains - the domains, e.g. ["example.org"].
     * @param string[] $addresses - the addresses, e.g. ["someone@example.com"].
     */
    private function __construct(array $domains, array $addresses)
    {
        $this->m_domains = array_values($domains);
        $this->m_addresses = array_values($addresses);
    }


    /**
     * Create the rows for a provider as it's saved.
     * @param AddressRestrictionInterface $senders - the senders it takes.
     * @return ProviderSenderRows
     */
    public static function createUsingRestriction(AddressRestrictionInterface $senders) : ProviderSenderRows
    {
        $domains = [];
        $addresses = [];

        if ($senders instanceof AddressRuleSet)
        {
            foreach ($senders->getRules() as $rule)
            {
                /* @var $rule AddressRule */
                if ($rule->getKind() === AddressRuleKind::DOMAIN)
                {
                    $domains[] = $rule->getValue();
                }
                else
                {
                    $addresses[] = $rule->getValue();
                }
            }
        }

        return new ProviderSenderRows($domains, $addresses);
    }


    /**
     * Create the rows from what was sent on the form, e.g. to fill it in again when it couldn't be saved.
     * @param Closure(string): string $getField - gets a form field's value by name.
     * @return ProviderSenderRows
     */
    public static function createUsingForm(Closure $getField) : ProviderSenderRows
    {
        $domains = PolicyInput::readValueRowsFromForm($getField, self::DOMAINS_PREFIX);
        $addresses = PolicyInput::readValueRowsFromForm($getField, self::ADDRESSES_PREFIX);
        return new ProviderSenderRows($domains, $addresses);
    }


    /**
     * Create empty rows, for a new provider, which takes any sender.
     * @return ProviderSenderRows
     */
    public static function createEmpty() : ProviderSenderRows
    {
        return new ProviderSenderRows([], []);
    }


    # Accessors
    /** @return string[] */
    public function getDomains() : array { return $this->m_domains; }

    /** @return string[] */
    public function getAddresses() : array { return $this->m_addresses; }
}
