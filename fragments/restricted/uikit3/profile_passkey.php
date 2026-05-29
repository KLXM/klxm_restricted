<?php
/** @var rex_fragment $this */
?>
<div class="klxm-restricted-passkey-manager uk-margin-top">
    <h4>Meine Passkeys</h4>
    
    <?php if ($this->getVar('passkeys') && count($this->getVar('passkeys')) > 0): ?>
        <ul class="uk-list uk-list-divider uk-margin-bottom">
            <?php foreach ($this->getVar('passkeys') as $passkey): ?>
                <li>
                    <div class="uk-flex uk-flex-between uk-flex-middle">
                        <div>
                            <strong><?= htmlspecialchars($passkey['name']) ?></strong><br>
                            <span class="uk-text-small uk-text-muted">Erstellt am: <?= htmlspecialchars($passkey['created_at']) ?></span>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="uk-text-muted">Noch keine Passkeys eingerichtet.</p>
    <?php endif; ?>

    <button type="button" class="uk-button uk-button-primary" id="klxm-passkey-register">Neuen Passkey hinzufügen</button>
</div>