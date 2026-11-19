<?php

/*
 * Thrown when something can't be done to an account in the state it's in now, e.g. deleting one that isn't archived.
 * The message says what to do instead. The API responds with a 409 Conflict, and the problem's error code.
 */

declare(strict_types = 1);


class AccountStateException extends Exception
{
    private readonly AccountStateProblem $m_problem;


    /**
     * @param AccountStateProblem $problem - what's wrong.
     *
     * @param string $message - what's wrong, and what to do instead, which is shown to whoever asked.
     */
    public function __construct(AccountStateProblem $problem, string $message)
    {
        parent::__construct($message);
        $this->m_problem = $problem;
    }


    # Accessors
    public function getProblem() : AccountStateProblem { return $this->m_problem; }
}
