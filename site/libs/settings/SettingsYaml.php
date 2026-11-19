<?php

/*
 * What the parsers of settings.yaml's sections share: checking a section's keys, reading its values, and writing them
 * back out as YAML.
 */

declare(strict_types = 1);


final class SettingsYaml
{
    /**
     * Check a section is a mapping with only the keys it allows, so a typo (e.g. "client_ids") is reported, rather than
     * silently ignored.
     *
     * @param mixed $data - the section, as decoded.
     *
     * @param SettingsSection $section - which section it is, for the message.
     *
     * @param string ...$allowed - the keys it can have.
     *
     * @return array<string, mixed> - the section.
     *
     * @throws InvalidInputException - if it isn't a mapping, or has a key it doesn't allow.
     */
    public static function readMapping(mixed $data, SettingsSection $section, string ...$allowed) : array
    {
        if (!is_array($data) || (count($data) > 0 && array_is_list($data)))
        {
            $example = "{$allowed[0]}: ...";
            throw new InvalidInputException("\"{$section->value}\" must be a set of settings, e.g. \"{$example}\".");
        }

        $unknown = array_diff(array_map('strval', array_keys($data)), $allowed);

        if (count($unknown) > 0)
        {
            $known = implode(', ', $allowed);
            $names = implode('", "', $unknown);
            $msg = "\"{$section->value}\" has no \"{$names}\" setting. Its settings are: {$known}.";
            throw new InvalidInputException($msg);
        }

        return $data;
    }


    /**
     * Read a whole number setting, or its default if it isn't given.
     *
     * @param array<string, mixed> $data - the section.
     *
     * @param SettingsSection $section - which section it is, for the message.
     *
     * @param string $key - the setting.
     *
     * @param int $default - the value when it isn't given.
     *
     * @return int
     *
     * @throws InvalidInputException - if it's given, but isn't a whole number.
     */
    public static function readInt(array $data, SettingsSection $section, string $key, int $default) : int
    {
        $value = $data[$key] ?? $default;

        if (!is_int($value))
        {
            throw new InvalidInputException("\"{$section->value}.{$key}\" must be a whole number.");
        }

        return $value;
    }


    /**
     * Quote a value for YAML. A JSON string is a valid YAML double-quoted string, so json_encode() is used.
     * @param string|int|bool $value
     * @return string
     */
    public static function quote(string|int|bool $value) : string
    {
        return is_string($value)
            ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : var_export($value, true);
    }
}
