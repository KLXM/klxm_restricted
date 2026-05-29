<?php
/** @var rex_fragment $this */
?>
<div class="klxm-restricted-register uk-margin">
    <?php if ($this->getVar('error')): ?>
        <div class="uk-alert-danger" uk-alert><p><?= htmlspecialchars($this->getVar('error')) ?></p></div>
    <?php endif; ?>
    <?php if ($this->getVar('success')): ?>
        <div class="uk-alert-success" uk-alert><p><?= htmlspecialchars($this->getVar('success')) ?></p></div>
    <?php else: ?>
        <form action="<?= $this->getVar('action_url') ?>" method="post" class="uk-form-stacked">
            <input type="hidden" name="klxm_action" value="register">
            
            <div class="uk-margin">
                <label for="klxm-reg-firstname" class="uk-form-label">Vorname</label>
                <div class="uk-form-controls">
                    <input type="text" class="uk-input" id="klxm-reg-firstname" name="firstname" required value="<?= htmlspecialchars($this->getVar('firstname', '')) ?>">
                </div>
            </div>
            
            <div class="uk-margin">
                <label for="klxm-reg-lastname" class="uk-form-label">Nachname</label>
                <div class="uk-form-controls">
                    <input type="text" class="uk-input" id="klxm-reg-lastname" name="lastname" required value="<?= htmlspecialchars($this->getVar('lastname', '')) ?>">
                </div>
            </div>
            
            <div class="uk-margin">
                <label for="klxm-reg-email" class="uk-form-label">E-Mail</label>
                <div class="uk-form-controls">
                    <input type="email" class="uk-input" id="klxm-reg-email" name="email" required value="<?= htmlspecialchars($this->getVar('email', '')) ?>">
                </div>
            </div>
            
            <div class="uk-margin">
                <label for="klxm-reg-password" class="uk-form-label">Passwort</label>
                <div class="uk-form-controls">
                    <input type="password" class="uk-input" id="klxm-reg-password" name="password" required>
                </div>
            </div>
            
            <div class="uk-margin">
                <button type="submit" class="uk-button uk-button-primary">Registrieren</button>
            </div>
        </form>
    <?php endif; ?>
</div>