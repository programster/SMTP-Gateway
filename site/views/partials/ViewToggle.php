<?php

/*
 * A toggle switch: an on/off setting, drawn as a track whose knob slides across when it's switched. Underneath, it's
 * an ordinary checkbox (with role="switch"), so it's sent with the form, and works with the keyboard and screen
 * readers, exactly as a checkbox does.
 */

declare(strict_types = 1);


class ViewToggle extends Programster\AbstractView\AbstractView
{
    private readonly string $m_name;
    private readonly string $m_label;
    private readonly bool $m_isOn;
    private readonly string $m_hint;


    /**
     * @param string $name - the form field's name. It's sent as "1" when switched on, and not at all when off.
     *
     * @param string $label - what it switches, e.g. "This site is behind Cloudflare".
     *
     * @param bool $isOn - whether it starts switched on.
     *
     * @param string $hint - optionally, a line under the label saying more about it, or an empty string for none.
     */
    public function __construct(string $name, string $label, bool $isOn, string $hint = '')
    {
        $this->m_name = $name;
        $this->m_label = $label;
        $this->m_isOn = $isOn;
        $this->m_hint = $hint;
    }


    protected function renderContent() : void
    {
?>
<label class="toggle"><input type="checkbox" role="switch" name="<?= Html::e($this->m_name) ?>" value="1"<?=
    $this->m_isOn ? ' checked' : '' ?>><span class="toggle-track" aria-hidden="true"></span><span
    class="toggle-label"><?= Html::e($this->m_label) ?><?php if ($this->m_hint !== ''): ?><span
    class="hint"><?= Html::e($this->m_hint) ?></span><?php endif; ?></span></label>
<?php
    }
}
