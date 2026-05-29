<?php
/** @var rex_fragment $this */
?>
<div class="klxm-restricted-login">
    <form action="<?= $this->getVar('action_url') ?>" method="post">
        <div class="mb-3">
            <label for="klxm-email" class="form-label">E-Mail</label>
            <input type="email" class="form-control" id="klxm-email" name="klxm_login_email" required>
        </div>
        <div class="mb-3">
            <label for="klxm-password" class="form-label">Passwort</label>
            <input type="password" class="form-control" id="klxm-password" name="klxm_login_password" required>
        </div>
        <?php if ($this->getVar('error')): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($this->getVar('error')) ?></div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">Login</button>
        <?php if ($this->getVar('passkey_enabled')): ?>
            <button type="button" class="btn btn-outline-secondary" id="klxm-passkey-login">Mit Passkey anmelden</button>
        <?php endif; ?>
    </form>
</div>
