<?php

/*
 * The application's logs, a page at a time: filtered by time, by text (or a regular expression) in the message, and by
 * level, and sorted by time or level (by clicking those columns' headings). Each entry's time is shown as how long ago
 * it was, with the time itself beside it, in the configured time zone. Clicking an entry shows it in full, with its
 * context (ViewLogEntryPage), and as each is a link, it can be opened in a new tab.
 */

declare(strict_types = 1);


class ViewLogsPage extends Programster\AbstractView\AbstractView
{
    private const string SEARCH_HELP_ID = 'log-search-help';
    private const string CONTEXT_HELP_ID = 'log-context-help';

    private readonly LogSearchResult $m_result;
    private readonly LogFilter $m_filter;
    private readonly LogPage $m_page;
    private readonly string $m_from;
    private readonly string $m_to;
    private readonly DateTimeZone $m_timezone;
    private readonly string $m_error;


    /**
     * @param LogSearchResult $result - the page of entries, and how many match in all.
     *
     * @param LogFilter $filter - the filter used.
     *
     * @param LogPage $page - the page shown, and its order.
     *
     * @param string $from - the "from" time as entered, to fill the form with.
     *
     * @param string $to - the "to" time as entered, to fill the form with.
     *
     * @param DateTimeZone $timezone - the time zone the times are in.
     *
     * @param string $error - why the filter was invalid, or an empty string.
     */
    public function __construct(
        LogSearchResult $result,
        LogFilter $filter,
        LogPage $page,
        string $from,
        string $to,
        DateTimeZone $timezone,
        string $error
    )
    {
        $this->m_result = $result;
        $this->m_filter = $filter;
        $this->m_page = $page;
        $this->m_from = $from;
        $this->m_to = $to;
        $this->m_timezone = $timezone;
        $this->m_error = $error;
    }


    protected function renderContent() : void
    {
        $filter = $this->m_filter;
        $entries = $this->m_result->getEntries();
        $levels = $filter->getLevels();
        $categories = $filter->getCategories();
        $chosenCategory = (count($categories) > 0) ? $categories[0] : null;
        $isAdvancedOpen = ($filter->isRegex() || $chosenCategory !== null || $filter->getContext()->getName() !== '');
        $syntax = $filter->getSyntax();
        $isConverted = ($syntax === MessageSearchSyntax::PHP || $syntax === MessageSearchSyntax::JAVASCRIPT);
        $isShowingConverted = ($isConverted && $filter->getSearch() !== '' && $this->m_error === '');
?>
<div class="log-heading">
    <h1>Logs</h1>
    <span class="hint"><?= number_format($this->m_result->getTotalCount()) ?> <?=
        ($this->m_result->getTotalCount() === 1) ? 'entry' : 'entries' ?></span>
</div>
<form method="get" action="/admin/logs" class="card log-filters">
    <div class="log-filters-main">
        <div class="search-field">
            <div class="label-with-help"><label for="search">Message contains</label><?=
                new ViewHelpButton(self::SEARCH_HELP_ID, 'Searching the messages') ?></div>
            <input type="search" id="search" name="search" value="<?= Html::e($filter->getSearch()) ?>"
                placeholder="e.g. failed">
        </div>
        <div class="level-field">
            <span class="field-label" id="level-label">Levels</span>
            <details class="multi-select" id="log-level-dropdown">
                <summary aria-labelledby="level-label level-summary"><span id="level-summary"
                    data-role="level-summary"><?= Html::e($this->describeChosenLevels()) ?></span></summary>
                <div class="multi-select-menu">
                    <?php foreach (LogLevelCollection::createAllFromLeastSevere() as $level): ?>
                        <label class="multi-select-option"><input type="checkbox" name="level[]"
                            value="<?= $level->value ?>" data-label="<?= Html::e(ucfirst($level->value)) ?>"<?=
                            $levels->contains($level) ? ' checked' : '' ?>> <span class="level-dot"
                            data-level="<?= $level->value ?>"></span> <?= Html::e(ucfirst($level->value)) ?></label>
                    <?php endforeach; ?>
                    <p class="hint">Shift-click to select a range.</p>
                </div>
            </details>
        </div>
        <div>
            <label for="from">From</label>
            <input type="datetime-local" id="from" name="from" step="1" value="<?= Html::e($this->m_from) ?>">
        </div>
        <div>
            <label for="to">To</label>
            <input type="datetime-local" id="to" name="to" step="1" value="<?= Html::e($this->m_to) ?>">
        </div>
        <div class="log-filter-actions"><button type="submit">Search</button><?php if ($filter->isFiltered()): ?> <a
            href="/admin/logs">Clear</a><?php endif; ?></div>
    </div>
    <details class="advanced log-advanced"<?= $isAdvancedOpen ? ' open' : '' ?>>
        <summary>Advanced</summary>
        <div class="log-advanced-fields">
            <div>
                <label for="category">Category</label>
                <select id="category" name="category">
                    <option value="">Any category</option>
                    <?php foreach (LogCategory::cases() as $case): ?>
                        <option value="<?= $case->value ?>"<?= ($case === $chosenCategory) ? ' selected' : '' ?>><?=
                            Html::e($case->getLabel()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="context-field">
                <div class="label-with-help"><label for="context_name">Context contains</label><?=
                    new ViewHelpButton(self::CONTEXT_HELP_ID, 'Searching the context') ?></div>
                <div class="context-inputs">
                    <input type="text" id="context_name" name="context_name" value="<?= Html::e(
                        $filter->getContext()->getName()
                    ) ?>" placeholder="name, e.g. email" aria-label="The name in the context" spellcheck="false">
                    <input type="text" id="context_value" name="context_value" value="<?= Html::e(
                        $filter->getContext()->getValue()
                    ) ?>" placeholder="value (optional)" aria-label="Text its value contains" spellcheck="false">
                </div>
            </div>
            <div class="regex-option" id="regex-option">
                <input type="hidden" name="regex" value="<?= Html::e($syntax->value) ?>" data-role="regex-value">
                <label class="toggle"><input type="checkbox" role="switch" data-role="regex-toggle"<?=
                    $filter->isRegex() ? ' checked' : '' ?>><span class="toggle-track" aria-hidden="true"></span><span
                    class="toggle-label">Message is a regular expression</span></label>
                <strong data-role="regex-label"><?= $filter->isRegex() ? Html::e($syntax->getLabel()) : '' ?></strong>
                <button type="button" class="secondary regex-change" data-role="regex-change"<?=
                    $filter->isRegex() ? '' : ' hidden' ?>>Change</button>
            </div>
        </div>
        <?php if ($isShowingConverted): ?>
            <p class="hint">Run in PostgreSQL as <code><?= Html::e($filter->getPostgresPattern()) ?></code></p>
        <?php endif; ?>
    </details>
    <?php // Searching keeps the order and page size, and starts at the first page. ?>
    <?php foreach (array_diff_key($this->m_page->toQueryParams(), ['page' => '']) as $name => $value): ?>
        <input type="hidden" name="<?= Html::e($name) ?>" value="<?= Html::e($value) ?>">
    <?php endforeach; ?>
</form>
<?= new ViewHelpDialog(self::SEARCH_HELP_ID, 'Message contains', self::createSearchHelp(), '') ?>
<?= new ViewHelpDialog(self::CONTEXT_HELP_ID, 'Context contains', self::createContextHelp(), '') ?>
<?= new ViewRegexTypeDialog($syntax) ?>
<p class="hint log-timezone">Times are in <?= Html::e($this->m_timezone->getName()) ?>.</p>
<?= new ViewFormError($this->m_error) ?>
<?php if (count($entries) === 0): ?>
    <div class="card log-empty"><p class="muted">No log entries<?= $filter->isFiltered() ? ' match' : ''
        ?><?= ($this->m_page->getNumber() > 1) ? ' on this page' : '' ?>.</p></div>
<?php else: ?>
<div class="table-wrap log-table-wrap">
<table class="log-table">
    <thead><tr>
        <th><?php $this->renderSortLink('Time', false); ?></th>
        <th><?php $this->renderSortLink('Level', true); ?></th>
        <th>Category</th>
        <th>Message</th>
    </tr></thead>
    <tbody>
    <?php foreach ($entries as $entry): ?>
        <?php /* @var $entry LogEntry */ ?>
        <tr class="clickable-row" data-level="<?= Html::e($entry->getLevel()) ?>">
            <td><?= Html::e(Time::describeAgo((float)$entry->getCreatedAt())) ?><br><span
                class="hint mono"><?= Html::e(Time::formatUnixTimestamp($entry->getCreatedAt())) ?></span></td>
            <td><span
                class="badge badge-<?= Html::e($entry->getLevel()) ?>"><?= Html::e($entry->getLevel()) ?></span></td>
            <td class="hint"><?= Html::e($entry->getCategory()) ?></td>
            <td><a class="row-link" href="/admin/logs/<?= Html::e(rawurlencode($entry->getId())) ?>"><?= Html::e(
                $entry->getMessage()
            ) ?></a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php $this->renderPagination(); ?>
<?php
    }


    // What the "Message contains" search does, for its help dialog.
    private static function createSearchHelp() : HelpEntryCollection
    {
        return new HelpEntryCollection(
            new HelpEntry(
                'Plain text',
                'By default, this finds entries whose message contains exactly what you type, anywhere in it, ignoring'
                . ' case. Characters like `%` and `_` are treated as ordinary text.'
            ),
            new HelpEntry(
                'Using a regular expression',
                'Click Advanced, and switch on "Message is a regular expression". It asks which kind you\'re writing:'
                . ' PostgreSQL, PHP or JavaScript. It ignores case, and can match anywhere in the message, unless you'
                . ' use `^` for the start or `$` for the end. If it isn\'t valid, the page says why.'
            ),
            new HelpEntry(
                'Example',
                '`^failed (web )?login` finds messages that start with `failed login` or `failed web login`.'
            ),
            new HelpEntry(
                'What isn\'t searched',
                'Only the message is searched, not the context. To search the context, use "Context contains" under'
                . ' Advanced.'
            )
        );
    }


    // What the "Context contains" search does, for its help dialog.
    private static function createContextHelp() : HelpEntryCollection
    {
        return new HelpEntryCollection(
            new HelpEntry('Name', 'Finds entries whose context has this name, e.g. `email_token_id`.'),
            new HelpEntry('Value', 'Optionally, only those whose value for that name contains this text.')
        );
    }


    /**
     * Describe the levels chosen, for the closed dropdown (LogLevelDropdown keeps it up to date as they're changed).
     * @return string - e.g. "All levels", "Error, Critical" or "3 levels".
     */
    private function describeChosenLevels() : string
    {
        $labels = [];

        foreach (LogLevelCollection::createAllFromLeastSevere() as $level)
        {
            if ($this->m_filter->getLevels()->contains($level))
            {
                $labels[] = ucfirst($level->value);
            }
        }

        $description = match (true) {
            count($labels) === 0 => 'All levels',
            count($labels) <= 2 => implode(', ', $labels),
            default => count($labels) . ' levels',
        };

        return $description;
    }


    /**
     * Render a column's heading as a link that sorts by it, with an arrow if it's sorted by it now, and which way.
     *
     * @param string $label - the heading, e.g. "Time".
     *
     * @param bool $isLevel - whether it's the level's column, rather than the time's.
     *
     * @return void
     */
    private function renderSortLink(string $label, bool $isLevel) : void
    {
        $sort = $this->m_page->getSort();
        $arrow = $sort->getArrowFor($isLevel);
        $path = $this->getPath($this->m_page->withSort($sort->getToggleFor($isLevel)));
        $sorted = ($arrow === '') ? '' : (($arrow === '↓') ? ' aria-sort="descending"' : ' aria-sort="ascending"');
?>
<a class="sort-link" href="<?= Html::e($path) ?>"<?= $sorted ?>><?= Html::e($label) ?><?php if ($arrow !== ''): ?>
    <span aria-hidden="true"><?= $arrow ?></span><?php endif; ?></a>
<?php
    }


    // Render which entries these are of how many, and links to the first, last, nearby, previous and next pages.
    private function renderPagination() : void
    {
        $total = $this->m_result->getTotalCount();
        $size = $this->m_page->getSize()->value;
        $current = $this->m_page->getNumber();
        $lastPage = max(1, (int)ceil($total / $size));
        $shownCount = count($this->m_result->getEntries());
        $first = $this->m_page->getOffset() + 1;
        $last = $this->m_page->getOffset() + $shownCount;
        $numbers = [];

        foreach ([1, $current - 2, $current - 1, $current, $current + 1, $current + 2, $lastPage] as $number)
        {
            if ($number >= 1 && $number <= $lastPage)
            {
                $numbers[$number] = true;
            }
        }

        ksort($numbers);
?>
<nav class="pagination" aria-label="Pages of log entries">
    <?php if ($shownCount > 0): ?>
        <span class="hint">Showing <?= number_format($first) ?>–<?= number_format($last) ?> of
            <?= number_format($total) ?></span>
    <?php else: ?>
        <span class="hint"><?= number_format($total) ?> in all</span>
    <?php endif; ?>
    <?php if ($lastPage > 1): ?>
        <span class="pages">
        <?php if ($current > 1): ?>
            <a href="<?= Html::e($this->getPath($this->m_page->withNumber($current - 1))) ?>" rel="prev">Previous</a>
        <?php endif; ?>
        <?php $previous = 0; ?>
        <?php foreach (array_keys($numbers) as $number): ?>
            <?php if ($number > $previous + 1): ?><span class="muted" aria-hidden="true">…</span><?php endif; ?>
            <?php if ($number === $current): ?>
                <span class="current" aria-current="page"><?= $number ?></span>
            <?php else: ?>
                <a href="<?= Html::e($this->getPath($this->m_page->withNumber($number))) ?>"><?= $number ?></a>
            <?php endif; ?>
            <?php $previous = $number; ?>
        <?php endforeach; ?>
        <?php if ($current < $lastPage): ?>
            <a href="<?= Html::e($this->getPath($this->m_page->withNumber($current + 1))) ?>" rel="next">Next</a>
        <?php endif; ?>
        </span>
    <?php endif; ?>
    <?php $this->renderPageSizeForm(); ?>
</nav>
<script type="module" src="/js/LogsPage.js"></script>
<?php
    }


    // Render the choice of how many entries are on a page, which keeps the filter and order, and goes to page 1.
    private function renderPageSizeForm() : void
    {
        $params = $this->m_filter->toQueryParams() + array_diff_key($this->m_page->toQueryParams(), ['page' => '']);
        unset($params['per_page']);
?>
<form method="get" action="/admin/logs" class="page-size" id="page-size-form">
    <?php foreach ($params as $name => $value): ?>
        <input type="hidden" name="<?= Html::e($name) ?>" value="<?= Html::e($value) ?>">
    <?php endforeach; ?>
    <label for="per_page">Per page</label>
    <select id="per_page" name="per_page" data-role="page-size">
        <?php foreach (LogPageSize::cases() as $size): ?>
            <option value="<?= $size->value ?>"<?= ($size === $this->m_page->getSize()) ? ' selected' : ''
                ?>><?= $size->value ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="secondary" data-role="page-size-submit">Show</button>
</form>
<?php
    }


    // The path of a page of these logs, with this filter.
    private function getPath(LogPage $page) : string
    {
        $query = http_build_query($this->m_filter->toQueryParams() + $page->toQueryParams());
        return '/admin/logs' . (($query === '') ? '' : "?{$query}");
    }
}
