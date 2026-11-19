<?php

/*
 * Everything an email token is restricted to: where it may be used from, which addresses it may send as and use as the
 * reply-to, who it may send to, and how fast. Each of the first four can be no restriction at all (AnyIp, AnyAddress),
 * which is what an email token has when it was given no condition for it.
 */

declare(strict_types = 1);


readonly class EmailTokenPolicy
{
    private IpRestrictionInterface $m_allowedNetworks;
    private AddressRestrictionInterface $m_allowedSenders;
    private AddressRestrictionInterface $m_allowedReplyTos;
    private AddressRestrictionInterface $m_allowedRecipients;
    private RateLimitSet $m_rateLimits;
    private Priority $m_priority;


    /**
     * @param IpRestrictionInterface $allowedNetworks - the IP addresses/ranges the token may be used from.
     *
     * @param AddressRestrictionInterface $allowedSenders - the addresses the token may send as. This is checked against the
     * envelope sender (MAIL FROM), and the From and Sender headers.
     *
     * @param AddressRestrictionInterface $allowedReplyTos - the addresses the token may put in the Reply-To header. An email with
     * no Reply-To header is always fine.
     *
     * @param AddressRestrictionInterface $allowedRecipients - who the token may send to (To, Cc and Bcc alike).
     *
     * @param RateLimitSet $rateLimits - how fast the token may send. These apply on top of the global limits.
     *
     * @param Priority $priority - how important the token's emails are in the queue.
     */
    public function __construct(
        IpRestrictionInterface $allowedNetworks,
        AddressRestrictionInterface $allowedSenders,
        AddressRestrictionInterface $allowedReplyTos,
        AddressRestrictionInterface $allowedRecipients,
        RateLimitSet $rateLimits,
        Priority $priority
    )
    {
        $this->m_allowedNetworks = $allowedNetworks;
        $this->m_allowedSenders = $allowedSenders;
        $this->m_allowedReplyTos = $allowedReplyTos;
        $this->m_allowedRecipients = $allowedRecipients;
        $this->m_rateLimits = $rateLimits;
        $this->m_priority = $priority;
    }


    /**
     * Get the rules for one part of an email.
     *
     * @param AddressRuleField $field - the part of the email.
     *
     * @return AddressRestrictionInterface
     */
    public function getRulesFor(AddressRuleField $field) : AddressRestrictionInterface
    {
        return match ($field) {
            AddressRuleField::SENDER => $this->m_allowedSenders,
            AddressRuleField::REPLY_TO => $this->m_allowedReplyTos,
            AddressRuleField::RECIPIENT => $this->m_allowedRecipients,
        };
    }


    # Accessors
    public function getAllowedNetworks() : IpRestrictionInterface { return $this->m_allowedNetworks; }
    public function getAllowedSenders() : AddressRestrictionInterface { return $this->m_allowedSenders; }
    public function getAllowedReplyTos() : AddressRestrictionInterface { return $this->m_allowedReplyTos; }
    public function getAllowedRecipients() : AddressRestrictionInterface { return $this->m_allowedRecipients; }
    public function getRateLimits() : RateLimitSet { return $this->m_rateLimits; }
    public function getPriority() : Priority { return $this->m_priority; }
}
