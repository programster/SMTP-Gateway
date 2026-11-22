<?php

/*
 * A provider's id: a UUID that stays the same when the provider is renamed, so anything that refers to a provider
 * (e.g. an email token restricted to it) keeps doing so. It's given in settings.yaml as the provider's "id", and is
 * made when a provider is added without one.
 */

declare(strict_types = 1);

use Programster\PgsqlLib\PgsqlLib;
use Programster\PgsqlLib\UuidVersion;


final readonly class ProviderId
{
    private string $m_value;


    /**
     * @param string $value - the UUID, in either case (it's kept in lowercase).
     * @throws InvalidInputException - if it isn't a UUID.
     */
    public function __construct(string $value)
    {
        if (!PgsqlLib::isValidUuid($value))
        {
            $example = '0b7f1c2e-5a3d-4e8f-9b6a-2c4d6e8f0a1b';
            $msg = "\"{$value}\" isn't a valid provider id. It must be a UUID, e.g. {$example}.";
            throw new InvalidInputException($msg);
        }

        $this->m_value = strtolower($value);
    }


    /**
     * Create a new, random id, for a provider that's added without one.
     * @return ProviderId
     */
    public static function createNew() : ProviderId
    {
        return new ProviderId(PgsqlLib::generateUuid(UuidVersion::V4));
    }


    public function equals(ProviderId $other) : bool
    {
        return $this->m_value === $other->m_value;
    }


    public function __toString() : string
    {
        return $this->m_value;
    }


    # Accessors
    public function getValue() : string { return $this->m_value; }
}
