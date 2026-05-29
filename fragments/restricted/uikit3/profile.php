<?php
/** @var rex_fragment $this */
?>
<div class="klxm-restricted-profile uk-margin">
    <?php if ($this->getVar('error')): ?>
        <div class="uk-alert-danger" uk-alert><p><?= htmlspecialchars($this->getVar('error')) ?></p></div>
    <?php endif; ?>
    <?php if ($this->getVar('success')): ?>
        <div class="uk-alert-success" uk-alert><p><?= htmlspecialchars($this->getVar('success')) ?></p></div>
    <?php endif; ?>

    <h4>Profildaten bearbeiten</h4>
    <form action="<?= $this->getVar('action_url') ?>" method="post" class="uk-form-stacked uk-margin-bottom">
        <input type="hidden" name="klxm_action" value="update_profile">
        <div class="uk-margin">
            <label for="klxm-prof-firstname" class="uk-form-label">Vorname</label>
            <div class="uk-form-controls">
                <input type="text" class="uk-input" id="klxm-prof-firstname" name="firstname" required value="<?= htmlspecialchars($this->getVar('firstname', '')) ?>">
            </div>
        </div>
        <div class="uk-margin">
            <label for="klxm-prof-lastname" class="uk-form-label">Nachname</label>
            <div class="uk-form-controls">
                <input type="text" class="uk-input" id="klxm-prof-lastname" name="lastname" required value="<?= htmlspecialchars($this->getVar('lastname', '')) ?>">
            </div>
        </div>
        <div class="uk-margin">
            <label for="klxm-prof-email" class="uk-form-label">E-Mail</label>
            <div class="uk-form-controls">
                <input type="email" class="uk-input" id="klxm-prof-email" name="email" required value="<?= htmlspecialchars($this->getVar('email', '')) ?>">
            </div>
        </div>
        <div class="uk-margin">
            <button type="submit" class="uk-button uk-button-primary">Profil speichern</button>
        </div>
    </form>

    <hr class="uk-divider-icon">

    <h4>Passwort ändern</h4>
    <form action="<?= $this->getVar('action_url') ?>" method="post" class="uk-form-stacked">
        <input type="hidden" name="klxm_action" value="update_password">
        <div class="uk-margin">
            <label for="klxm-prof-oldpass" class="uk-form-label">Aktuelles Passwort</label>
            <div class="uk-form-controls">
                <input type="password" class="uk-input" id="klxm-prof-oldpass" name="old_password" required>
            </div>
        </div>
        <div class="uk-margin">
            <label for="klxm-prof-newpass" class="uk-form-label">Neues Passwort</label>
            <div class="uk-form-controls">
                <input type="password" class="uk-input" id="klxm-prof-newpass" name="new_password" required>
            </div>
        </div>
        <div class="uk-margin">
            <button type="submit" class="uk-button uk-button-default">Passwort ändern</button>
        </div>
    </form>
</div>