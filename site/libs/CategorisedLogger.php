<?php

/*
 * A logger that adds a category (see LogCategory) to the context of everything it logs, as "category", so the logs
 * can be filtered by it. See ServiceFactory::getLogger().
 */

declare(strict_types = 1);


final class CategorisedLogger implements \Psr\Log\LoggerInterface
{
    use \Psr\Log\LoggerTrait;

    private readonly \Psr\Log\LoggerInterface $m_logger;
    private readonly LogCategory $m_category;


    /**
     * @param \Psr\Log\LoggerInterface $logger - the logger that writes the entries.
     *
     * @param LogCategory $category - what they're about.
     */
    public function __construct(\Psr\Log\LoggerInterface $logger, LogCategory $category)
    {
        $this->m_logger = $logger;
        $this->m_category = $category;
    }


    /**
     * @param mixed $level - the entry's level, e.g. Psr\Log\LogLevel::INFO.
     *
     * @param string|\Stringable $message - the message.
     *
     * @param array<string, mixed> $context - anything else about it. Its "category" is set to this logger's.
     *
     * @return void
     */
    public function log($level, string|\Stringable $message, array $context = []) : void
    {
        $this->m_logger->log($level, $message, ['category' => $this->m_category->value] + $context);
    }
}
