<?php

declare(strict_types=1);

namespace KLXM\Restricted\Auth;

use rex;
use rex_addon;
use rex_article;
use rex_clang;
use rex_sql;

/**
 * Handles Double-Opt-In email verification flow.
 */
class EmailVerification
{
    private const TOKEN_LENGTH = 32;

    /**
     * Generates a cryptographically secure verification token.
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_LENGTH));
    }

    /**
     * Stores the verification token for a user and marks them as unverified.
     */
    public static function assignToken(int $userId): string
    {
        $token = self::generateToken();

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('klxm_restricted_user'));
        $sql->setWhere(['id' => $userId]);
        $sql->setValue('email_verified', 0);
        $sql->setValue('email_verification_token', $token);
        $sql->update();

        return $token;
    }

    /**
     * Verifies a token. Returns true and marks the user as verified if valid.
     */
    public static function verify(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT id FROM ' . rex::getTable('klxm_restricted_user') . ' WHERE email_verification_token = ? AND email_verified = 0',
            [$token]
        );

        if ($sql->getRows() !== 1) {
            return false;
        }

        $userId = (int) $sql->getValue('id');

        $update = rex_sql::factory();
        $update->setTable(rex::getTable('klxm_restricted_user'));
        $update->setWhere(['id' => $userId]);
        $update->setValue('email_verified', 1);
        $update->setValue('email_verification_token', '');
        $update->update();

        return true;
    }

    /**
     * Builds the frontend verification URL for the given token.
     */
    public static function buildVerifyUrl(string $token): string
    {
        $addon = rex_addon::get('klxm_restricted');
        $loginArticleId = (int) $addon->getConfig('login_article');
        if ($loginArticleId === 0) {
            $loginArticleId = (int) rex_article::getSiteStartArticleId();
        }

        return \rex_getUrl($loginArticleId, rex_clang::getCurrentId(), [
            'klxm_action' => 'verify_email',
            'token' => $token,
        ]);
    }

    /**
     * Sends the verification email via symfony_mailer (if available).
     * Returns true on success, false if mailer is unavailable.
     */
    public static function sendVerificationMail(string $toEmail, string $firstname, string $token): bool
    {
        if (!rex_addon::get('symfony_mailer')->isAvailable()) {
            return false;
        }

        $verifyUrl = self::buildVerifyUrl($token);
        $siteName = rex::getServerName();

        $mailer = new \FriendsOfRedaxo\SymfonyMailer\RexSymfonyMailer();
        $email = $mailer->createEmail();
        $email = $email
            ->to($toEmail)
            ->subject($siteName . ' – E-Mail-Adresse bestätigen')
            ->html(
                '<p>Hallo ' . htmlspecialchars($firstname) . ',</p>'
                . '<p>bitte bestätige deine E-Mail-Adresse, indem du auf den folgenden Link klickst:</p>'
                . '<p><a href="' . htmlspecialchars($verifyUrl) . '">' . htmlspecialchars($verifyUrl) . '</a></p>'
                . '<p>Dieser Link ist gültig, bis du dein Konto aktiviert hast.</p>'
            );

        return $mailer->send($email);
    }
}
