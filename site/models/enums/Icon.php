<?php

/*
 * An icon: one of the Phosphor icons in public/vendor/phosphor-icons/regular, each named by its file (see the README
 * there), or none (e.g. for a link in a section of the side panel's menu). See ViewIcon.
 */

declare(strict_types = 1);


enum Icon : string
{
    case NONE = '';
    case DASHBOARD = 'squares-four';
    case ACTIVITY = 'envelope-simple';
    case RATE_LIMITS = 'gauge';
    case ACCESS = 'key';
    case PROVIDERS = 'paper-plane-tilt';
    case LOGS = 'scroll';
    case SETTINGS = 'gear-six';
    case DOCUMENTATION = 'book-open';
    case ACCOUNT = 'user-circle';
    case LOG_OUT = 'sign-out';
    case EDIT = 'pencil-simple';
    case TRASH = 'trash';
    case DRAG_HANDLE = 'dots-six-vertical';
    case HELP = 'question';
    case CLOSE = 'x';
    case BLOCK = 'prohibit';
    case UNBLOCK = 'check-circle';
    case ARCHIVE = 'archive';
    case RESTORE = 'arrow-counter-clockwise';
    case CARET_RIGHT = 'caret-right';


    /**
     * Get the path of the icon's SVG file.
     * @return string
     * @throws LogicException - if it's NONE, which has no file.
     */
    public function getFilePath() : string
    {
        if ($this === Icon::NONE)
        {
            throw new LogicException('Icon::NONE has no file.');
        }

        return __DIR__ . "/../../public/vendor/phosphor-icons/regular/{$this->value}.svg";
    }
}
