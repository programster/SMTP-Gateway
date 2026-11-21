<?php

/*
 * One field of a type of provider's settings on the provider form, e.g. SendGrid's API key: the setting it's written
 * to in settings.yaml, and how it's shown. Each type's fields are in ProviderFormFields, and ProviderFormTest checks
 * they're exactly the settings ProvidersConfigParser reads, so the form and the parser can't drift apart. The parser
 * checks what's entered.
 */

declare(strict_types = 1);


final readonly class ProviderField
{
    private string $m_key;
    private string $m_label;
    private ProviderFieldKind $m_kind;
    private bool $m_isRequired;
    private string $m_hint;
    private string $m_default;
    private string $m_permissions;

    /** @var array<string, string> - for a CHOICE, each value, and what it's shown as. */
    private array $m_options;


    /**
     * @param array<string, string> $options - for a CHOICE, each value and its label. Empty for any other kind.
     *
     * @param string $permissions - for a SECRET, the permissions it needs, or an empty string.
     */
    private function __construct(
        string $key,
        string $label,
        ProviderFieldKind $kind,
        bool $isRequired,
        string $hint,
        string $default,
        array $options,
        string $permissions = ''
    )
    {
        $this->m_permissions = $permissions;
        $this->m_key = $key;
        $this->m_label = $label;
        $this->m_kind = $kind;
        $this->m_isRequired = $isRequired;
        $this->m_hint = $hint;
        $this->m_default = $default;
        $this->m_options = $options;
    }


    /**
     * Create a field of one line of text, e.g. a host.
     *
     * @param string $key - the setting it's written to in settings.yaml, e.g. "host".
     *
     * @param string $label - what it's called on the form.
     *
     * @param bool $isRequired - whether it must be filled in.
     *
     * @param string $hint - what it is, and where to find it, or an empty string.
     *
     * @param string $default - what a new provider's field starts with, or an empty string.
     *
     * @return ProviderField
     */
    public static function createText(
        string $key,
        string $label,
        bool $isRequired,
        string $hint,
        string $default = ''
    ) : ProviderField
    {
        return new ProviderField($key, $label, ProviderFieldKind::TEXT, $isRequired, $hint, $default, []);
    }


    /**
     * Create a field for a password, key or token, which is never shown, and can be entered or read from an
     * environment variable.
     *
     * @param string $key - the setting it's written to in settings.yaml, e.g. "api_key".
     *
     * @param string $label - what it's called on the form.
     *
     * @param bool $isRequired - whether it must be given.
     *
     * @param string $hint - what it is, and where to find it, or an empty string.
     *
     * @param string $permissions - the permissions it needs, shown under a bold "Permissions:", or an empty string.
     *
     * @return ProviderField
     */
    public static function createSecret(
        string $key,
        string $label,
        bool $isRequired,
        string $hint,
        string $permissions = ''
    ) : ProviderField
    {
        return new ProviderField($key, $label, ProviderFieldKind::SECRET, $isRequired, $hint, '', [], $permissions);
    }


    /**
     * Create a field of several lines of text, e.g. a public key.
     *
     * @param string $key - the setting it's written to in settings.yaml.
     *
     * @param string $label - what it's called on the form.
     *
     * @param bool $isRequired - whether it must be filled in.
     *
     * @param string $hint - what it is, and where to find it, or an empty string.
     *
     * @return ProviderField
     */
    public static function createTextarea(string $key, string $label, bool $isRequired, string $hint) : ProviderField
    {
        return new ProviderField($key, $label, ProviderFieldKind::TEXTAREA, $isRequired, $hint, '', []);
    }


    /**
     * Create a field for a whole number, e.g. a port.
     *
     * @param string $key - the setting it's written to in settings.yaml.
     *
     * @param string $label - what it's called on the form.
     *
     * @param string $hint - what it is, or an empty string.
     *
     * @param int $default - what a new provider's field starts with.
     *
     * @return ProviderField
     */
    public static function createNumber(string $key, string $label, string $hint, int $default) : ProviderField
    {
        return new ProviderField($key, $label, ProviderFieldKind::NUMBER, true, $hint, (string)$default, []);
    }


    /**
     * Create a field that's one of a fixed set of values, from a backed enum's cases, e.g. a region.
     *
     * @param string $key - the setting it's written to in settings.yaml.
     *
     * @param string $label - what it's called on the form.
     *
     * @param string $hint - what it is, or an empty string.
     *
     * @param array<string, string> $options - each value (an enum case's value), and what it's shown as.
     *
     * @param string $default - the value a new provider's field starts with, which must be one of the options.
     *
     * @return ProviderField
     *
     * @throws LogicException - if there are no options, or the default isn't one of them.
     */
    public static function createChoice(
        string $key,
        string $label,
        string $hint,
        array $options,
        string $default
    ) : ProviderField
    {
        if (count($options) === 0 || array_key_exists($default, $options) === false)
        {
            throw new LogicException("The \"{$key}\" field needs options, and a default that's one of them.");
        }

        return new ProviderField($key, $label, ProviderFieldKind::CHOICE, true, $hint, $default, $options);
    }


    # Accessors
    public function getKey() : string { return $this->m_key; }
    public function getLabel() : string { return $this->m_label; }
    public function getKind() : ProviderFieldKind { return $this->m_kind; }
    public function isRequired() : bool { return $this->m_isRequired; }
    public function getHint() : string { return $this->m_hint; }
    public function getDefault() : string { return $this->m_default; }
    public function getPermissions() : string { return $this->m_permissions; }

    /** @return array<string, string> */
    public function getOptions() : array { return $this->m_options; }
}
