<?php
/** @var rex_fragment $this */
?>
<div class="klxm-restricted-profile max-w-lg mx-auto mt-6 space-y-8">
    
    <?php if ($this->getVar('error')): ?>
        <div class="p-3 bg-red-100 text-red-700 rounded-md text-sm">
            <?= htmlspecialchars($this->getVar('error')) ?>
        </div>
    <?php endif; ?>
    <?php if ($this->getVar('success')): ?>
        <div class="p-3 bg-green-100 text-green-700 rounded-md text-sm">
            <?= htmlspecialchars($this->getVar('success')) ?>
        </div>
    <?php endif; ?>

    <!-- Profildaten -->
    <div class="bg-white p-6 rounded-lg shadow-md">
        <h4 class="text-lg font-medium text-gray-900 mb-4">Profildaten bearbeiten</h4>
        <form action="<?= $this->getVar('action_url') ?>" method="post" class="space-y-4">
            <input type="hidden" name="klxm_action" value="update_profile">
            
            <div>
                <label for="klxm-prof-firstname" class="block text-sm font-medium text-gray-700">Vorname</label>
                <input type="text" id="klxm-prof-firstname" name="firstname" required value="<?= htmlspecialchars($this->getVar('firstname', '')) ?>"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2">
            </div>
            
            <div>
                <label for="klxm-prof-lastname" class="block text-sm font-medium text-gray-700">Nachname</label>
                <input type="text" id="klxm-prof-lastname" name="lastname" required value="<?= htmlspecialchars($this->getVar('lastname', '')) ?>"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2">
            </div>
            
            <div>
                <label for="klxm-prof-email" class="block text-sm font-medium text-gray-700">E-Mail</label>
                <input type="email" id="klxm-prof-email" name="email" required value="<?= htmlspecialchars($this->getVar('email', '')) ?>"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2">
            </div>
            
            <button type="submit" 
                    class="inline-flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                Profil speichern
            </button>
        </form>
    </div>

    <!-- Passwort -->
    <div class="bg-white p-6 rounded-lg shadow-md">
        <h4 class="text-lg font-medium text-gray-900 mb-4">Passwort ändern</h4>
        <form action="<?= $this->getVar('action_url') ?>" method="post" class="space-y-4">
            <input type="hidden" name="klxm_action" value="update_password">
            
            <div>
                <label for="klxm-prof-oldpass" class="block text-sm font-medium text-gray-700">Aktuelles Passwort</label>
                <input type="password" id="klxm-prof-oldpass" name="old_password" required
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2">
            </div>
            
            <div>
                <label for="klxm-prof-newpass" class="block text-sm font-medium text-gray-700">Neues Passwort</label>
                <input type="password" id="klxm-prof-newpass" name="new_password" required
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2">
            </div>
            
            <button type="submit" 
                    class="inline-flex justify-center py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                Passwort ändern
            </button>
        </form>
    </div>
</div>