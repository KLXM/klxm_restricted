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

// 4. Database-backed frontend sessions
rex_sql_table::get(rex::getTable('klxm_restricted_session'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('session_id', 'varchar(191)'))
    ->ensureColumn(new rex_sql_column('user_id', 'int(10) unsigned'))
    ->ensureColumn(new rex_sql_column('ip', 'varchar(45)', true))
    ->ensureColumn(new rex_sql_column('useragent', 'varchar(255)', true))
    ->ensureColumn(new rex_sql_column('starttime', 'datetime'))
    ->ensureColumn(new rex_sql_column('last_activity', 'datetime'))
    ->ensureIndex(new rex_sql_index('idx_klxm_session_id', ['session_id'], rex_sql_index::UNIQUE))
    ->ensureIndex(new rex_sql_index('idx_klxm_session_user', ['user_id']))
    ->ensure();

// 5. Public media share links for restricted files
rex_sql_table::get(rex::getTable('klxm_restricted_media_share'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('token_hash', 'varchar(64)'))
    ->ensureColumn(new rex_sql_column('token_hint', 'varchar(16)', true))
    ->ensureColumn(new rex_sql_column('token_plain', 'varchar(64)', true))
    ->ensureColumn(new rex_sql_column('category_id', 'int(10) unsigned'))
    ->ensureColumn(new rex_sql_column('title', 'varchar(191)', true))
    ->ensureColumn(new rex_sql_column('media_files', 'text'))
    ->ensureColumn(new rex_sql_column('allow_zip', 'tinyint(1)', false, '1'))
    ->ensureColumn(new rex_sql_column('password_hash', 'varchar(255)', true))
    ->ensureColumn(new rex_sql_column('expires_at', 'datetime', true))
    ->ensureColumn(new rex_sql_column('max_downloads', 'int(10) unsigned', true))
    ->ensureColumn(new rex_sql_column('download_count', 'int(10) unsigned', false, '0'))
    ->ensureColumn(new rex_sql_column('status', 'tinyint(1)', false, '1'))
    ->ensureColumn(new rex_sql_column('created_by', 'varchar(191)', true))
    ->ensureColumn(new rex_sql_column('last_download', 'datetime', true))
    ->ensureColumn(new rex_sql_column('createdate', 'datetime'))
    ->ensureColumn(new rex_sql_column('updatedate', 'datetime'))
    ->ensureIndex(new rex_sql_index('idx_klxm_share_token', ['token_hash'], rex_sql_index::UNIQUE))
    ->ensureIndex(new rex_sql_index('idx_klxm_share_category', ['category_id']))
    ->ensure();

// 6. One-time pastebin entries with optional media attachments
rex_sql_table::get(rex::getTable('klxm_restricted_pastebin'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('token_hash', 'varchar(64)'))
    ->ensureColumn(new rex_sql_column('token_hint', 'varchar(16)', true))
    ->ensureColumn(new rex_sql_column('title', 'varchar(191)', true))
    ->ensureColumn(new rex_sql_column('secret_content', 'longtext'))
    ->ensureColumn(new rex_sql_column('attachment_files', 'text', true))
    ->ensureColumn(new rex_sql_column('password_hash', 'varchar(255)', true))
    ->ensureColumn(new rex_sql_column('expires_at', 'datetime', true))
    ->ensureColumn(new rex_sql_column('status', 'tinyint(1)', false, '1'))
    ->ensureColumn(new rex_sql_column('view_count', 'int(10) unsigned', false, '0'))
    ->ensureColumn(new rex_sql_column('created_by', 'varchar(191)', true))
    ->ensureColumn(new rex_sql_column('destroyedate', 'datetime', true))
    ->ensureColumn(new rex_sql_column('createdate', 'datetime'))
    ->ensureColumn(new rex_sql_column('updatedate', 'datetime'))
    ->ensureIndex(new rex_sql_index('idx_klxm_paste_token', ['token_hash'], rex_sql_index::UNIQUE))
    ->ensureIndex(new rex_sql_index('idx_klxm_paste_status', ['status']))
    ->ensure();

// 7. Import YForm tables for Users and Roles
$tablesetPath = $addon->getPath('install/tablesets/yform_restricted.json');
if (file_exists($tablesetPath)) {
    $tableset = rex_file::get($tablesetPath);
    if ($tableset) {
        rex_yform_manager_table_api::importTablesets($tableset);
        SetupHelper::createDefaultUserIfEmpty();
    }
}

// 8. Ensure additional security columns on user table (added after initial install)
rex_sql_table::get(rex::getTable('klxm_restricted_user'))
    ->ensureColumn(new rex_sql_column('last_login', 'datetime', true))
    ->ensureColumn(new rex_sql_column('failed_logins', 'int(10) unsigned', false, '0'))
    ->ensureColumn(new rex_sql_column('login_locked_until', 'datetime', true))
    ->ensureColumn(new rex_sql_column('email_verified', 'tinyint(1)', false, '1'))
    ->ensureColumn(new rex_sql_column('email_verification_token', 'varchar(64)', true))
    ->ensure();
