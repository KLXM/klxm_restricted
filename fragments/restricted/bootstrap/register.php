<?php
/** @var rex_fragment $this */
?>
<div class="klxm-restricted-register">
    <?php if ($this->getVar('error')): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($this->getVar('error')) ?></div>
    <?php endif; ?>
    <?php if ($this->getVar('success')): ?>
        <div class="alert alert-success"><?= htmlspecialchars($this->getVar('success')) ?></div>
    <?php else: ?>
        <form action="<?= $this->getVar('action_url') ?>" method="post">
            <input type="hidden" name="klxm_action" value="register">
            <div class="mb-3">
                <label for="klxm-reg-firstname" class="form-label">Vorname</label>
                <input type="text" class="form-control" id="klxm-reg-firstname" name="firstname" required value="<?= htmlspecialchars($this->getVar('firstname', '')) ?>">
            </div>
            <div class="mb-3">
                <label for="klxm-reg-lastname" class="form-label">Nachname</label>
                <input type="text" class="form-control" id="klxm-reg-lastname" name="lastname" required value="<?= htmlspecialchars($this->getVar('lastname', '')) ?>">
            </div>
            <div class="mb-3">
                <label for="klxm-reg-email" class="form-label">E-Mail</label>
                <input type="email" class="form-control" id="klxm-reg-email" name="email" required value="<?= htmlspecialchars($this->getVar('email', '')) ?>">
            </div>
            <div class="mb-3">
                <label for="klxm-reg-password" class="form-label">Passwort</label>
                <input type="password" class="form-control" id="klxm-reg-password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary">Registrieren</button>
        </form>
    <?php endif; ?>
</div>