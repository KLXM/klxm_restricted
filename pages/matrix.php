<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use rex;
use rex_addon;
use rex_category;
use rex_csrf_token;
use rex_fragment;
use rex_media_category;
use rex_sql;

use function in_array;

$addon = rex_addon::get('klxm_restricted');

// Generate CSRF Token for the API Call
$csrfToken = rex_csrf_token::factory('rex_api_klxm_restricted_matrix_update')->getValue();
echo '<script>var klxm_matrix_csrf = "' . $csrfToken . '";</script>';

$sql = rex_sql::factory();
$roles = $sql->getArray('SELECT id, name FROM ' . rex::getTable('klxm_restricted_role') . ' ORDER BY name ASC');

// Add pseudo roles for easy configuration
array_unshift($roles, ['id' => PermissionManager::ROLE_GUEST, 'name' => '🚷 Nur Gäste']);
array_unshift($roles, ['id' => PermissionManager::ROLE_LOGGED_IN, 'name' => '🔑 Alle Angemeldeten']);
array_unshift($roles, ['id' => PermissionManager::ROLE_PUBLIC, 'name' => '🌍 Öffentlich (Jeder)']);

// Helper to fetch directly assigned roles for fast rendering
$pm = new PermissionManager();

$content = '<table class="table table-striped table-hover">';
$content .= '<thead><tr>';
$content .= '<th>Struktur (Kategorie/Artikel)</th>';
foreach ($roles as $role) {
    $content .= '<th class="text-center">' . htmlspecialchars((string) $role['name']) . '</th>';
}
$content .= '<th class="text-center">Zugriff anfragen</th>';
$content .= '</tr></thead>';
$content .= '<tbody>';

// Recursive function to render the category tree
$renderCategory = static function (rex_category $category, int $depth = 0) use (&$renderCategory, $roles, $pm) {
    $indent = $depth * 20;

    // Explicit roles
    $assignedRoles = $pm->getRolesForItem('category', $category->getId());
    $inheritedRoles = $pm->getInheritedRolesForCategory($category->getParentId());

    // If no roles are set and none are inherited, it is effectively public by default
    $isPublicDefault = empty($assignedRoles) && empty($inheritedRoles);

    $html = '<tr>';
    $html .= '<td style="padding-left: ' . ($indent + 10) . 'px;">';
    $html .= '<i class="rex-icon rex-icon-category"></i> <strong>' . htmlspecialchars($category->getName()) . '</strong> <small class="text-muted">[' . $category->getId() . ']</small>';
    $html .= '</td>';

    foreach ($roles as $role) {
        $checked = in_array((int) $role['id'], $assignedRoles, true) ? 'checked' : '';
        $disabled = '';
        $opacity = '';
        if ('' === $checked && in_array((int) $role['id'], $inheritedRoles, true)) {
            $checked = 'checked';
            $disabled = 'disabled title="Von übergeordneter Kategorie vererbt"';
            $opacity = 'style="opacity: 0.4;"';
        }
        if ('' === $checked && $isPublicDefault && PermissionManager::ROLE_PUBLIC === $role['id']) {
            $checked = 'checked';
            $disabled = 'disabled title="Standard (Öffentlich vererbt)"';
            $opacity = 'style="opacity: 0.4;"';
        }
        $html .= '<td class="text-center">';
        $html .= '<input type="checkbox" class="klxm-matrix-checkbox" ' . $checked . ' ' . $disabled . ' ' . $opacity . ' ';
        $html .= 'data-item-type="category" data-item-id="' . $category->getId() . '" data-role-id="' . $role['id'] . '">';
        $html .= '</td>';
    }

    $requestEnabled = in_array(PermissionManager::REQUEST_ENABLED, $pm->getRolesForItem('request_category', $category->getId()), true);
    $html .= '<td class="text-center">';
    $html .= '<input type="checkbox" class="klxm-request-checkbox" ' . ($requestEnabled ? 'checked' : '') . ' ';
    $html .= 'data-item-type="request_category" data-item-id="' . $category->getId() . '" data-role-id="' . PermissionManager::REQUEST_ENABLED . '">';
    $html .= '</td>';
    $html .= '</tr>';

    // Articles inside this category
    $articles = $category->getArticles(false);
    foreach ($articles as $article) {
        // Skip start article since it represents the category itself regarding rights in this simplified matrix
        if ($article->isStartArticle()) {
            continue;
        }

        $artAssignedRoles = $pm->getRolesForItem('article', $article->getId());
        $artInheritedRoles = $pm->getInheritedRolesForCategory($article->getCategoryId());

        // If neither the article nor its parent category tree has roles, it is effectively public
        $isArtPublicDefault = empty($artAssignedRoles) && empty($artInheritedRoles);

        $html .= '<tr>';
        $html .= '<td style="padding-left: ' . ($indent + 30) . 'px;">';
        $html .= '<i class="rex-icon rex-icon-article"></i> ' . htmlspecialchars($article->getName()) . ' <small class="text-muted">[' . $article->getId() . ']</small>';
        $html .= '</td>';

        foreach ($roles as $role) {
            $checkedArt = in_array((int) $role['id'], $artAssignedRoles, true) ? 'checked' : '';
            $disabledArt = '';
            $opacityArt = '';
            if ('' === $checkedArt && in_array((int) $role['id'], $artInheritedRoles, true)) {
                $checkedArt = 'checked';
                $disabledArt = 'disabled title="Von übergeordneter Kategorie vererbt"';
                $opacityArt = 'style="opacity: 0.4;"';
            }
            if ('' === $checkedArt && $isArtPublicDefault && PermissionManager::ROLE_PUBLIC === $role['id']) {
                $checkedArt = 'checked';
                $disabledArt = 'disabled title="Standard (Öffentlich vererbt)"';
                $opacityArt = 'style="opacity: 0.4;"';
            }
            $html .= '<td class="text-center">';
            $html .= '<input type="checkbox" class="klxm-matrix-checkbox" ' . $checkedArt . ' ' . $disabledArt . ' ' . $opacityArt . ' ';
            $html .= 'data-item-type="article" data-item-id="' . $article->getId() . '" data-role-id="' . $role['id'] . '">';
            $html .= '</td>';
        }

        $requestAssigned = in_array(PermissionManager::REQUEST_ENABLED, $pm->getRolesForItem('request_article', $article->getId()), true);
        $requestInherited = !$requestAssigned && $pm->isAccessRequestEnabledForCategory($article->getCategoryId());

        $html .= '<td class="text-center">';
        $html .= '<input type="checkbox" class="klxm-request-checkbox" ' . ($requestAssigned || $requestInherited ? 'checked' : '') . ' ';
        if ($requestInherited) {
            $html .= 'disabled title="Von Kategorie vererbt" style="opacity: 0.4;" ';
        }
        $html .= 'data-item-type="request_article" data-item-id="' . $article->getId() . '" data-role-id="' . PermissionManager::REQUEST_ENABLED . '">';
        $html .= '</td>';
        $html .= '</tr>';
    }

    $children = $category->getChildren(false);
    foreach ($children as $child) {
        $html .= $renderCategory($child, $depth + 1);
    }

    return $html;
};

// Start parsing root categories
$rootCategories = rex_category::getRootCategories(false);
foreach ($rootCategories as $rootCategory) {
    $content .= $renderCategory($rootCategory, 0);
}

$content .= '</tbody></table>';

$fragment = new rex_fragment();
$fragment->setVar('class', 'success', false);
$fragment->setVar('title', 'Zentrale Rechte-Matrix (Struktur)', false);
$fragment->setVar('body', $content, false);

echo $fragment->parse('core/page/section.php');

// --- MEDIA CATEGORIES MATRIX ---

$mediaContent = '<table class="table table-striped table-hover">';
$mediaContent .= '<thead><tr>';
$mediaContent .= '<th>Medienpool-Kategorien</th>';
foreach ($roles as $role) {
    $mediaContent .= '<th class="text-center">' . htmlspecialchars((string) $role['name']) . '</th>';
}
$mediaContent .= '</tr></thead>';
$mediaContent .= '<tbody>';

// Recursive function to render the media category tree
$renderMediaCategory = static function (rex_media_category $category, int $depth = 0) use (&$renderMediaCategory, $roles, $pm) {
    $indent = $depth * 20;

    // Explicit roles
    $assignedRoles = $pm->getRolesForItem('media_category', $category->getId());
    $inheritedRoles = $pm->getInheritedRolesForMediaCategory($category->getParentId());

    // If no explicit rules on media_category anywhere in tree
    $isPublicDefault = empty($assignedRoles) && empty($inheritedRoles);

    $html = '<tr>';
    $html .= '<td style="padding-left: ' . ($indent + 10) . 'px;">';
    $html .= '<i class="rex-icon rex-icon-media-category"></i> <strong>' . htmlspecialchars($category->getName()) . '</strong> <small class="text-muted">[' . $category->getId() . ']</small>';
    $html .= '</td>';

    foreach ($roles as $role) {
        $checked = in_array((int) $role['id'], $assignedRoles, true) ? 'checked' : '';
        $disabled = '';
        $opacity = '';
        if ('' === $checked && in_array((int) $role['id'], $inheritedRoles, true)) {
            $checked = 'checked';
            $disabled = 'disabled title="Von übergeordneter Kategorie vererbt"';
            $opacity = 'style="opacity: 0.4;"';
        }
        if ('' === $checked && $isPublicDefault && PermissionManager::ROLE_PUBLIC === $role['id']) {
            $checked = 'checked';
            $disabled = 'disabled title="Standard (Öffentlich vererbt)"';
            $opacity = 'style="opacity: 0.4;"';
        }
        $html .= '<td class="text-center">';
        $html .= '<input type="checkbox" class="klxm-matrix-checkbox" ' . $checked . ' ' . $disabled . ' ' . $opacity . ' ';
        $html .= 'data-item-type="media_category" data-item-id="' . $category->getId() . '" data-role-id="' . $role['id'] . '">';
        $html .= '</td>';
    }
    $html .= '</tr>';

    $children = $category->getChildren();
    foreach ($children as $child) {
        $html .= $renderMediaCategory($child, $depth + 1);
    }

    return $html;
};

// Start parsing root media categories
$rootMediaCategories = rex_media_category::getRootCategories();
foreach ($rootMediaCategories as $rootMediaCategory) {
    $mediaContent .= $renderMediaCategory($rootMediaCategory, 0);
}

$mediaContent .= '</tbody></table>';

$fragmentMedia = new rex_fragment();
$fragmentMedia->setVar('class', 'info', false);
$fragmentMedia->setVar('title', 'Zentrale Rechte-Matrix (Medienpool)', false);
$fragmentMedia->setVar('body', $mediaContent, false);

echo $fragmentMedia->parse('core/page/section.php');
