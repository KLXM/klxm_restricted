<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use rex;
use rex_addon;
use rex_file;
use rex_sql_table;
use rex_sql_column;
use rex_sql_index;
use rex_yform_manager_table_api;
use KLXM\Restricted\Tools\SetupHelper;

$addon = rex_addon::get('klxm_restricted');

// 1. Create native Matrix Table for efficient lookups without YForm overhead
rex_sql_table::get(rex::getTable('klxm_restricted_matrix'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('item_type', 'varchar(50)')) // 'article', 'category', 'media', 'media_category'
    ->ensureColumn(new rex_sql_column('item_id', 'int(10) unsigned'))
    ->ensureColumn(new rex_sql_column('role_id', 'int(10) unsigned'))
    ->ensureIndex(new rex_sql_index('idx_item', ['item_type', 'item_id']))
    ->ensure();

// 2. Passkey Storage Table
rex_sql_table::get(rex::getTable('klxm_restricted_passkey'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('credential_id', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('user_handle', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('credential_data', 'text'))
    ->ensureIndex(new rex_sql_index('idx_credential', ['credential_id'], rex_sql_index::UNIQUE))
    ->ensure();

// 3. Access request inbox table (optional feature)
rex_sql_table::get(rex::getTable('klxm_restricted_access_request'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('article_id', 'int(10) unsigned'))
    ->ensureColumn(new rex_sql_column('user_id', 'int(10) unsigned', true))
    ->ensureColumn(new rex_sql_column('email', 'varchar(191)'))
    ->ensureColumn(new rex_sql_column('message', 'text', true))
    ->ensureColumn(new rex_sql_column('status', 'varchar(32)', false, 'open'))
    ->ensureColumn(new rex_sql_column('createdate', 'datetime'))
    ->ensureColumn(new rex_sql_column('updatedate', 'datetime'))
    ->ensureColumn(new rex_sql_column('handled_by', 'varchar(191)', true))
    ->ensureColumn(new rex_sql_column('handled_note', 'text', true))
    ->ensureIndex(new rex_sql_index('idx_request_article', ['article_id']))
    ->ensureIndex(new rex_sql_index('idx_request_status', ['status']))
    ->ensure();

// 4. Import YForm tables for Users and Roles
$tablesetPath = $addon->getPath('install/tablesets/yform_restricted.json');
if (file_exists($tablesetPath)) {
    $tableset = rex_file::get($tablesetPath);
    if ($tableset) {
        rex_yform_manager_table_api::importTablesets($tableset);
        SetupHelper::createDefaultUserIfEmpty();
    }
}

// 5. Ensure additional security columns on user table (added after initial install)
rex_sql_table::get(rex::getTable('klxm_restricted_user'))
    ->ensureColumn(new rex_sql_column('last_login', 'datetime', true))
    ->ensureColumn(new rex_sql_column('failed_logins', 'int(10) unsigned', false, '0'))
    ->ensureColumn(new rex_sql_column('login_locked_until', 'datetime', true))
    ->ensureColumn(new rex_sql_column('email_verified', 'tinyint(1)', false, '1'))
    ->ensureColumn(new rex_sql_column('email_verification_token', 'varchar(64)', true))
    ->ensure();
