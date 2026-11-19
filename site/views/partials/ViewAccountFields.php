<?php

/*
 * The fields for an account's email address and password, for adding an account or restoring an archived one: its
 * email address, then either an emailed link to choose its own password, or a password set now (which it can be made
 * to change when it first logs in). NewAccountForm.ts shows the password fields only when they're needed.
 */

declare(strict_types = 1);


class ViewAccountFields extends Programster\AbstractView\AbstractView
{
    private readonly NewAccountForm $m_form;


    /**
     * @param NewAccountForm $form - what to fill the fields with.
     */
    public function __construct(NewAccountForm $form)
    {
        $this->m_form = $form;
    }


    protected function renderContent() : void
    {
        $policy = AdminUserRepository::getPasswordPolicy();
        $minLength = $policy->getMinLength();
        $isLink = ($this->m_form->getPasswordSource() === AccountPasswordSource::EMAILED_LINK);
        $mustChange = $this->m_form->mustChangePassword();
        $inviteHours = intdiv(PasswordResetService::INVITATION_LIFETIME_MINUTES, 60);
?>
    <label for="email">Email address</label>
    <input type="email" id="email" name="email" value="<?= Html::e($this->m_form->getEmail()) ?>" required
        autocomplete="off">
    <p class="hint">What they log in with. They can set up two-factor authentication from "My account".</p>

    <fieldset class="choices">
        <legend>Their password</legend>
        <label class="choice"><input type="radio" name="password_source"
            value="<?= AccountPasswordSource::EMAILED_LINK->value ?>" data-role="password-source"<?= $isLink
            ? ' checked' : '' ?>> <span><strong>Email them a link to choose their own</strong> <span class="hint">It
            works for <?= $inviteHours ?> hours. Needs <code>PUBLIC_URL</code>, and the address the service's own
            emails come from (in the Sending part of Settings &gt; General).</span></span></label>
        <label class="choice"><input type="radio" name="password_source"
            value="<?= AccountPasswordSource::SET_NOW->value ?>" data-role="password-source"<?= $isLink
            ? '' : ' checked' ?>> <span><strong>Set one for them now</strong> <span class="hint">For when they can't
            be emailed. Give it to them somewhere safe.</span></span></label>

        <div class="set-password" data-role="set-password"<?= $isLink ? ' hidden' : '' ?>>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" minlength="<?= $minLength ?>"
                autocomplete="new-password" data-role="password">
            <label for="confirm_password">Password again</label>
            <input type="password" id="confirm_password" name="confirm_password" minlength="<?= $minLength ?>"
                autocomplete="new-password" data-role="password">
            <p class="hint"><?= Html::e($policy->describe()) ?></p>
            <?= new ViewToggle(
                'must_change_password',
                'Make them change it when they first log in',
                $mustChange,
                'So the password they go on using is one only they know, and not one you do.'
            ) ?>
        </div>
    </fieldset>
<?php
    }
}
