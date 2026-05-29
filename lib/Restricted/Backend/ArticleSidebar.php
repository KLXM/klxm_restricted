<?php

declare(strict_types=1);

namespace KLXM\Restricted\Backend;

use KLXM\Restricted\PermissionManager;
use rex;
use rex_article;
use rex_category;
use rex_clang;
use rex_escape;
use rex_extension_point;
use rex_fragment;
use rex_i18n;
use rex_sql;
use rex_url;

class ArticleSidebar
{
    /**
     * Renders the restricted-permissions panel in the article sidebar.
     *
     * @param rex_extension_point<string> $ep
     */
    public static function render(rex_extension_point $ep): string
    {
        $subject = $ep->getSubject();
        $params = $ep->getParams();
        $articleId = (int) ($params['article_id'] ?? 0);
        $clangId = (int) ($params['clang'] ?? rex_clang::getCurrentId());

        if ($articleId === 0) {
            return $subject;
        }

        $art = rex_article::get($articleId, $clangId);
        if (!$art) {
            return $subject;
        }

        $pm = new PermissionManager();

        $directRoles = $pm->getRolesForItem('article', $articleId);

        $sourceCategoryId = null;
        $inheritedRoles = [];
        if ($directRoles === []) {
            [$inheritedRoles, $sourceCategoryId] = $pm->getInheritedRolesWithSourceForCategory($art->getCategoryId());
        }

        $activeRoles = $directRoles !== [] ? $directRoles : $inheritedRoles;
        $isInherited = $directRoles === [] && $inheritedRoles !== [];

        $roleLabels = self::getRoleLabels($activeRoles);

        $matrixUrl = rex_url::backendController(['page' => 'klxm_restricted/matrix']);

        if ($activeRoles === []) {
            $body = '<p class="text-muted" style="margin-bottom:8px"><i class="fa fa-globe"></i> '
                . rex_i18n::msg('klxm_restricted_sidebar_public')
                . '</p>';
        } else {
            $body = '<ul class="list-unstyled" style="margin-bottom:8px">';
            foreach ($roleLabels as $label) {
                $body .= '<li><i class="fa fa-tag" style="margin-right:4px"></i>'
                    . '<strong>' . rex_escape($label) . '</strong></li>';
            }
            $body .= '</ul>';

            if ($isInherited && $sourceCategoryId !== null) {
                $sourceCategory = rex_category::get($sourceCategoryId);
                if ($sourceCategory) {
                    $catUrl = rex_url::backendController([
                        'page' => 'structure',
                        'category_id' => $sourceCategory->getParentId(),
                        'clang' => $clangId,
                    ]);
                    $body .= '<p class="text-muted" style="margin:4px 0 8px">'
                        . '<i class="fa fa-sitemap" style="margin-right:4px"></i>'
                        . rex_i18n::msg('klxm_restricted_sidebar_inherited_from')
                        . ' <a href="' . rex_escape($catUrl) . '">'
                        . '<strong>' . rex_escape($sourceCategory->getName()) . '</strong>'
                        . '</a></p>';
                }
            }
        }

        $body .= '<a href="' . rex_escape($matrixUrl) . '" class="btn btn-xs btn-default">'
            . '<i class="fa fa-th" style="margin-right:4px"></i>'
            . rex_i18n::msg('klxm_restricted_sidebar_open_matrix')
            . '</a>';

        $fragment = new rex_fragment();
        $fragment->setVar(
            'title',
            '<i class="fa fa-lock" style="margin-right:4px"></i>' . rex_i18n::msg('klxm_restricted_sidebar_title'),
            false
        );
        $fragment->setVar('body', $body, false);
        $fragment->setVar('collapse', true);
        $fragment->setVar('collapsed', true);

        return $fragment->parse('core/page/section.php') . $subject;
    }

    /**
     * @param list<int> $roleIds
     * @return list<string>
     */
    private static function getRoleLabels(array $roleIds): array
    {
        $labels = [];
        $dbRoleIds = [];

        foreach ($roleIds as $roleId) {
            match ($roleId) {
                PermissionManager::ROLE_PUBLIC => $labels[] = rex_i18n::msg('klxm_restricted_role_public'),
                PermissionManager::ROLE_LOGGED_IN => $labels[] = rex_i18n::msg('klxm_restricted_role_logged_in'),
                PermissionManager::ROLE_GUEST => $labels[] = rex_i18n::msg('klxm_restricted_role_guest'),
                default => $dbRoleIds[] = $roleId,
            };
        }

        if ($dbRoleIds !== []) {
            $sql = rex_sql::factory();
            $placeholders = implode(',', array_fill(0, count($dbRoleIds), '?'));
            $rows = $sql->getArray(
                'SELECT name FROM ' . rex::getTable('klxm_restricted_role') . ' WHERE id IN (' . $placeholders . ')',
                $dbRoleIds
            );
            foreach ($rows as $row) {
                $labels[] = (string) $row['name'];
            }
        }

        return $labels;
    }
}
