<?php

/*
 * Turns the general, security, proxies, sending and retention sections of settings.yaml into their settings, and back
 * again. Any setting that's left out has its default:
 *
 *     general:
 *       timezone: "Europe/London"           # UTC by default
 *       date_order: "dmy"                   # dmy (day, month, year) by default, or mdy, or ymd
 *       date_separator: "-"                 # "-" by default, or "/", or "."
 *       time_position: "before"             # before the date by default, or after
 *     security:
 *       require_mfa: true                   # true by default
 *       password_min_length: 12             # 8 to 64, 12 by default
 *       password_requires_capital: false    # false by default, as are the next two
 *       password_requires_number: false
 *       password_requires_symbol: false
 *     proxies:
 *       behind_cloudflare: false            # false by default
 *       trusted_proxies: ["172.16.0.0/12"]  # none by default
 *     sending:
 *       system_email_from: "noreply@example.org"   # none by default
 *       sync_wait_seconds: 5                # 0 to 60, 5 by default
 *     retention:
 *       email_record_days: 90               # 90 by default
 *       email_content_days: -1              # -1 (not kept, the default), 0 (as long as the record), or days
 *       email_attachment_days: -1           # -1 (not kept, the default), 0 (as long as the content), or days
 *       log_days: 90                        # 90 by default
 */

declare(strict_types = 1);


final class ServiceSettingsParser
{
    /**
     * @param mixed $data - the general section, as decoded.
     * @return GeneralSettings
     * @throws InvalidInputException - if a setting is unknown or invalid.
     */
    public static function parseGeneral(mixed $data) : GeneralSettings
    {
        $keys = ['timezone', 'date_order', 'date_separator', 'time_position'];
        $settings = SettingsYaml::readMapping($data, SettingsSection::GENERAL, ...$keys);
        $timezone = $settings['timezone'] ?? 'UTC';
        $default = DateTimeFormat::createDefault();
        $order = DateOrder::tryFrom((string)($settings['date_order'] ?? $default->getOrder()->value));
        $separator = DateSeparator::tryFrom((string)($settings['date_separator'] ?? $default->getSeparator()->value));
        $position = TimePosition::tryFrom((string)($settings['time_position'] ?? $default->getTimePosition()->value));

        if (!is_string($timezone))
        {
            $msg = '"general.timezone" must be the name of a time zone, e.g. "Europe/London".';
            throw new InvalidInputException($msg);
        }

        if ($order === null)
        {
            throw new InvalidInputException('"general.date_order" must be "dmy", "mdy" or "ymd".');
        }

        if ($separator === null)
        {
            throw new InvalidInputException('"general.date_separator" must be "-", "/" or ".".');
        }

        if ($position === null)
        {
            throw new InvalidInputException('"general.time_position" must be "before" or "after".');
        }

        return GeneralSettings::createUsingTimezoneName($timezone, new DateTimeFormat($order, $separator, $position));
    }


    /**
     * @param mixed $data - the security section, as decoded.
     * @return SecuritySettings
     * @throws InvalidInputException - if a setting is unknown or invalid.
     */
    public static function parseSecurity(mixed $data) : SecuritySettings
    {
        $keys = [
            'require_mfa',
            'password_min_length',
            'password_requires_capital',
            'password_requires_number',
            'password_requires_symbol',
        ];

        $settings = SettingsYaml::readMapping($data, SettingsSection::SECURITY, ...$keys);
        $default = PasswordPolicy::createDefault();
        $minLength = $settings['password_min_length'] ?? $default->getMinLength();

        if (!is_int($minLength))
        {
            throw new InvalidInputException('"security.password_min_length" must be a whole number, e.g. 12.');
        }

        $booleans = ['require_mfa', 'password_requires_capital', 'password_requires_number', 'password_requires_symbol'];

        foreach ($booleans as $key)
        {
            if (!is_bool($settings[$key] ?? false))
            {
                throw new InvalidInputException("\"security.{$key}\" must be true or false.");
            }
        }

        $passwordPolicy = new PasswordPolicy(
            $minLength,
            $settings['password_requires_capital'] ?? false,
            $settings['password_requires_number'] ?? false,
            $settings['password_requires_symbol'] ?? false
        );

        return new SecuritySettings($settings['require_mfa'] ?? true, $passwordPolicy);
    }


    /**
     * @param mixed $data - the proxies section, as decoded.
     * @return ProxySettings
     * @throws InvalidInputException - if a setting is unknown or invalid.
     */
    public static function parseProxies(mixed $data) : ProxySettings
    {
        $settings = SettingsYaml::readMapping($data, SettingsSection::PROXIES, 'behind_cloudflare', 'trusted_proxies');
        $isBehindCloudflare = $settings['behind_cloudflare'] ?? false;
        $proxies = $settings['trusted_proxies'] ?? [];

        if (!is_bool($isBehindCloudflare))
        {
            throw new InvalidInputException('"proxies.behind_cloudflare" must be true or false.');
        }

        if (!is_array($proxies) || !array_is_list($proxies))
        {
            throw new InvalidInputException('"proxies.trusted_proxies" must be a list, e.g. ["172.16.0.0/12"].');
        }

        $networks = [];

        foreach ($proxies as $proxy)
        {
            if (!is_string($proxy))
            {
                throw new InvalidInputException('Each of "proxies.trusted_proxies" must be an IP address or range.');
            }

            $networks[] = new IpNetwork($proxy);
        }

        return new ProxySettings($isBehindCloudflare, new IpNetworkCollection(...$networks));
    }


    /**
     * @param mixed $data - the sending section, as decoded.
     * @return SendingSettings
     * @throws InvalidInputException - if a setting is unknown or invalid.
     */
    public static function parseSending(mixed $data) : SendingSettings
    {
        $section = SettingsSection::SENDING;
        $settings = SettingsYaml::readMapping($data, $section, 'system_email_from', 'sync_wait_seconds');
        $from = $settings['system_email_from'] ?? '';
        $default = SendingSettings::createDefault();

        if (!is_string($from))
        {
            throw new InvalidInputException('"sending.system_email_from" must be an email address.');
        }

        $waitSeconds = SettingsYaml::readInt($settings, $section, 'sync_wait_seconds', $default->getSyncWaitSeconds());
        return new SendingSettings($from, $waitSeconds);
    }


    /**
     * @param mixed $data - the retention section, as decoded.
     * @return RetentionSettings
     * @throws InvalidInputException - if a setting is unknown or invalid, or they don't fit within each other.
     */
    public static function parseRetention(mixed $data) : RetentionSettings
    {
        $section = SettingsSection::RETENTION;
        $keys = ['email_record_days', 'email_content_days', 'email_attachment_days', 'log_days'];
        $settings = SettingsYaml::readMapping($data, $section, ...$keys);
        $default = RetentionSettings::createDefault();
        $defaultPolicy = $default->getEmailRetention();
        $recordDays = SettingsYaml::readInt($settings, $section, 'email_record_days', $defaultPolicy->getRecordDays());
        $contentDays = SettingsYaml::readInt($settings, $section, 'email_content_days', -1);
        $attachmentDays = SettingsYaml::readInt($settings, $section, 'email_attachment_days', -1);
        $logDays = SettingsYaml::readInt($settings, $section, 'log_days', $default->getLogDays());

        $policy = EmailRetentionPolicy::createFromSettings(
            (string)$recordDays,
            (string)$contentDays,
            (string)$attachmentDays
        );

        return new RetentionSettings($policy, $logDays);
    }


    /**
     * @param GeneralSettings $settings
     * @return string - the section as YAML, without its key or indentation.
     */
    public static function generalToYaml(GeneralSettings $settings) : string
    {
        $lines = [
            '# The time zone times are shown in, in the web interface. Times are always stored in UTC.',
            'timezone: ' . SettingsYaml::quote($settings->getTimezone()->getName()),
            '# How dates and times are written: the order of the date (dmy, mdy or ymd), what separates its',
            '# parts ("-", "/" or "."), and whether the time comes before or after it.',
            'date_order: ' . SettingsYaml::quote($settings->getDateTimeFormat()->getOrder()->value),
            'date_separator: ' . SettingsYaml::quote($settings->getDateTimeFormat()->getSeparator()->value),
            'time_position: ' . SettingsYaml::quote($settings->getDateTimeFormat()->getTimePosition()->value),
        ];

        return implode("\n", $lines);
    }


    /**
     * @param SecuritySettings $settings
     * @return string - the section as YAML, without its key or indentation.
     */
    public static function securityToYaml(SecuritySettings $settings) : string
    {
        $policy = $settings->getPasswordPolicy();

        $lines = [
            '# Whether every account must use two-factor authentication.',
            'require_mfa: ' . SettingsYaml::quote($settings->isMfaRequired()),
            '# What a password must have: at least this many characters, and, if these are true, a capital letter, a',
            '# number and a symbol. Checked whenever a password is set.',
            'password_min_length: ' . SettingsYaml::quote($policy->getMinLength()),
            'password_requires_capital: ' . SettingsYaml::quote($policy->requiresCapital()),
            'password_requires_number: ' . SettingsYaml::quote($policy->requiresNumber()),
            'password_requires_symbol: ' . SettingsYaml::quote($policy->requiresSymbol()),
        ];

        return implode("\n", $lines);
    }


    /**
     * @param ProxySettings $settings
     * @return string - the section as YAML, without its key or indentation.
     */
    public static function proxiesToYaml(ProxySettings $settings) : string
    {
        $proxies = [];

        foreach ($settings->getTrustedProxies() as $proxy)
        {
            /* @var $proxy IpNetwork */
            $proxies[] = SettingsYaml::quote((string)$proxy);
        }

        $lines = [
            '# Whether requests come through Cloudflare. Its addresses are fetched from Cloudflare, not listed here.',
            'behind_cloudflare: ' . SettingsYaml::quote($settings->isBehindCloudflare()),
            '# Your own reverse proxies (behind Cloudflare, if it is used), whose X-Forwarded-For header is believed.',
            'trusted_proxies: [' . implode(', ', $proxies) . ']',
        ];

        return implode("\n", $lines);
    }


    /**
     * @param SendingSettings $settings
     * @return string - the section as YAML, without its key or indentation.
     */
    public static function sendingToYaml(SendingSettings $settings) : string
    {
        $from = $settings->hasSystemEmailFrom() ? (string)$settings->getSystemEmailFrom() : '';

        $lines = [
            '# The address the service\'s own emails (password reset links) are sent from.',
            'system_email_from: ' . SettingsYaml::quote($from),
            '# How long (0 to 60 seconds) a client waits to be told an email was sent.',
            'sync_wait_seconds: ' . $settings->getSyncWaitSeconds(),
        ];

        return implode("\n", $lines);
    }


    /**
     * @param RetentionSettings $settings
     * @return string - the section as YAML, without its key or indentation.
     */
    public static function retentionToYaml(RetentionSettings $settings) : string
    {
        $policy = $settings->getEmailRetention();

        $lines = [
            '# How many days each part of an email, and the logs, are kept. For content and attachments, -1 is not',
            '# kept, and 0 is as long as what they\'re part of (the record, or the content).',
            'email_record_days: ' . $policy->getRecordDays(),
            'email_content_days: ' . $policy->getContentSetting(),
            'email_attachment_days: ' . $policy->getAttachmentSetting(),
            'log_days: ' . $settings->getLogDays(),
        ];

        return implode("\n", $lines);
    }
}
