<?php

declare(strict_types=1);

use rex_api_function;
use rex_sql;
use rex_response;

/**
 * Handle async matrix updates (assign/remove role from item).
 * Call via: index.php?rex-api-call=klxm_restricted_matrix_update
 */
class rex_api_klxm_restricted_matrix_update extends rex_api_function
{
    protected $published = false; // Backend only

    public function execute(): rex_api_result
    {
        rex_response::cleanOutputBuffers();

        $itemType = rex_post('item_type', 'string', '');
        $itemId = rex_post('item_id', 'int', 0);
        $roleId = rex_post('role_id', 'int', 0);
        
        // formData appends state as 1 or 0 string, handle appropriately
        $stateRaw = rex_post('state', 'string', '');
        $state = $stateRaw === '1' || $stateRaw === 'true';

        if ($itemType === '' || $itemId === 0 || $roleId === 0) {
            rex_response::sendJson(['status' => false, 'error' => 'Invalid parameters']);
            exit;
        }

        $sql = rex_sql::factory();
        $table = rex::getTable('klxm_restricted_matrix');

        if ($state) {
            // Assign: Insert ignore
            $sql->setTable($table);
            $sql->setValue('item_type', $itemType);
            $sql->setValue('item_id', $itemId);
            $sql->setValue('role_id', $roleId);
            try {
                $sql->insert();
            } catch (rex_sql_exception) {
                // Ignore duplicate entry errors
            }
        } else {
            // Remove: Delete
            $sql->setQuery("DELETE FROM $table WHERE item_type = ? AND item_id = ? AND role_id = ?", [$itemType, $itemId, $roleId]);
        }

        rex_response::sendJson(['status' => true]);
        exit;
    }
}
