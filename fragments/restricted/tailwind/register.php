<?php
/** @var rex_fragment $this */
?>
<div class="klxm-restricted-register max-w-md mx-auto bg-white p-6 rounded-lg shadow-md mt-6">
    <?php if ($this->getVar('error')): ?>
        <div class="mb-4 p-3 bg-red-100 text-red-700 rounded-md text-sm">
            <?= htmlspecialchars($this->getVar('error')) ?>
        </div>
    <?php endif; ?>
    <?php if ($this->getVar('success')): ?>
        <div class="mb-4 p-3 bg-green-100 text-green-700 rounded-md text-sm">
            <?= htmlspecialchars($this->getVar('success')) ?>
        </div>
    <?php else: ?>
        <form action="<?= $this->getVar('action_url') ?>" method="post" class="space-y-4">
            <input type="hidden" name="klxm_action" value="register">
            
            <div>
                <label for="klxm-reg-firstname" class="block text-sm font-medium text-gray-700">Vorname</label>
                <input type="text" id="klxm-reg-firstname" name="firstname" required value="<?= htmlspecialchars($this->getVar('firstname', '')) ?>"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2">
            </div>
            
            <div>
                <label for="klxm-reg-lastname" class="block text-sm font-medium text-gray-700">Nachname</label>
                <input type="text" id="klxm-reg-lastname" name="lastname" required value="<?= htmlspecialchars($this->getVar('lastname', '')) ?>"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2">
            </div>
            
            <div>
                <label for="klxm-reg-email" class="block text-sm font-medium text-gray-700">E-Mail</label>
                <input type="email" id="klxm-reg-email" name="email" required value="<?= htmlspecialchars($this->getVar('email', '')) ?>"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2">
            </div>
            
            <div>
                <label for="klxm-reg-password" class="block text-sm font-medium text-gray-700">Passwort</label>
                <input type="password" id="klxm-reg-password" name="password" required
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2">
            </div>
            
            <button type="submit" 
                    class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                Registrieren
            </button>
        </form>
    <?php endif; ?>
</div>