<?php

declare(strict_types=1);

namespace KLXM\Restricted\Media;

use KLXM\Restricted\Auth;
use KLXM\Restricted\PermissionManager;
use rex_media;

use function in_array;

class MediaGuard
{
    /**
     * Hooked into MEDIA_IS_LOGGED_IN (or custom download script)
     * Determines if the current user can access a requested media file.
     */
    public static function hasAccess(string $filename): bool
    {
        $media = rex_media::get($filename);
        if (!$media) {
            return true; // Handle missing media normally (404 later)
        }

        $categoryId = (int) $media->getCategoryId();

        // If media has no category, it's public (unless restricted globally, but we keep it simple: no category = public)
        if (0 === $categoryId) {
            return true;
        }

        $auth = new Auth();
        $pm = new PermissionManager();
        $user = $auth->getUser();

        // Check the generic category tree (we reuse 'category' here or use a distinct 'media_category' namespace in the matrix)
        // Let's assume we map mediapool categories via 'media_category' item_type in our Matrix!
        // We need a helper to walk media categories similar to structure categories.

        // Check explicit roles for this category or inherited from parent media categories
        $requiredRoles = $pm->getInheritedRolesForMediaCategory($categoryId);

        // If no explicit rules on media_category anywhere in tree, it is open
        if (empty($requiredRoles)) {
            return true;
        }

        // Explicitly public via pseudo-role
        if (in_array(PermissionManager::ROLE_PUBLIC, $requiredRoles, true)) {
            return true;
        }

        // Guests only
        if (in_array(PermissionManager::ROLE_GUEST, $requiredRoles, true)) {
            return null === $user;
        }

        // Must be logged in from now on
        if (!$user) {
            return false;
        }

        // All logged in users
        if (in_array(PermissionManager::ROLE_LOGGED_IN, $requiredRoles, true)) {
            return true;
        }

        return in_array($user->roleId, $requiredRoles, true);
    }
}
