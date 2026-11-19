<?php

/*
 * Stands in for a provider whose sender checker couldn't be created (e.g. its API couldn't be reached, or its API
 * key isn't set), so every check says why, rather than the provider silently being left out.
 */

declare(strict_types = 1);


final readonly class FailedSenderChecker implements SenderCheckerInterface
{
    private string $m_providerName;
    private string $m_reason;


    /**
     * @param string $providerName - the provider's name in settings.yaml.
     *
     * @param string $reason - why it couldn't be checked.
     */
    public function __construct(string $providerName, string $reason)
    {
        $this->m_providerName = $providerName;
        $this->m_reason = $reason;
    }


    public function checkAccount() : SenderCheckCollection
    {
        return $this->createFailure();
    }


    public function checkDomain(DomainDns $dns) : SenderCheckCollection
    {
        return $this->createFailure();
    }


    public function checkAddress(EmailAddress $address, DomainDns $dns) : SenderCheckCollection
    {
        return $this->createFailure();
    }


    public function checkAnySender() : SenderCheckCollection
    {
        return $this->createFailure();
    }


    private function createFailure() : SenderCheckCollection
    {
        $title = 'Couldn\'t be checked';
        $check = new SenderCheck(SenderCheckLevel::ERROR, $this->m_providerName, $title, $this->m_reason);
        return new SenderCheckCollection($check);
    }


    # Accessors
    public function getProviderName() : string { return $this->m_providerName; }
}
