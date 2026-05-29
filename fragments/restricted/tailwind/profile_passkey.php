<?php
/** @var rex_fragment $this */
?>
<div class="klxm-restricted-passkey-manager mt-6 max-w-sm mx-auto bg-white p-6 rounded-lg shadow-md">
    <h4 class="text-lg font-medium text-gray-900 mb-4">Meine Passkeys</h4>
    
    <?php if ($this->getVar('passkeys') && count($this->getVar('passkeys')) > 0): ?>
        <ul class="divide-y divide-gray-200 mb-4">
            <?php foreach ($this->getVar('passkeys') as $passkey): ?>
                <li class="py-3 flex justify-between items-center">
                    <div>
                        <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($passkey['name']) ?></p>
                        <p class="text-sm text-gray-500">Erstellt am: <?= htmlspecialchars($passkey['created_at']) ?></p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="text-sm text-gray-500 mb-4">Noch keine Passkeys eingerichtet.</p>
    <?php endif; ?>

    <button type="button" id="klxm-passkey-register"
            class="inline-flex justify-center rounded-md border border-transparent bg-indigo-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
        Neuen Passkey hinzufügen
    </button>
</div>