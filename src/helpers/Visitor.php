<?php

declare(strict_types=1);

namespace justinholtweb\csr\helpers;

use Craft;
use craft\helpers\StringHelper;

/**
 * Who is doing this, to the smallest degree that answers the question.
 *
 * CSR needs to be able to say "the same person voted twice" and "these three searches were one
 * session", and it needs to say it without storing anything that identifies anybody. The answer is
 * a salted hash: the salt is the install's security key, the ingredients are the session ID when
 * one already exists and the IP and user agent when one does not, and the output is 32 characters
 * that mean nothing outside this database.
 *
 * **It never opens a session that was not already open.** Asking Craft whether somebody is logged
 * in is not a free read: it starts the session, which sets a cookie, which stops a full-page cache
 * ever hitting again. A knowledge base is the most cacheable thing on most sites, and quietly
 * making it uncacheable in order to count votes would be a poor trade.
 */
abstract class Visitor
{
    /** How long a deflection session is considered the same visit. */
    public const SESSION_WINDOW_MINUTES = 60;

    public static function key(): string
    {
        $parts = [];
        $request = Craft::$app->getRequest();

        if (!$request->getIsConsoleRequest()) {
            $session = Craft::$app->getSession();

            // Only if somebody else already opened it — see the note above.
            if ($session->getIsActive()) {
                $parts[] = (string)$session->getId();
            } else {
                $parts[] = (string)$request->getUserIP();
                $parts[] = (string)$request->getUserAgent();
            }
        } else {
            $parts[] = 'console';
        }

        return self::hash(implode('|', $parts));
    }

    /** A key for a value the caller already has — an email address, an IP — hashed the same way. */
    public static function hash(string $value): string
    {
        $key = Craft::$app->getConfig()->getGeneral()->securityKey;

        return substr(hash_hmac('sha256', $value, (string)$key), 0, 32);
    }

    /** The IP address, if the site has asked for it to be stored. */
    public static function ip(): ?string
    {
        if (!\justinholtweb\csr\Plugin::getInstance()->getSettings()->storeIpAddress) {
            return null;
        }

        $request = Craft::$app->getRequest();

        return $request->getIsConsoleRequest() ? null : $request->getUserIP();
    }

    public static function userAgent(): ?string
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return null;
        }

        $agent = $request->getUserAgent();

        return $agent !== null ? StringHelper::safeTruncate($agent, 500) : null;
    }
}
