<?php

/*
 * One entry from the "log" table, written by programster/pgsql-logger.
 */

declare(strict_types = 1);


readonly class LogEntry
{
    private string $m_id;
    private string $m_message;
    private string $m_level;
    private string $m_contextJson;
    private string $m_createdAt;


    /**
     * @param array $row - the row from the "log" table: id, message, level, context (as JSON text), and created_at
     * (a unix timestamp with a fraction of a second).
     */
    public function __construct(array $row)
    {
        $this->m_id = (string)$row['id'];
        $this->m_message = (string)$row['message'];
        $this->m_level = (string)$row['level'];
        $this->m_contextJson = (string)$row['context'];
        $this->m_createdAt = (string)$row['created_at'];
    }


    /**
     * Get what the entry is about (from its context's "category"), or an empty string for one logged without one.
     * @return string - its label, e.g. "Changes to the service".
     */
    public function getCategory() : string
    {
        $context = json_decode($this->m_contextJson, true);
        $category = is_array($context) ? LogCategory::tryFrom((string)($context['category'] ?? '')) : null;
        return ($category === null) ? '' : $category->getLabel();
    }


    /**
     * Get the context's top level, each value as text: as it is, or, for a list or object, as JSON.
     * @return array<string, string> - by name.
     */
    public function getContextValues() : array
    {
        $context = json_decode($this->m_contextJson, true);
        $values = [];

        foreach (is_array($context) ? $context : [] as $name => $value)
        {
            $values[(string)$name] = match (true) {
                is_array($value) => (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => 'null',
                default => (string)$value,
            };
        }

        return $values;
    }


    /**
     * Get the context as indented JSON, for showing to a person.
     *
     * @return string - or an empty string if there's no context.
     */
    public function getPrettyContext() : string
    {
        $context = json_decode($this->m_contextJson, true);

        return (is_array($context) && count($context) > 0)
            ? (string)json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';
    }


    # Accessors
    public function getId() : string { return $this->m_id; }
    public function getMessage() : string { return $this->m_message; }
    public function getLevel() : string { return $this->m_level; }
    public function getCreatedAt() : string { return $this->m_createdAt; }
}
