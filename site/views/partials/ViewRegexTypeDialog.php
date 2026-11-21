<?php

/*
 * The dialog that asks which kind of regular expression the Logs page's message search is, when it's switched on, with
 * what to know about each kind (see RegexTypePicker.ts).
 */

declare(strict_types = 1);


class ViewRegexTypeDialog extends Programster\AbstractView\AbstractView
{
    private readonly MessageSearchSyntax $m_current;


    /**
     * @param MessageSearchSyntax $current - the syntax in use now.
     */
    public function __construct(MessageSearchSyntax $current)
    {
        $this->m_current = $current;
    }


    protected function renderContent() : void
    {
        $chosen = $this->m_current->isRegex() ? $this->m_current : MessageSearchSyntax::POSTGRESQL;
?>
<dialog id="regex-type-dialog" class="help-dialog" aria-labelledby="regex-type-title">
    <div class="dialog-header">
        <h3 id="regex-type-title">Which kind of regular expression?</h3>
        <button type="button" class="icon-button neutral" data-role="cancel" aria-label="Close"
            title="Close"><?= new ViewIcon(Icon::CLOSE) ?></button>
    </div>
    <label for="regex-type">Regular expression type</label>
    <select id="regex-type" data-role="regex-select">
        <?php foreach (MessageSearchSyntax::cases() as $syntax): ?>
            <?php if ($syntax->isRegex()): ?>
                <option value="<?= Html::e($syntax->value) ?>"<?= ($syntax === $chosen) ? ' selected' : '' ?>><?=
                    Html::e($syntax->getLabel()) ?></option>
            <?php endif; ?>
        <?php endforeach; ?>
    </select>
    <div class="regex-info" data-role="regex-info" data-syntax="postgresql"<?=
        ($chosen === MessageSearchSyntax::POSTGRESQL) ? '' : ' hidden' ?>>
        <p>Run exactly as you type it, with no delimiters, e.g. <code>^failed (web )?login</code>.</p>
        <ul>
            <li>Word boundaries are <code>\y</code>, or <code>\m</code> and <code>\M</code> for a word's start and
                end. <code>\b</code> means a backspace here, so it usually finds nothing.</li>
            <li><code>.</code> also matches a line break.</li>
        </ul>
        <p><a href="https://www.postgresql.org/docs/current/functions-matching.html#FUNCTIONS-POSIX-REGEXP"
            target="_blank" rel="noopener">PostgreSQL's regular expression syntax</a></p>
    </div>
    <div class="regex-info" data-role="regex-info" data-syntax="php"<?=
        ($chosen === MessageSearchSyntax::PHP) ? '' : ' hidden' ?>>
        <p>With or without delimiters, e.g. <code>/^failed\b/i</code> or <code>^failed\b</code>. It's converted to
            PostgreSQL's syntax, and the page shows what was run.</p>
        <ul>
            <li>The flags <code>i</code>, <code>m</code>, <code>s</code>, <code>u</code> and <code>D</code> work.</li>
            <li>Possessive quantifiers (<code>a++</code>), atomic groups (<code>(?&gt;...)</code>), recursion,
                conditionals and <code>\p{...}</code> have no equivalent, so the page says so instead of searching.
                </li>
        </ul>
        <p><a href="https://www.php.net/manual/en/reference.pcre.pattern.syntax.php" target="_blank"
            rel="noopener">PHP's regular expression syntax</a></p>
    </div>
    <div class="regex-info" data-role="regex-info" data-syntax="javascript"<?=
        ($chosen === MessageSearchSyntax::JAVASCRIPT) ? '' : ' hidden' ?>>
        <p>With or without the slashes, e.g. <code>/^failed\b/i</code> or <code>^failed\b</code>. It's converted to
            PostgreSQL's syntax, and the page shows what was run.</p>
        <ul>
            <li>The flags <code>d</code>, <code>g</code>, <code>i</code>, <code>m</code>, <code>s</code> and
                <code>u</code> work.</li>
            <li>The <code>y</code> and <code>v</code> flags, <code>\p{...}</code>, and an empty <code>[]</code> or
                <code>[^]</code> have no equivalent, so the page says so instead of searching.</li>
        </ul>
        <p><a href="https://developer.mozilla.org/en-US/docs/Web/JavaScript/Guide/Regular_expressions"
            target="_blank" rel="noopener">JavaScript's regular expression syntax</a></p>
    </div>
    <p class="hint">Whichever you choose, case is always ignored, and only the message is searched.</p>
    <div class="form-actions">
        <button type="button" data-role="confirm">Use this type</button>
        <button type="button" class="secondary" data-role="dismiss">Cancel</button>
    </div>
</dialog>
<?php
    }
}
