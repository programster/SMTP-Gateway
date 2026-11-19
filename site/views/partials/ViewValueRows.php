<?php

/*
 * A list on a form as rows of one value each, e.g. the IP addresses an email token may be used from: a row for each
 * value (at least one, which may be empty), and an "Add" button, which adds another once the last row is filled in
 * (see DynamicRows.ts). Each row is checked as it's typed (see ValueRowCheck), and has a button to remove it. The rows
 * are sent as "<prefix>_0", "<prefix>_1" and so on, and read back with PolicyInput::readValueRowsFromForm().
 */

declare(strict_types = 1);


class ViewValueRows extends Programster\AbstractView\AbstractView
{
    public const string TEMPLATE_INDEX = '__INDEX__';

    private readonly string $m_prefix;
    private readonly string $m_label;
    private readonly ValueRowCheck $m_check;
    private readonly string $m_placeholder;

    /** @var list<string> */
    private readonly array $m_values;


    /**
     * @param string $prefix - the rows' name, e.g. "allowed_ips".
     *
     * @param string $label - what the list is, for screen readers, e.g. "Allowed IP address".
     *
     * @param ValueRowCheck $check - how each row is checked as it's typed.
     *
     * @param string $placeholder - what an empty row shows, e.g. "10.0.0.0/8".
     *
     * @param string ...$values - the rows' values. With none, there's one empty row.
     */
    public function __construct(
        string $prefix,
        string $label,
        ValueRowCheck $check,
        string $placeholder,
        string ...$values
    )
    {
        $this->m_prefix = $prefix;
        $this->m_label = $label;
        $this->m_check = $check;
        $this->m_placeholder = $placeholder;
        $this->m_values = (count($values) === 0) ? [''] : array_values($values);
    }


    protected function renderContent() : void
    {
?>
<div class="dynamic-rows" data-role="dynamic-rows" data-next-index="<?= count($this->m_values) ?>"
    data-max-rows="<?= PolicyInput::MAX_FORM_ROWS ?>">
    <div data-role="rows">
    <?php foreach ($this->m_values as $index => $value): ?>
        <?php $this->renderRow((string)$index, $value); ?>
    <?php endforeach; ?>
    </div>
    <template data-role="row-template"><?php $this->renderRow(self::TEMPLATE_INDEX, ''); ?></template>
    <button type="button" class="secondary" data-role="add-row">Add</button>
</div>
<?php
    }


    private function renderRow(string $index, string $value) : void
    {
        $name = "{$this->m_prefix}_{$index}";
?>
<div class="dynamic-row" data-role="row">
    <input type="text" name="<?= Html::e($name) ?>" value="<?= Html::e($value) ?>" data-row-value
        data-check="<?= $this->m_check->value ?>" placeholder="<?= Html::e($this->m_placeholder) ?>"
        aria-label="<?= Html::e($this->m_label) ?>" autocomplete="off">
    <button type="button" class="icon-button" data-role="remove-row" aria-label="Remove this row"
        title="Remove"><?= new ViewIcon(Icon::TRASH) ?></button>
    <span class="row-error" data-role="row-error" aria-live="polite"></span>
</div>
<?php
    }
}
