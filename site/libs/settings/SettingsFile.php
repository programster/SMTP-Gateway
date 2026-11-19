<?php

/*
 * Reads and writes settings.yaml (SETTINGS_FILE, /config/settings.yaml by default): the settings that are changed in
 * the web interface (or through the API), in sections (see SettingsSection). A fresh install has no file until the
 * first save.
 *
 * Each section is saved on its own, against the version of that section it was based on, so two people saving the
 * same section at once can't silently overwrite each other's changes, while saving one section never conflicts with a
 * change to another. The other sections are kept exactly as they're written, comments and all. A save checks the new
 * settings first, then writes the file to a temporary file and renames that over the old one, so the file is never
 * half written, and anything reading it sees either the old version or the new one. Saves are made under a lock, which
 * is a file in the system's temporary folder (/tmp in the container), rather than next to settings.yaml, so it never
 * appears in the codebase.
 */

declare(strict_types = 1);

use Programster\CoreLibs\Enums\PreserveOwnershipEnum;
use Programster\CoreLibs\Enums\UseSystemTempDirEnum;
use Programster\CoreLibs\Exceptions\CoreLibsException;
use Programster\CoreLibs\Filesystem;


final class SettingsFile
{
    public const string DEFAULT_PATH = '/config/settings.yaml';

    private const string HEADER =
        "# The settings that are changed on the Settings and Providers pages of the web interface (or through\n"
        . "# the API), which check them before every save. A setting written as \"\${NAME}\" is read from the\n"
        . "# environment variable NAME (e.g. set in .env), so secrets can be kept out of this file. Any setting\n"
        . "# (or section) that's left out has its default.";


    /**
     * Get the providers. They're read from the file once, and again only when it changes.
     *
     * @return ProvidersConfig
     *
     * @throws ProvidersNotConfiguredException - if there are none yet: there's no file, or it has no providers section.
     * @throws SettingsFileInvalidException - if the file can't be read, or isn't valid YAML.
     * @throws ConfigurationException - if the providers section isn't valid.
     */
    public static function loadProviders() : ProvidersConfig
    {
        static $config = null;
        static $loadedSignature = '';

        $signature = self::getFileSignature();

        if ($config === null || $signature !== $loadedSignature)
        {
            $data = self::readSections(self::readFile())[SettingsSection::PROVIDERS->value] ?? null;

            if ($data === null)
            {
                $msg = 'No providers have been set up yet. Add one on the Providers page, or through the API.';
                throw new ProvidersNotConfiguredException($msg);
            }

            try
            {
                $config = ProvidersConfigParser::parse($data, new ProviderDefinitionCollection());
            }
            catch (InvalidInputException $e)
            {
                $msg = "The providers in " . self::getPath() . " aren't valid: {$e->getMessage()}";
                throw new ConfigurationException($msg);
            }

            $loadedSignature = $signature;
        }

        return $config;
    }


    /**
     * Get the service's settings (every section but the providers). They're read from the file once, and again only
     * when it changes. A section that's invalid has its defaults (see ServiceSettings), and why is logged once.
     *
     * @return ServiceSettings
     */
    public static function loadServiceSettings() : ServiceSettings
    {
        static $settings = null;
        static $loadedSignature = '';

        $signature = self::getFileSignature();

        if ($settings === null || $signature !== $loadedSignature)
        {
            $settings = self::readServiceSettings();
            $loadedSignature = $signature;

            // error_log() rather than the logger, as the logger needs a database connection, which the SMTP server's
            // parent process must never open (see ServiceFactory::getDb()).
            foreach ($settings->getProblems() as $problem)
            {
                error_log("WARNING: {$problem}");
            }
        }

        return $settings;
    }


    /**
     * Read providers from YAML text (e.g. pasted into the web interface, or sent to the API), without saving them.
     * Secrets given as "<unchanged>" are taken from the current providers.
     *
     * @param string $yaml - the providers section's YAML, without the "providers:" key.
     *
     * @return ProvidersConfig
     *
     * @throws InvalidInputException - if it isn't valid YAML, or isn't a valid configuration.
     */
    public static function parseProvidersYaml(string $yaml) : ProvidersConfig
    {
        return self::parseProvidersData(self::decodeYaml($yaml));
    }


    /**
     * Read providers from their decoded form (e.g. the JSON sent to the API), without saving them. Secrets given as
     * "<unchanged>" are taken from the current providers.
     *
     * @param mixed $data - the decoded providers section.
     *
     * @return ProvidersConfig
     *
     * @throws InvalidInputException - if it isn't a valid configuration.
     */
    public static function parseProvidersData(mixed $data) : ProvidersConfig
    {
        return ProvidersConfigParser::parse($data, self::loadCurrentProviders());
    }


    /**
     * Get the current version of a section, which a change to it must be based on to be saved.
     *
     * @param SettingsSection $section
     *
     * @return string - a hash of the section, which is the hash of an empty string while there's no such section.
     *
     * @throws SettingsFileInvalidException - if the file can't be read, or isn't valid YAML.
     */
    public static function getSectionVersion(SettingsSection $section) : string
    {
        return self::getVersionOfSection(self::readSections(self::readFile()), $section);
    }


    /**
     * Get the current version of the whole file, which saveWholeFile() must be given.
     *
     * @return string - a hash of the file, which is the hash of an empty string while there's no file.
     *
     * @throws SettingsFileInvalidException - if it can't be read.
     */
    public static function getFileVersion() : string
    {
        return hash('sha256', self::readFile());
    }


    /**
     * Get the file as it is, e.g. to show it for fixing when it isn't valid YAML.
     *
     * @return string - an empty string if there's no file.
     *
     * @throws SettingsFileInvalidException - if it can't be read.
     */
    public static function readRaw() : string
    {
        return self::readFile();
    }


    /**
     * Get a section as it's written (without its key or indentation), e.g. to show it for fixing when it isn't valid.
     *
     * @param SettingsSection $section
     *
     * @return string - an empty string if there's no such section.
     *
     * @throws SettingsFileInvalidException - if the file can't be read, or isn't valid YAML.
     */
    public static function readSectionText(SettingsSection $section) : string
    {
        $raw = self::readFile();
        $sections = self::readSections($raw);
        $text = '';

        if (isset($sections[$section->value]))
        {
            $text = self::getSectionBody($raw, $section, $sections[$section->value]);
        }

        return $text;
    }


    /**
     * Get the whole file as YAML that's safe to commit to version control: the secrets that are written out in full
     * are replaced with references to environment variables to put them in.
     *
     * @return string
     *
     * @throws SettingsFileInvalidException - if the file can't be read, or isn't valid YAML.
     */
    public static function export() : string
    {
        $raw = self::readFile();
        $sections = self::readSections($raw);
        $texts = [];

        foreach (SettingsSection::cases() as $section)
        {
            if (isset($sections[$section->value]))
            {
                $texts[] = self::exportSection($raw, $section, $sections[$section->value]);
            }
        }

        return self::formatFile(...$texts);
    }


    public static function saveProviders(ProvidersConfig $config, string $basedOnVersion) : string
    {
        $yaml = ProvidersConfigParser::toYaml($config, SecretOutput::AS_IS);
        return self::saveSection(SettingsSection::PROVIDERS, $yaml, $basedOnVersion);
    }


    public static function saveGeneral(GeneralSettings $settings, string $basedOnVersion) : string
    {
        $yaml = ServiceSettingsParser::generalToYaml($settings);
        return self::saveSection(SettingsSection::GENERAL, $yaml, $basedOnVersion);
    }


    public static function saveSecurity(SecuritySettings $settings, string $basedOnVersion) : string
    {
        $yaml = ServiceSettingsParser::securityToYaml($settings);
        return self::saveSection(SettingsSection::SECURITY, $yaml, $basedOnVersion);
    }


    public static function saveProxies(ProxySettings $settings, string $basedOnVersion) : string
    {
        $yaml = ServiceSettingsParser::proxiesToYaml($settings);
        return self::saveSection(SettingsSection::PROXIES, $yaml, $basedOnVersion);
    }


    public static function saveSending(SendingSettings $settings, string $basedOnVersion) : string
    {
        $yaml = ServiceSettingsParser::sendingToYaml($settings);
        return self::saveSection(SettingsSection::SENDING, $yaml, $basedOnVersion);
    }


    public static function saveRetention(RetentionSettings $settings, string $basedOnVersion) : string
    {
        $yaml = ServiceSettingsParser::retentionToYaml($settings);
        return self::saveSection(SettingsSection::RETENTION, $yaml, $basedOnVersion);
    }


    /**
     * Save the settings for logging in with Google. When it's switched on, they're checked to be usable first.
     *
     * @param GoogleSsoConfig $config
     *
     * @param string $basedOnVersion - the version of the google_sso section the change was made to.
     *
     * @return string - the section's new version.
     *
     * @throws InvalidInputException - if it's switched on, but can't be used (e.g. PUBLIC_URL isn't set, or the secret
     * refers to an environment variable that isn't set).
     *
     * @throws ConflictException - if the section has been changed since $basedOnVersion.
     *
     * @throws ConfigurationException - if the file can't be read or written.
     */
    public static function saveGoogleSso(GoogleSsoConfig $config, string $basedOnVersion) : string
    {
        if ($config->isEnabled())
        {
            try
            {
                $config->createSettings(Config::get()->hasPublicUrl() ? Config::get()->getPublicUrl() : '');
            }
            catch (ConfigurationException $e)
            {
                throw new InvalidInputException($e->getMessage());
            }
        }

        $yaml = GoogleSsoConfigParser::toYaml($config, SecretOutput::AS_IS);
        return self::saveSection(SettingsSection::GOOGLE_SSO, $yaml, $basedOnVersion);
    }


    /**
     * Replace the whole file, e.g. to fix one that isn't valid YAML (as it's edited by hand), so no section of it can
     * be saved on its own. Every section in it must be valid.
     *
     * @param string $yaml - the whole file.
     *
     * @param string $basedOnVersion - the version of the file (from getFileVersion()) the change was made to.
     *
     * @return string - the file's new version.
     *
     * @throws InvalidInputException - if it isn't valid YAML, or a section in it isn't valid.
     *
     * @throws ConflictException - if the file has been changed since $basedOnVersion.
     *
     * @throws ConfigurationException - if the file can't be read or written.
     */
    public static function saveWholeFile(string $yaml, string $basedOnVersion) : string
    {
        try
        {
            $sections = self::readSections($yaml);
        }
        catch (SettingsFileInvalidException $e)
        {
            throw new InvalidInputException($e->getMessage());
        }

        self::assertSectionsValid($sections);
        $contents = rtrim($yaml) . "\n";

        $write = function () use ($contents, $basedOnVersion) : string {
            if (hash_equals(self::getFileVersion(), $basedOnVersion) === false)
            {
                $msg = 'settings.yaml has been changed since you loaded it. Reload it, and make your change again.';
                throw new ConflictException($msg);
            }

            self::writeFile(self::getPath(), $contents);
            return hash('sha256', $contents);
        };

        return self::runLocked($write);
    }


    /**
     * Write a section with its key, e.g. for formatFile().
     *
     * @param SettingsSection $section
     *
     * @param string $yaml - its contents, without its key or indentation.
     *
     * @return string
     */
    public static function formatSection(SettingsSection $section, string $yaml) : string
    {
        return $section->value . ":\n" . self::indent($yaml);
    }


    /**
     * Put a file together from its sections (each from formatSection()), under the explanation at the top.
     * @param string ...$sectionTexts
     * @return string
     */
    public static function formatFile(string ...$sectionTexts) : string
    {
        return implode("\n\n", [self::HEADER, ...$sectionTexts]) . "\n";
    }


    public static function getPath() : string
    {
        return Config::getEnv('SETTINGS_FILE', self::DEFAULT_PATH);
    }


    /**
     * Decode YAML safely: PHP objects (and any other tags) aren't supported, so a file can't create objects.
     *
     * @param string $yaml
     *
     * @return mixed
     *
     * @throws InvalidInputException - if it isn't valid YAML.
     */
    public static function decodeYaml(string $yaml) : mixed
    {
        $error = '';

        $captureError = function (int $level, string $message) use (&$error) : bool {
            $error = preg_replace('/^yaml_parse\(\): /', '', $message);
            return true;
        };

        set_error_handler($captureError);

        try
        {
            ini_set('yaml.decode_php', '0');
            $data = yaml_parse($yaml);
        }
        finally
        {
            restore_error_handler();
        }

        if ($data === false || $error !== '')
        {
            throw new InvalidInputException("It isn't valid YAML: {$error}");
        }

        return $data;
    }


    /**
     * Read the service's settings from the file, with the defaults for any section that's missing or invalid.
     * @return ServiceSettings
     */
    private static function readServiceSettings() : ServiceSettings
    {
        $problems = [];

        try
        {
            $sections = self::readSections(self::readFile());
        }
        catch (SettingsFileInvalidException $e)
        {
            $sections = [];
            $problems[] = "The defaults are being used for every setting, as {$e->getMessage()}";
        }

        $general = GeneralSettings::createDefault();
        $security = SecuritySettings::createDefault();
        $proxies = ProxySettings::createDefault();
        $sending = SendingSettings::createDefault();
        $retention = RetentionSettings::createDefault();
        $isRetentionValid = (count($problems) === 0);
        $googleSso = GoogleSsoConfig::createDisabled();

        foreach (SettingsSection::cases() as $section)
        {
            $data = $sections[$section->value] ?? null;

            try
            {
                if ($data === null || $section === SettingsSection::PROVIDERS)
                {
                    // Left out, so it has its defaults. The providers aren't part of these settings.
                }
                elseif ($section === SettingsSection::GENERAL)
                {
                    $general = ServiceSettingsParser::parseGeneral($data);
                }
                elseif ($section === SettingsSection::SECURITY)
                {
                    $security = ServiceSettingsParser::parseSecurity($data);
                }
                elseif ($section === SettingsSection::PROXIES)
                {
                    $proxies = ServiceSettingsParser::parseProxies($data);
                }
                elseif ($section === SettingsSection::SENDING)
                {
                    $sending = ServiceSettingsParser::parseSending($data);
                }
                elseif ($section === SettingsSection::RETENTION)
                {
                    $retention = ServiceSettingsParser::parseRetention($data);
                }
                else
                {
                    $googleSso = GoogleSsoConfigParser::parse($data);
                }
            }
            catch (InvalidInputException $e)
            {
                $isRetention = ($section === SettingsSection::RETENTION);
                $isRetentionValid = $isRetentionValid && !$isRetention;
                $use = $isRetention ? 'nothing is being deleted' : 'the defaults are used';
                $problems[] = "The \"{$section->value}\" settings in settings.yaml are invalid, so {$use}: "
                    . $e->getMessage();
            }
        }

        return new ServiceSettings(
            $general,
            $security,
            $proxies,
            $sending,
            $retention,
            $isRetentionValid,
            $googleSso,
            ...$problems
        );
    }


    /**
     * Replace one section of the file, keeping the others exactly as they're written.
     *
     * @param SettingsSection $section - the section to replace.
     *
     * @param string $yaml - its new contents, without its key or indentation.
     *
     * @param string $basedOnVersion - the version of the section (from getSectionVersion()) the change was made to.
     *
     * @return string - the section's new version.
     *
     * @throws ConflictException - if the section has been changed since $basedOnVersion.
     *
     * @throws ConfigurationException - if the file can't be read (or isn't valid YAML, so has to be fixed as a whole
     * first), or can't be written.
     */
    private static function saveSection(SettingsSection $section, string $yaml, string $basedOnVersion) : string
    {
        $write = function () use ($section, $yaml, $basedOnVersion) : string {
            $raw = self::readFile();
            $sections = self::readSections($raw);

            if (hash_equals(self::getVersionOfSection($sections, $section), $basedOnVersion) === false)
            {
                $msg =
                    "The {$section->value} settings have been changed since you loaded them. Reload them, and make your"
                    . ' change again.';

                throw new ConflictException($msg);
            }

            $texts = [];

            foreach (SettingsSection::cases() as $case)
            {
                if ($case === $section)
                {
                    $texts[] = self::formatSection($case, $yaml);
                }
                elseif (isset($sections[$case->value]))
                {
                    $texts[] = self::getSectionText($raw, $case, $sections[$case->value]);
                }
            }

            $contents = self::formatFile(...$texts);
            self::writeFile(self::getPath(), $contents);
            return self::getVersionOfSection(self::readSections($contents), $section);
        };

        return self::runLocked($write);
    }


    /**
     * Run some work under the lock that every save takes, so saves happen one at a time.
     *
     * @param Closure $work - the work, which returns the new version.
     *
     * @return string - what the work returned.
     *
     * @throws ConfigurationException - if the lock can't be taken.
     */
    private static function runLocked(Closure $work) : string
    {
        $lockPath = self::getLockPath(self::getPath());

        try
        {
            Filesystem::lockFile($lockPath);
        }
        catch (CoreLibsException $e)
        {
            $msg = "Couldn't lock " . self::getPath() . " to save it, with {$lockPath}: {$e->getMessage()}";
            throw new ConfigurationException($msg);
        }

        try
        {
            $result = $work();
        }
        finally
        {
            Filesystem::unlockFile($lockPath);
        }

        return $result;
    }


    /**
     * Check every section in a file is valid, before saving it as a whole.
     *
     * @param array<string, mixed> $sections - the file's sections, as decoded.
     *
     * @return void
     *
     * @throws InvalidInputException - naming the first section that isn't valid.
     */
    private static function assertSectionsValid(array $sections) : void
    {
        foreach (SettingsSection::cases() as $section)
        {
            $data = $sections[$section->value] ?? null;

            try
            {
                if ($data !== null)
                {
                    match ($section) {
                        SettingsSection::GENERAL => ServiceSettingsParser::parseGeneral($data),
                        SettingsSection::SECURITY => ServiceSettingsParser::parseSecurity($data),
                        SettingsSection::PROXIES => ServiceSettingsParser::parseProxies($data),
                        SettingsSection::SENDING => ServiceSettingsParser::parseSending($data),
                        SettingsSection::RETENTION => ServiceSettingsParser::parseRetention($data),
                        SettingsSection::GOOGLE_SSO => GoogleSsoConfigParser::parse($data),
                        SettingsSection::PROVIDERS => self::parseProvidersData($data),
                    };
                }
            }
            catch (InvalidInputException $e)
            {
                throw new InvalidInputException("The \"{$section->value}\" section isn't valid: {$e->getMessage()}");
            }
        }
    }


    /**
     * Read the file's sections.
     *
     * @param string $raw - the file's contents.
     *
     * @return array<string, mixed> - each section, as decoded, by its key. Empty for an empty file.
     *
     * @throws SettingsFileInvalidException - if it isn't valid YAML, or has a key that isn't a section.
     */
    private static function readSections(string $raw) : array
    {
        try
        {
            $data = (trim($raw) === '') ? [] : self::decodeYaml($raw);
        }
        catch (InvalidInputException $e)
        {
            throw new SettingsFileInvalidException(self::getPath() . " can't be read. {$e->getMessage()}");
        }

        $data = $data ?? [];
        $known = array_column(SettingsSection::cases(), 'value');

        if (!is_array($data) || (count($data) > 0 && array_is_list($data)))
        {
            $msg = self::getPath() . ' must be made of sections: ' . implode(', ', $known) . '.';
            throw new SettingsFileInvalidException($msg);
        }

        $unknown = array_diff(array_map('strval', array_keys($data)), $known);

        if (count($unknown) > 0)
        {
            $names = implode('", "', $unknown);
            $msg = self::getPath() . " has no \"{$names}\" section. Its sections are: " . implode(', ', $known) . '.';
            throw new SettingsFileInvalidException($msg);
        }

        return $data;
    }


    /**
     * @param array<string, mixed> $sections - the file's sections, as decoded.
     * @param SettingsSection $section
     * @return string - a hash of the section, or of an empty string if there's no such section.
     */
    private static function getVersionOfSection(array $sections, SettingsSection $section) : string
    {
        $data = $sections[$section->value] ?? null;
        return hash('sha256', ($data === null) ? '' : json_encode($data, JSON_THROW_ON_ERROR));
    }


    /**
     * Get a section as it's written in the file, key and all, so it can be kept as it is when another is saved. If it
     * can't be found as written (e.g. the whole file is written as one {...} mapping), it's written out afresh from
     * its decoded form.
     *
     * @param string $raw - the file's contents.
     *
     * @param SettingsSection $section
     *
     * @param mixed $data - the section, as decoded.
     *
     * @return string
     */
    private static function getSectionText(string $raw, SettingsSection $section, mixed $data) : string
    {
        $collected = [];
        $isInside = false;
        $isDone = false;

        foreach (preg_split('/\r?\n/', $raw) as $line)
        {
            $isTopLevelKey = (preg_match('/^[A-Za-z_"\'][^:]*:/', $line) === 1);
            $isSectionKey = (preg_match('/^["\']?' . preg_quote($section->value, '/') . '["\']?\s*:/', $line) === 1);

            if ($isInside && $isTopLevelKey)
            {
                $isDone = true;
            }
            elseif ($isSectionKey && !$isDone)
            {
                $isInside = true;
            }

            if ($isInside && !$isDone)
            {
                $collected[] = $line;
            }
        }

        $text = rtrim(implode("\n", $collected));

        return ($text !== '')
            ? $text
            : self::formatSection($section, self::emitYaml($data));
    }


    /**
     * Get a section's contents as they're written in the file, without its key, and with its indentation removed.
     *
     * @param string $raw - the file's contents.
     *
     * @param SettingsSection $section
     *
     * @param mixed $data - the section, as decoded.
     *
     * @return string
     */
    private static function getSectionBody(string $raw, SettingsSection $section, mixed $data) : string
    {
        $lines = explode("\n", self::getSectionText($raw, $section, $data));
        $firstLine = (string)array_shift($lines);
        $afterKey = trim((string)preg_replace('/^[^:]*:/', '', $firstLine));
        $indent = PHP_INT_MAX;

        foreach ($lines as $line)
        {
            if (trim($line) !== '')
            {
                $indent = min($indent, strlen($line) - strlen(ltrim($line, ' ')));
            }
        }

        $dedented = [];

        foreach ($lines as $line)
        {
            $dedented[] = ($indent === PHP_INT_MAX) ? $line : substr($line, $indent);
        }

        return ($afterKey !== '' && !str_starts_with($afterKey, '#'))
            ? $afterKey
            : trim(implode("\n", $dedented), "\n");
    }


    /**
     * Get a section for export(): the providers and Google sign-in with their secrets replaced with references, if they
     * can be read, and anything else as it's written.
     *
     * @param string $raw - the file's contents.
     *
     * @param SettingsSection $section
     *
     * @param mixed $data - the section, as decoded.
     *
     * @return string
     */
    private static function exportSection(string $raw, SettingsSection $section, mixed $data) : string
    {
        try
        {
            $text = match ($section) {
                SettingsSection::PROVIDERS => self::exportProviders($data),
                SettingsSection::GOOGLE_SSO => self::exportGoogleSso($data),
                default => self::getSectionText($raw, $section, $data),
            };
        }
        catch (InvalidInputException)
        {
            // An invalid section can't be read to find its secrets, so it's left out, rather than exported with them.
            $text = "# The {$section->value} section was left out, as it isn't valid.";
        }

        return $text;
    }


    /**
     * @throws InvalidInputException - if the section isn't valid.
     */
    private static function exportProviders(mixed $data) : string
    {
        $config = ProvidersConfigParser::parse($data, new ProviderDefinitionCollection());
        $yaml = ProvidersConfigParser::toYaml($config, SecretOutput::EXPORT);
        return self::formatSection(SettingsSection::PROVIDERS, $yaml);
    }


    /**
     * @throws InvalidInputException - if the section isn't valid.
     */
    private static function exportGoogleSso(mixed $data) : string
    {
        $yaml = GoogleSsoConfigParser::toYaml(GoogleSsoConfigParser::parse($data), SecretOutput::EXPORT);
        return self::formatSection(SettingsSection::GOOGLE_SSO, $yaml);
    }


    /**
     * Indent YAML by two spaces, to go under a section's key. Empty lines are left empty.
     * @param string $yaml
     * @return string
     */
    private static function indent(string $yaml) : string
    {
        $lines = [];

        foreach (explode("\n", rtrim($yaml)) as $line)
        {
            $lines[] = ($line === '') ? '' : '  ' . $line;
        }

        return implode("\n", $lines);
    }


    /**
     * Write a decoded value as YAML, without the "---" and "..." that mark the start and end of a document.
     * @param mixed $data
     * @return string
     */
    private static function emitYaml(mixed $data) : string
    {
        $yaml = yaml_emit($data, YAML_UTF8_ENCODING, YAML_LN_BREAK);
        $yaml = (string)preg_replace('/^---\s*\n?/', '', $yaml);
        return rtrim((string)preg_replace('/\n\.\.\.\s*$/', '', $yaml));
    }


    /**
     * The providers as they are now, or none if there aren't any or they aren't valid (e.g. while they're being fixed
     * through the web interface), so that "<unchanged>" secrets just can't be taken from them.
     *
     * @return ProviderDefinitionCollection
     */
    private static function loadCurrentProviders() : ProviderDefinitionCollection
    {
        try
        {
            $providers = self::loadProviders()->getProviders();
        }
        catch (ConfigurationException)
        {
            $providers = new ProviderDefinitionCollection();
        }

        return $providers;
    }


    /**
     * Read the file.
     *
     * @return string - its contents, or an empty string if it doesn't exist.
     *
     * @throws SettingsFileInvalidException - if it exists, but can't be read.
     */
    private static function readFile() : string
    {
        $path = self::getPath();
        clearstatcache(true, $path);
        $contents = file_exists($path) ? @file_get_contents($path) : '';

        if ($contents === false)
        {
            throw new SettingsFileInvalidException("{$path} can't be read. Check www-data can read it.");
        }

        return $contents;
    }


    /**
     * Something that changes whenever the file does, cheap enough to check before every use.
     */
    private static function getFileSignature() : string
    {
        clearstatcache(true, self::getPath());
        $stat = @stat(self::getPath());
        return ($stat === false) ? '' : "{$stat['ino']}-{$stat['mtime']}-{$stat['size']}";
    }


    /**
     * Get the path of the file saves lock while they save. It's in the system's temporary folder (/tmp in the
     * container), so it never appears in the codebase, and it's created if it doesn't exist. It's named after the
     * settings file's path, so each settings file has a lock of its own.
     *
     * The settings file itself can't be the lock, as saving replaces it with a new file (see writeFile()), and
     * a lock belongs to a file, not to its name: a save waiting on the old file would then go ahead, while another
     * locks the new one. The lock only works between processes that share the temporary folder, which every save does,
     * as only the app container saves the settings.
     *
     * @param string $path - the settings file's path.
     *
     * @return string
     */
    private static function getLockPath(string $path) : string
    {
        return sys_get_temp_dir() . '/settings-' . hash('sha256', $path) . '.lock';
    }


    /**
     * Write the file, with Filesystem::replaceFileContents(), which writes the new contents to a private temporary
     * file and renames it over the old one, so the file is never half written, and keeps its permissions. A file that
     * doesn't exist yet (the first save) is created in the same way, and made writable by the folder's group, so it
     * can be edited by hand too.
     *
     * The temporary file goes next to it (never in /tmp, which may be another filesystem), and the file's owner isn't
     * kept (it becomes www-data's, in the folder's group), as either would make it copy the contents back into the
     * old file instead, which isn't atomic, so something reading it part way could see half of it.
     *
     * @param string $path
     *
     * @param string $contents
     *
     * @return void
     *
     * @throws ConfigurationException - if it can't be written.
     */
    private static function writeFile(string $path, string $contents) : void
    {
        $isNew = !file_exists($path);

        try
        {
            Filesystem::replaceFileContents(
                $path,
                $contents,
                createIfMissing: true,
                useSystemTempDir: UseSystemTempDirEnum::NEVER,
                preserveOwnership: PreserveOwnershipEnum::NO
            );
        }
        catch (CoreLibsException $e)
        {
            $msg = "Couldn't write {$path}: {$e->getMessage()} Check it and its folder can be written to by www-data.";
            throw new ConfigurationException($msg);
        }

        if ($isNew)
        {
            @chmod($path, 0664);
        }
    }
}
