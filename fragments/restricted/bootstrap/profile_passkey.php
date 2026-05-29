<?php
/** @var rex_fragment $this */
?>
<div class="klxm-restricted-passkey-manager mt-4">
    <h4>Meine Passkeys</h4>
    
    <?php if ($this->getVar('passkeys') && count($this->getVar('passkeys')) > 0): ?>
        <ul class="list-group mb-3">
            <?php foreach ($this->getVar('passkeys') as $passkey): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong><?= htmlspecialchars($passkey['name']) ?></strong><br>
                        <small class="text-muted">Erstellt am: <?= htmlspecialchars($passkey['created_at']) ?></small>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="text-muted">Noch keine Passkeys eingerichtet.</p>
    <?php endif; ?>

    <button type="button" class="btn btn-primary" id="klxm-passkey-register">Neuen Passkey hinzufügen</button>
</div>