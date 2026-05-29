<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use rex;
use rex_article;
use rex_category;
use rex_media_category;
use rex_sql;

use function in_array;

class PermissionManager
{
    public const ROLE_PUBLIC = 1000000;
    public const ROLE_LOGGED_IN = 1000001;
    public const ROLE_GUEST = 1000002;
    public const REQUEST_ENABLED = 1;

    private static bool $isLoaded = false;
    /** @var array<string, list<int>> */
    private static array $matrixCache = [];

    public function __construct()
    {
        $this->loadMatrix();
    }

    private function loadMatrix(): void
    {
        if (self::$isLoaded) {
            return;
        }

        $sql = rex_sql::factory();
        $rules = $sql->getArray('SELECT item_type, item_id, role_id FROM ' . rex::getTable('klxm_restricted_matrix'));

        foreach ($rules as $rule) {
            $key = $rule['item_type'] . '_' . $rule['item_id'];
            if (!isset(self::$matrixCache[$key])) {
                self::$matrixCache[$key] = [];
            }
            self::$matrixCache[$key][] = (int) $rule['role_id'];
        }

        self::$isLoaded = true;
    }

    /**
     * Checks if a user has access to a specific article.
     * Inherits permissions from parent categories if not explicitly set.
     */
    public function checkArticleAccess(?User $user, int $articleId): bool
    {
        $article = rex_article::get($articleId);
        if (!$article) {
            return true; // Or false depending on strictness, usually 404 handles it anyway
        }

        $categoryId = (int) $article->getCategoryId();
        // REDAXO root start articles can report category ID 0 in some contexts.
        // In that case, map to the article ID because category/start-article IDs are aligned.
        if (0 === $categoryId && $article->isStartArticle()) {
            $categoryId = $articleId;
        }

        $rolesForArticle = $this->getRolesForItem('article', $articleId);
        $rolesForCategory = $this->getInheritedRolesForCategory($categoryId);

        // Focus on explicit article roles if defined, otherwise fall back to category roles
        $requiredRoles = !empty($rolesForArticle) ? $rolesForArticle : $rolesForCategory;

        // If no roles are defined anywhere in the tree, the article is unhandled/public
        if (empty($requiredRoles)) {
            return true;
        }

        // Explicitly public via pseudo-role
        if (in_array(self::ROLE_PUBLIC, $requiredRoles, true)) {
            return true;
        }

        // Guests only
        if (in_array(self::ROLE_GUEST, $requiredRoles, true)) {
            return null === $user;
        }

        // Must be logged in from now on
        if (!$user) {
            return false;
        }

        // All logged in users
        if (in_array(self::ROLE_LOGGED_IN, $requiredRoles, true)) {
            return true;
        }

        return in_array($user->roleId, $requiredRoles, true);
    }

    /**
     * Checks if a user has access to a specific category.
     * Inherits permissions from parent categories if not explicitly set.
     */
    public function checkCategoryAccess(?User $user, int $categoryId): bool
    {
        $category = rex_category::get($categoryId);
        if (!$category) {
            return true;
        }

        $rolesForCategory = $this->getInheritedRolesForCategory($categoryId);

        if (empty($rolesForCategory)) {
            return true;
        }

        // Explicitly public via pseudo-role
        if (in_array(self::ROLE_PUBLIC, $rolesForCategory, true)) {
            return true;
        }

        // Guests only
        if (in_array(self::ROLE_GUEST, $rolesForCategory, true)) {
            return null === $user;
        }

        // Must be logged in from now on
        if (!$user) {
            return false;
        }

        // All logged in users
        if (in_array(self::ROLE_LOGGED_IN, $rolesForCategory, true)) {
            return true;
        }

        return in_array($user->roleId, $rolesForCategory, true);
    }

    /**
     * Gets explicitly defined roles for a specific type and ID.
     *
     * @return list<int>
     */
    public function getRolesForItem(string $itemType, int $itemId): array
    {
        $cacheKey = $itemType . '_' . $itemId;

        return self::$matrixCache[$cacheKey] ?? [];
    }

    /**
     * Walks up the category tree to find inherited permissions.
     *
     * @return list<int>
     */
    public function getInheritedRolesForCategory(int $categoryId): array
    {
        return $this->getInheritedRolesWithSourceForCategory($categoryId)[0];
    }

    /**
     * Like getInheritedRolesForCategory(), but also returns the category ID where the rule was found.
     *
     * @return array{0: list<int>, 1: int|null}
     */
    public function getInheritedRolesWithSourceForCategory(int $categoryId): array
    {
        if (0 === $categoryId) {
            return [[], null];
        }

        $category = rex_category::get($categoryId);
        if (!$category) {
            return [[], null];
        }

        $tree = $category->getPathAsArray();
        $tree[] = $categoryId; // include itself
        $tree = array_reverse($tree); // start from deepest

        foreach ($tree as $catId) {
            if (empty($catId)) {
                continue;
            }

            $roles = $this->getRolesForItem('category', (int) $catId);
            if (!empty($roles)) {
                return [$roles, (int) $catId];
            }
        }

        return [[], null];
    }

    /**
     * Walks up the media category tree to find inherited permissions.
     *
     * @return list<int>
     */
    public function getInheritedRolesForMediaCategory(int $categoryId): array
    {
        if (0 === $categoryId) {
            return [];
        }

        $category = rex_media_category::get($categoryId);
        if (!$category) {
            return [];
        }

        $tree = $category->getPathAsArray();
        $tree[] = $categoryId; // include itself
        $tree = array_reverse($tree);

        foreach ($tree as $catId) {
            if (empty($catId)) {
                continue;
            }

            $roles = $this->getRolesForItem('media_category', (int) $catId);
            if (!empty($roles)) {
                return $roles; // First set of rules found applies
            }
        }

        return [];
    }

    /**
     * Returns true when access requests are enabled for this article.
     * Priority: explicit article flag, then inherited category flag.
     */
    public function isAccessRequestEnabledForArticle(int $articleId): bool
    {
        $article = rex_article::get($articleId);
        if (!$article) {
            return false;
        }

        if ($this->hasRequestFlag('request_article', $articleId)) {
            return true;
        }

        return $this->isAccessRequestEnabledForCategory($article->getCategoryId());
    }

    /**
     * Returns true when access requests are enabled for this category tree.
     */
    public function isAccessRequestEnabledForCategory(int $categoryId): bool
    {
        if (0 === $categoryId) {
            return false;
        }

        $category = rex_category::get($categoryId);
        if (!$category) {
            return false;
        }

        if ($this->hasRequestFlag('request_category', $categoryId)) {
            return true;
        }

        $tree = $category->getPathAsArray();
        $tree = array_reverse($tree);
        foreach ($tree as $catId) {
            if (empty($catId)) {
                continue;
            }

            if ($this->hasRequestFlag('request_category', (int) $catId)) {
                return true;
            }
        }

        return false;
    }

    private function hasRequestFlag(string $itemType, int $itemId): bool
    {
        return in_array(self::REQUEST_ENABLED, $this->getRolesForItem($itemType, $itemId), true);
    }
}
