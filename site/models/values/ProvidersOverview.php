<?php

/*
 * What the dashboard says about the providers: which are sending, and how, or why none can, and anything to warn
 * about (see SystemSenderWarning).
 */

declare(strict_types = 1);


readonly class ProvidersOverview
{
    private ProvidersState $m_state;

    /** @var list<string> */
    private array $m_enabledNames;

    private string $m_mode;
    private string $m_problem;
    private string $m_warning;


    /**
     * @param ProvidersState $state
     * @param list<string> $enabledNames
     * @param string $mode
     * @param string $problem
     * @param string $warning
     */
    private function __construct(
        ProvidersState $state,
        array $enabledNames,
        string $mode,
        string $problem,
        string $warning
    )
    {
        $this->m_state = $state;
        $this->m_enabledNames = $enabledNames;
        $this->m_mode = $mode;
        $this->m_problem = $problem;
        $this->m_warning = $warning;
    }


    /**
     * Create the overview of providers that can be used.
     * @param ProvidersConfig $config
     * @return ProvidersOverview
     */
    public static function createUsingConfig(ProvidersConfig $config) : ProvidersOverview
    {
        $names = [];

        foreach ($config->getEnabledProviders() as $provider)
        {
            /* @var $provider ProviderDefinition */
            $names[] = $provider->getName();
        }

        $mode = str_replace('_', ' ', $config->getMode()->value);
        $warning = SystemSenderWarning::describe($config);
        return new ProvidersOverview(ProvidersState::USABLE, $names, $mode, '', $warning);
    }


    /**
     * Create the overview for when none have been set up yet.
     * @return ProvidersOverview
     */
    public static function createNotConfigured() : ProvidersOverview
    {
        return new ProvidersOverview(ProvidersState::NOT_CONFIGURED, [], '', '', '');
    }


    /**
     * Create the overview for providers that can't be used, as they aren't valid.
     * @param string $problem - why not.
     * @return ProvidersOverview
     */
    public static function createInvalid(string $problem) : ProvidersOverview
    {
        return new ProvidersOverview(ProvidersState::INVALID, [], '', $problem, '');
    }


    # Accessors
    public function getState() : ProvidersState { return $this->m_state; }
    /** @return list<string> */
    public function getEnabledNames() : array { return $this->m_enabledNames; }
    public function getMode() : string { return $this->m_mode; }
    public function getProblem() : string { return $this->m_problem; }
    public function getWarning() : string { return $this->m_warning; }
}
