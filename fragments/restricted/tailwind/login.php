<?php
/** @var rex_fragment $this */
?>
<div class="klxm-restricted-login max-w-sm mx-auto bg-white p-6 rounded-lg shadow-md">
    <form action="<?= $this->getVar('action_url') ?>" method="post" class="space-y-4">
        <div>
            <label for="klxm-email" class="block text-sm font-medium text-gray-700">E-Mail</label>
            <input type="email" id="klxm-email" name="klxm_login_email" required
                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2">
        </div>
        
        <div>
            <label for="klxm-password" class="block text-sm font-medium text-gray-700">Passwort</label>
            <input type="password" id="klxm-password" name="klxm_login_password" required
                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2">
        </div>
        
        <?php if ($this->getVar('error')): ?>
            <div class="p-3 bg-red-100 text-red-700 rounded-md text-sm">
                <?= htmlspecialchars($this->getVar('error')) ?>
            </div>
        <?php endif; ?>
        
        <div class="flex items-center gap-3">
            <button type="submit" 
                    class="inline-flex justify-center rounded-md border border-transparent bg-indigo-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                Login
            </button>
            <?php if ($this->getVar('passkey_enabled')): ?>
                <button type="button" id="klxm-passkey-login"
                        class="inline-flex justify-center rounded-md border border-gray-300 bg-white py-2 px-4 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Mit Passkey anmelden
                </button>
            <?php endif; ?>
        </div>
    </form>
</div>
