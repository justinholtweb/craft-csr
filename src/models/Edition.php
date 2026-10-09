<?php

declare(strict_types=1);

namespace justinholtweb\csr\models;

/**
 * What each edition allows.
 *
 * Pure and static, taking `$isPro` rather than reaching for the plugin, so the boundary can be
 * tested without an application and read in one place as the answer to "what exactly does Pro
 * buy".
 *
 * The rule the caps follow: **Lite is a working support desk, not a demo.** A company with one
 * person answering the email should never meet a paywall — writing articles, publishing them at
 * proper URLs, letting readers say whether they helped, taking tickets, replying to them, and
 * showing somebody a likely answer before they open the form are all in Lite, uncapped. There is
 * deliberately **no limit on articles, categories or tickets**: charging for the thing that grows
 * with how busy a support team is, is the wrong shape of pricing for a support tool.
 *
 * Pro is the two halves that only start to matter once one person cannot read everything:
 *
 * - **Measurement.** Which searches came back empty, which articles nobody opens, whether the
 *   suggestions panel actually stopped anybody writing in. Lite tells you what people searched
 *   for; Pro tells you what they failed to find, which is the number that changes what you write
 *   next.
 * - **Scale.** Promises with a clock on them, a library of replies so eight agents answer the
 *   same question the same way, teams to route between, and questions of your own on the form.
 */
class Edition
{
    /**
     * Ticket statuses Lite may hold — exactly the five the installer creates.
     *
     * So Lite has a *working* pipeline (new → open → pending → resolved → closed), which is what
     * stops the tickets screen from being an undifferentiated list. What it cannot do is model a
     * support process of its own.
     */
    public const LITE_MAX_STATUSES = 5;

    /**
     * How many days of search history Lite keeps.
     *
     * Not a nag: a log nobody can report on is a table that grows forever, and thirty days is
     * enough to answer "what happened this month", which is the question Lite's top-searches
     * report is for.
     */
    public const LITE_SEARCH_RETENTION_DAYS = 30;

    // Measurement
    // -------------------------------------------------------------------------

    /**
     * Searches that returned nothing, and searches whose results nobody opened.
     *
     * The flagship Pro report, and the one that pays for the licence: a knowledge base's most
     * valuable page is the one it does not have yet, and this is the only place its name is
     * written down.
     */
    public static function allowsFailedSearchAnalytics(bool $isPro): bool
    {
        return $isPro;
    }

    /** Views, ratings and trends per article — which pages are working and which are ignored. */
    public static function allowsArticleAnalytics(bool $isPro): bool
    {
        return $isPro;
    }

    /**
     * Counting what the suggestions panel achieved.
     *
     * The panel itself is in Lite — showing somebody the answer before they write in is the whole
     * point of the plugin and gating it would make Lite pointless. What Pro adds is knowing
     * whether it worked: the deflection rate, and which articles earn it.
     */
    public static function allowsDeflectionAnalytics(bool $isPro): bool
    {
        return $isPro;
    }

    /** Ticket volume, first-response and resolution times, agent workload, satisfaction. */
    public static function allowsDeskReports(bool $isPro): bool
    {
        return $isPro;
    }

    /** CSV of tickets, searches or ratings. */
    public static function allowsExport(bool $isPro): bool
    {
        return $isPro;
    }

    // Scale
    // -------------------------------------------------------------------------

    /** Promises with a clock on them: first response and resolution targets, and breaches. */
    public static function allowsSlas(bool $isPro): bool
    {
        return $isPro;
    }

    /** Opening hours, so an SLA does not burn through a weekend nobody was working. */
    public static function allowsBusinessHours(bool $isPro): bool
    {
        return $isPro;
    }

    /** A library of replies, so eight agents answer the same question the same way. */
    public static function allowsCannedReplies(bool $isPro): bool
    {
        return $isPro;
    }

    /** Teams, and routing tickets to one rather than to a person. */
    public static function allowsTeams(bool $isPro): bool
    {
        return $isPro;
    }

    /** Questions of your own on the support form, and showing them per category. */
    public static function allowsCustomFormFields(bool $isPro): bool
    {
        return $isPro;
    }

    /** Articles only logged-in users, or particular groups, may read. */
    public static function allowsRestrictedArticles(bool $isPro): bool
    {
        return $isPro;
    }

    /** "How did we do?" after a ticket is resolved. */
    public static function allowsSatisfactionSurvey(bool $isPro): bool
    {
        return $isPro;
    }

    /** Folding a duplicate ticket into the one that will be answered. */
    public static function allowsMerge(bool $isPro): bool
    {
        return $isPro;
    }

    /**
     * Email in: provider webhooks and the IMAP poll, turning mail into tickets and replies.
     *
     * Pro because it is the door a busy desk needs and a one-person desk can do without — a
     * support form plus the notification emails is a complete loop on its own.
     */
    public static function allowsInboundEmail(bool $isPro): bool
    {
        return $isPro;
    }

    // Caps
    // -------------------------------------------------------------------------

    public static function maxStatuses(bool $isPro): ?int
    {
        return $isPro ? null : self::LITE_MAX_STATUSES;
    }

    public static function maxSearchRetentionDays(bool $isPro): ?int
    {
        return $isPro ? null : self::LITE_SEARCH_RETENTION_DAYS;
    }

    /**
     * Whether this edition will let another status be created, given how many exist.
     *
     * A **downgrade**, not a refusal: a site whose licence lapsed keeps every ticket it has, on
     * the statuses they are already on, and every status it created. What Lite stops is *creating
     * the sixth*. Nothing here ever moves a ticket.
     */
    public static function statusLimitReached(int $existing, bool $isPro): bool
    {
        $max = self::maxStatuses($isPro);

        return $max !== null && $existing >= $max;
    }
}
