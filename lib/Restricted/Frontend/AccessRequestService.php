<?php

declare(strict_types=1);

namespace KLXM\Restricted\Frontend;

use KLXM\Restricted\PermissionManager;
use KLXM\Restricted\User;
use rex;
use rex_article;
use rex_sql;
use rex_sql_exception;

class AccessRequestService
{
    /**
     * @return array{status: bool, message: string}
     */
    public static function createForArticle(int $articleId, ?User $user, string $email, string $message, bool $allowGuestRequests = true): array
    {
        if ($articleId <= 0 || rex_article::get($articleId) === null) {
            return ['status' => false, 'message' => 'Unbekanntes Ziel für die Anfrage.'];
        }

        if (!$allowGuestRequests && $user === null) {
            return ['status' => false, 'message' => 'Bitte zuerst einloggen, um eine Anfrage zu stellen.'];
        }

        $pm = new PermissionManager();
        if (!$pm->isAccessRequestEnabledForArticle($articleId)) {
            return ['status' => false, 'message' => 'Anfragen für diesen Inhalt sind deaktiviert.'];
        }

        $requestEmail = trim($email);
        if ($user !== null && $requestEmail === '') {
            $requestEmail = $user->email;
        }

        if ($requestEmail === '' || !filter_var($requestEmail, FILTER_VALIDATE_EMAIL)) {
            return ['status' => false, 'message' => 'Bitte eine gültige E-Mail-Adresse angeben.'];
        }

        $requestMessage = trim($message);
        if (strlen($requestMessage) > 2000) {
            return ['status' => false, 'message' => 'Die Nachricht ist zu lang (max. 2000 Zeichen).'];
        }

        $sql = rex_sql::factory();

        // Avoid duplicate open requests for the same article + email.
        $sql->setQuery(
            'SELECT id FROM ' . rex::getTable('klxm_restricted_access_request') . ' WHERE article_id = ? AND email = ? AND status = ? LIMIT 1',
            [$articleId, $requestEmail, 'open']
        );
        if ($sql->getRows() > 0) {
            return ['status' => true, 'message' => 'Es gibt bereits eine offene Anfrage für diesen Inhalt.'];
        }

        try {
            $sql->setTable(rex::getTable('klxm_restricted_access_request'));
            $sql->setValue('article_id', $articleId);
            $sql->setValue('user_id', $user?->id);
            $sql->setValue('email', $requestEmail);
            $sql->setValue('message', $requestMessage);
            $sql->setValue('status', 'open');
            $sql->setValue('createdate', date('Y-m-d H:i:s'));
            $sql->setValue('updatedate', date('Y-m-d H:i:s'));
            $sql->insert();
        } catch (rex_sql_exception) {
            return ['status' => false, 'message' => 'Anfrage konnte nicht gespeichert werden.'];
        }

        return ['status' => true, 'message' => 'Anfrage wurde gespeichert. Ein Redakteur prüft den Zugriff.'];
    }
}
