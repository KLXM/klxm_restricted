<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use rex;
use rex_sql_table;

rex_sql_table::get(rex::getTable('klxm_restricted_matrix'))->drop();
rex_sql_table::get(rex::getTable('klxm_restricted_media_share'))->drop();
rex_sql_table::get(rex::getTable('klxm_restricted_pastebin'))->drop();

// Note: We don't automatically drop the YForm tables rex_klxm_restricted_user and rex_klxm_restricted_role
// to prevent accidental data loss of users/roles on uninstall. If they should be removed, we could delete from Yform schema.
