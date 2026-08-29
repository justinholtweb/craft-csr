<?php

declare(strict_types=1);

namespace justinholtweb\csr\records;

/**
 * CSR's table names, in one place.
 *
 * Every query in the plugin names its table from here, so a hand-written `csr_searches` and the
 * migration that created `csr_searchs` cannot drift apart in a typo that only surfaces on the one
 * database driver nobody tested.
 */
abstract class Table
{
    // The knowledge base
    public const ARTICLES = '{{%csr_articles}}';
    public const ARTICLES_SITES = '{{%csr_articles_sites}}';
    public const CATEGORIES = '{{%csr_categories}}';
    public const ARTICLE_RELATED = '{{%csr_article_related}}';
    public const RATINGS = '{{%csr_ratings}}';
    public const VIEWS = '{{%csr_articleviews}}';

    // What visitors looked for, and whether they found it
    public const SEARCHES = '{{%csr_searches}}';
    public const DEFLECTIONS = '{{%csr_deflections}}';

    // The desk
    public const TICKETS = '{{%csr_tickets}}';
    public const REPLIES = '{{%csr_replies}}';
    public const ATTACHMENTS = '{{%csr_attachments}}';
    public const EVENTS = '{{%csr_ticketevents}}';
    public const STATUSES = '{{%csr_statuses}}';
    public const CANNED_REPLIES = '{{%csr_cannedreplies}}';
    public const SLA_POLICIES = '{{%csr_slapolicies}}';
    public const TEAMS = '{{%csr_teams}}';
    public const TEAM_MEMBERS = '{{%csr_teammembers}}';
    public const FORM_FIELDS = '{{%csr_formfields}}';
}
