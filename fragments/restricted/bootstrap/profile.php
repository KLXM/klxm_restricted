<?php
/** @var rex_fragment $this */
?>
<div class="klxm-restricted-profile">
    <?php if ($this->getVar('error')): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($this->getVar('error')) ?></div>
    <?php endif; ?>
    <?php if ($this->getVar('success')): ?>
        <div class="alert alert-success"><?= htmlspecialchars($this->getVar('success')) ?></div>
    <?php endif; ?>

    <h4>Profildaten bearbeiten</h4>
    <form action="<?= $this->getVar('action_url') ?>" method="post" class="mb-5">
        <input type="hidden" name="klxm_action" value="update_profile">
        <div class="mb-3">
            <label for="klxm-prof-firstname" class="form-label">Vorname</label>
            <input type="text" class="form-control" id="klxm-prof-firstname" name="firstname" required value="<?= htmlspecialchars($this->getVar('firstname', '')) ?>">
        </div>
        <div class="mb-3">
            <label for="klxm-prof-lastname" class="form-label">Nachname</label>
            <input type="text" class="form-control" id="klxm-prof-lastname" name="lastname" required value="<?= htmlspecialchars($this->getVar('lastname', '')) ?>">
        </div>
        <div class="mb-3">
            <label for="klxm-prof-email" class="form-label">E-Mail</label>
            <input type="email" class="form-control" id="klxm-prof-email" name="email" required value="<?= htmlspecialchars($this->getVar('email', '')) ?>">
        </div>
        <button type="submit" class="btn btn-primary">Profil speichern</button>
    </form>

    <hr>

    <h4>Passwort ändern</h4>
    <form action="<?= $this->getVar('action_url') ?>" method="post">
        <input type="hidden" name="klxm_action" value="update_password">
        <div class="mb-3">
            <label for="klxm-prof-oldpass" class="form-label">Aktuelles Passwort</label>
            <input type="password" class="form-control" id="klxm-prof-oldpass" name="old_password" required>
        </div>
        <div class="mb-3">
            <label for="klxm-prof-newpass" class="form-label">Neues Passwort</label>
            <input type="password" class="form-control" id="klxm-prof-newpass" name="new_password" required>
        </div>
        <button type="submit" class="btn btn-secondary">Passwort ändern</button>
    </form>
</div>