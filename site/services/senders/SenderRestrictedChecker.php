<?php

/*
 * Wraps the sender checker of a provider that only takes particular senders (see ProviderDefinition::takesAll()), so
 * it's only checked for the senders it takes. For any other, there's nothing to set up with it, as it's never used for
 * them, so its checks are replaced by a note saying so, rather than problems that don't matter.
 */

declare(strict_types = 1);


final readonly class SenderRestrictedChecker implements SenderCheckerInterface
{
    private SenderCheckerInterface $m_checker;
    private AddressRuleSet $m_takes;


    /**
     * @param SenderCheckerInterface $checker - the provider's own checker.
     *
     * @param AddressRuleSet $takes - the senders the provider takes.
     */
    public function __construct(SenderCheckerInterface $checker, AddressRuleSet $takes)
    {
        $this->m_checker = $checker;
        $this->m_takes = $takes;
    }


    public function checkAccount() : SenderCheckCollection
    {
        return $this->m_checker->checkAccount();
    }


    public function checkDomain(DomainDns $dns) : SenderCheckCollection
    {
        $isTaken = $this->m_takes->allowsSomeOf(AddressRule::domain($dns->getDomain()));
        return $isTaken ? $this->m_checker->checkDomain($dns) : $this->createNotTaken();
    }


    public function checkAddress(EmailAddress $address, DomainDns $dns) : SenderCheckCollection
    {
        $isTaken = $this->m_takes->allows($address);
        return $isTaken ? $this->m_checker->checkAddress($address, $dns) : $this->createNotTaken();
    }


    public function checkAnySender() : SenderCheckCollection
    {
        $note = new SenderCheck(
            SenderCheckLevel::INFO,
            $this->getProviderName(),
            'Only takes its own senders',
            'It only takes email from: ' . str_replace("\n", ', ', $this->m_takes->describe()) . '.'
        );

        return new SenderCheckCollection($note, ...$this->m_checker->checkAnySender()->toArray());
    }


    private function createNotTaken() : SenderCheckCollection
    {
        $detail =
            'It only takes email from: ' . str_replace("\n", ', ', $this->m_takes->describe()) . ', so it isn\'t used'
            . ' for this sender, and doesn\'t need setting up for it.';

        $note = new SenderCheck(SenderCheckLevel::INFO, $this->getProviderName(), 'Doesn\'t take this sender', $detail);
        return new SenderCheckCollection($note);
    }


    # Accessors
    public function getProviderName() : string { return $this->m_checker->getProviderName(); }
}
