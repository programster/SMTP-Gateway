<?php

/*
 * An email token whose secret has been checked, and that is allowed to be used from the client's IP address.
 * Only EmailTokenAuthenticator creates these, so having one means those checks have passed.
 */

declare(strict_types = 1);


readonly class AuthenticatedEmailToken
{
    private EmailToken $m_emailToken;
    private EmailTokenPolicy $m_policy;
    private IpAddress $m_clientIp;
    private MessageSource $m_source;


    public function __construct(
        EmailToken $emailToken,
        EmailTokenPolicy $policy,
        IpAddress $clientIp,
        MessageSource $source
    )
    {
        $this->m_emailToken = $emailToken;
        $this->m_policy = $policy;
        $this->m_clientIp = $clientIp;
        $this->m_source = $source;
    }


    /**
     * Turn the request away, recording why.
     *
     * @param RejectionReason $reason - why.
     *
     * @param string $message - the message for the client.
     *
     * @return never
     *
     * @throws RequestRejectedException - always.
     */
    public function reject(RejectionReason $reason, string $message) : never
    {
        $rejection = new RequestRejectedException($reason, $message);
        RejectionLog::record($this->m_emailToken->getId(), $this->m_source, $this->m_clientIp, $rejection);
        throw $rejection;
    }


    # Accessors
    public function getEmailToken() : EmailToken { return $this->m_emailToken; }
    public function getPolicy() : EmailTokenPolicy { return $this->m_policy; }
    public function getClientIp() : IpAddress { return $this->m_clientIp; }
    public function getSource() : MessageSource { return $this->m_source; }
}
