<?php
/** @var rex_fragment $this */
?>
<div class="klxm-restricted-login uk-margin">
    <form action="<?= $this->getVar('action_url') ?>" method="post" class="uk-form-stacked">
        <div class="uk-margin">
            <label for="klxm-email" class="uk-form-label">E-Mail</label>
            <div class="uk-form-controls">
                <input type="email" class="uk-input" id="klxm-email" name="klxm_login_email" required>
            </div>
        </div>
        <div class="uk-margin">
            <label for="klxm-password" class="uk-form-label">Passwort</label>
            <div class="uk-form-controls">
                <input type="password" class="uk-input" id="klxm-password" name="klxm_login_password" required>
            </div>
        </div>
        <?php if ($this->getVar('error')): ?>
            <div class="uk-alert-danger" uk-alert>
                <p><?= htmlspecialchars($this->getVar('error')) ?></p>
            </div>
        <?php endif; ?>
        <div class="uk-margin">
            <button type="submit" class="uk-button uk-button-primary">Login</button>
            <?php if ($this->getVar('passkey_enabled')): ?>
                <button type="button" class="uk-button uk-button-default" id="klxm-passkey-login">Mit Passkey anmelden</button>
            <?php endif; ?>
        </div>
    </form>
</div>
