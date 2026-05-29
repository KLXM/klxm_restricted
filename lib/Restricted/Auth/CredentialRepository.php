<?php

declare(strict_types=1);

namespace KLXM\Restricted\Auth;

use rex;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialSourceRepository;
use Webauthn\PublicKeyCredentialUserEntity;
use rex_sql;

/**
 * Handles Storing and Loading of Passkeys (Credentials) from the Database.
 */
class CredentialRepository implements PublicKeyCredentialSourceRepository
{
    private string $table;

    public function __construct()
    {
        // Custom table for passkeys linked to users
        $this->table = rex::getTable('klxm_restricted_passkey');
    }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?PublicKeyCredentialSource
    {
        $sql = rex_sql::factory();
        $sql->setQuery("SELECT credential_data FROM {$this->table} WHERE credential_id = ? LIMIT 1", [base64_encode($publicKeyCredentialId)]);

        if ($sql->getRows() === 1) {
            $data = json_decode((string) $sql->getValue('credential_data'), true);
            return PublicKeyCredentialSource::createFromArray($data);
        }

        return null;
    }

    public function findAllForUserEntity(PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array
    {
        $sql = rex_sql::factory();
        $sql->setQuery("SELECT credential_data FROM {$this->table} WHERE user_handle = ?", [base64_encode($publicKeyCredentialUserEntity->id)]);

        $sources = [];
        foreach ($sql as $row) {
            $data = json_decode($row->getValue('credential_data'), true);
            $sources[] = PublicKeyCredentialSource::createFromArray($data);
        }

        return $sources;
    }

    public function saveCredentialSource(PublicKeyCredentialSource $publicKeyCredentialSource): void
    {
        $sql = rex_sql::factory();
        $sql->setTable($this->table);
        $sql->setValue('credential_id', base64_encode($publicKeyCredentialSource->publicKeyCredentialId));
        $sql->setValue('user_handle', base64_encode($publicKeyCredentialSource->userHandle));
        $sql->setValue('credential_data', json_encode($publicKeyCredentialSource->jsonSerialize()));
        
        // Use Insert Or Update (Replace)
        $sql->insertOrUpdate();
    }
}
