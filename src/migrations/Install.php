<?php

declare(strict_types=1);

namespace justinholtweb\csr\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\DeflectionOutcome;
use justinholtweb\csr\enums\Priority;
use justinholtweb\csr\enums\StatusKind;
use justinholtweb\csr\enums\Visibility;
use justinholtweb\csr\records\Table;

/**
 * CSR's schema.
 *
 * Five shapes here are decisions rather than defaults, and each one is the answer to a question
 * that a help desk gets asked and a CRUD app does not:
 *
 * - **A ticket keeps the requester's email and name as columns, and `requesterId` is `SET NULL`.**
 *   Most people who write to support do not have an account, and the ones who do sometimes delete
 *   it. Either way the conversation still happened and still has to be readable.
 *
 * - **`csr_searches` stores one row per search, not a counter per phrase.** The valuable question
 *   is not "how often was `refund` searched" but "how often was `refund` searched *and nothing
 *   came back*", which needs the result count of each individual search, and "which article did
 *   they open afterwards", which needs the click. A counter cannot be asked either. The rows are
 *   pruned on a retention window rather than kept forever.
 *
 * - **`csr_deflections` is a row per *session*, not per suggestion.** A deflection rate is a ratio
 *   of people, and one visitor being shown six articles is one person deciding once.
 *
 * - **Articles and tickets share `csr_categories`.** It is the same taxonomy read from two ends: a
 *   ticket filed under Billing is routed by it, and the articles offered to somebody filing that
 *   ticket are found by it. Two parallel taxonomies would need mapping between them, and the
 *   mapping is the entire value.
 *
 * - **`csr_ticketevents` is append-only and separate from the ticket.** See
 *   {@see \justinholtweb\csr\enums\EventType}.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createConfigTables();
        $this->createKnowledgeBaseTables();
        $this->createAnalyticsTables();
        $this->createTicketTables();
        $this->addForeignKeys();

        return true;
    }

    /**
     * The tables that mirror project config.
     *
     * Statuses, SLA policies, teams and form fields are configuration — reviewable, deployable,
     * the same on staging as on production — so project config owns them. They are mirrored into
     * real tables anyway so that a ticket can point at a status with an integer foreign key and
     * the control panel can list them with a query instead of walking YAML on every request.
     */
    private function createConfigTables(): void
    {
        $this->createTable(Table::STATUSES, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            // What the status means to CSR, as opposed to what the site calls it.
            'kind' => $this->string(16)->notNull()->defaultValue(StatusKind::Open->value),
            'color' => $this->string(16)->notNull()->defaultValue('gray'),
            'description' => $this->text(),
            'isDefault' => $this->boolean()->defaultValue(false)->notNull(),
            'notifyRequester' => $this->boolean()->defaultValue(false)->notNull(),
            'emailSubject' => $this->string(),
            'emailBody' => $this->text(),
            'sortOrder' => $this->smallInteger()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::STATUSES, ['handle'], true);
        $this->createIndex(null, Table::STATUSES, ['name'], true);

        $this->createTable(Table::TEAMS, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'description' => $this->text(),
            'color' => $this->string(16)->notNull()->defaultValue('blue'),
            // A shared address the whole team is copied on, separate from the members' own.
            'email' => $this->string(),
            'sortOrder' => $this->smallInteger()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::TEAMS, ['handle'], true);
        $this->createIndex(null, Table::TEAMS, ['name'], true);

        // Membership is *not* project config: it is a list of people, their user IDs differ per
        // environment, and somebody joining the support team is not a deployment.
        $this->createTable(Table::TEAM_MEMBERS, [
            'id' => $this->primaryKey(),
            'teamId' => $this->integer()->notNull(),
            'userId' => $this->integer()->notNull(),
            'isLead' => $this->boolean()->defaultValue(false)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::TEAM_MEMBERS, ['teamId', 'userId'], true);
        $this->createIndex(null, Table::TEAM_MEMBERS, ['userId'], false);

        $this->createTable(Table::SLA_POLICIES, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'description' => $this->text(),
            'enabled' => $this->boolean()->defaultValue(true)->notNull(),
            // Which tickets this policy claims, as priorities / category uids / team uids /
            // sources. Held as JSON because it is read whole, by one thing, and never queried.
            'conditions' => $this->text(),
            'firstResponseMinutes' => $this->integer()->unsigned(),
            'resolutionMinutes' => $this->integer()->unsigned(),
            // Whether the clock only runs during the opening hours in the plugin settings.
            'businessHoursOnly' => $this->boolean()->defaultValue(true)->notNull(),
            'escalateAfterMinutes' => $this->integer()->unsigned(),
            // `char(36)`, not `$this->uid()`: that helper is `NOT NULL DEFAULT '0'`, which is
            // right for a row's own uid and wrong for an optional pointer at another row — every
            // policy without an escalation target fails to insert.
            'escalateToTeamUid' => $this->char(36),
            'notifyOnBreach' => $this->boolean()->defaultValue(true)->notNull(),
            'sortOrder' => $this->smallInteger()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::SLA_POLICIES, ['handle'], true);
        $this->createIndex(null, Table::SLA_POLICIES, ['name'], true);

        $this->createTable(Table::FORM_FIELDS, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'type' => $this->string(16)->notNull(),
            'instructions' => $this->text(),
            'placeholder' => $this->string(),
            'required' => $this->boolean()->defaultValue(false)->notNull(),
            'enabled' => $this->boolean()->defaultValue(true)->notNull(),
            'options' => $this->text(),
            // Only shown when the chosen category is one of these uids. Empty means always.
            'categoryUids' => $this->text(),
            'sortOrder' => $this->smallInteger()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::FORM_FIELDS, ['handle'], true);

        // The equivalent of a section's site settings: whether the knowledge base exists on this
        // site and what URLs its articles get.
        $this->createTable(Table::ARTICLES_SITES, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'enabledByDefault' => $this->boolean()->defaultValue(true)->notNull(),
            'uriFormat' => $this->text(),
            'template' => $this->string(500),
            // Where the built-in index lives on this site, for breadcrumbs and "back to the
            // knowledge base" links.
            'baseUri' => $this->string(500),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::ARTICLES_SITES, ['siteId'], true);
    }

    private function createKnowledgeBaseTables(): void
    {
        $this->createTable(Table::CATEGORIES, [
            'id' => $this->primaryKey(),
            'parentId' => $this->integer(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'description' => $this->text(),
            // An inline SVG or an emoji. Knowledge base indexes are read by scanning, and a row
            // of identical grey rectangles is scanned by nobody.
            'icon' => $this->text(),
            'color' => $this->string(16)->notNull()->defaultValue('blue'),
            'enabled' => $this->boolean()->defaultValue(true)->notNull(),
            // Whether tickets may be filed under it. Some categories are documentation only.
            'acceptsTickets' => $this->boolean()->defaultValue(true)->notNull(),
            // The team its tickets are routed to. Pro.
            'teamId' => $this->integer(),
            'sortOrder' => $this->smallInteger()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::CATEGORIES, ['handle'], true);
        $this->createIndex(null, Table::CATEGORIES, ['parentId', 'sortOrder'], false);

        $this->createTable(Table::ARTICLES, [
            'id' => $this->integer()->notNull(),
            'categoryId' => $this->integer(),
            'authorId' => $this->integer(),
            'excerpt' => $this->text(),
            // Phrases the article should be found by that do not appear in it — what customers
            // call the thing, as opposed to what the documentation calls it. The single highest
            // value field in a knowledge base and the one nobody ships.
            'keywords' => $this->text(),
            'visibility' => $this->string(16)->notNull()->defaultValue(Visibility::Public->value),
            'allowedGroupIds' => $this->text(),
            'featured' => $this->boolean()->defaultValue(false)->notNull(),
            'allowRatings' => $this->boolean()->defaultValue(true)->notNull(),
            'helpfulCount' => $this->integer()->unsigned()->defaultValue(0)->notNull(),
            'unhelpfulCount' => $this->integer()->unsigned()->defaultValue(0)->notNull(),
            'viewCount' => $this->integer()->unsigned()->defaultValue(0)->notNull(),
            'sortOrder' => $this->integer()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, Table::ARTICLES, ['categoryId', 'sortOrder'], false);
        $this->createIndex(null, Table::ARTICLES, ['featured'], false);
        $this->createIndex(null, Table::ARTICLES, ['visibility'], false);

        // Related articles, as rows rather than a JSON list, because the relationship is walked
        // from both ends: "what else should I read" on the article, and "what points at this"
        // when somebody is about to delete it.
        $this->createTable(Table::ARTICLE_RELATED, [
            'id' => $this->primaryKey(),
            'articleId' => $this->integer()->notNull(),
            'relatedId' => $this->integer()->notNull(),
            'sortOrder' => $this->smallInteger()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::ARTICLE_RELATED, ['articleId', 'relatedId'], true);
        $this->createIndex(null, Table::ARTICLE_RELATED, ['relatedId'], false);

        $this->createTable(Table::RATINGS, [
            'id' => $this->primaryKey(),
            'articleId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'userId' => $this->integer(),
            'helpful' => $this->boolean()->notNull(),
            // "What were you looking for?" — asked only after a No, which is when somebody is
            // both motivated to answer and holding the one piece of information the desk wants.
            'comment' => $this->text(),
            // A salted hash of the session, so one visitor votes once per article and can change
            // their mind, without CSR storing anything that identifies them.
            'visitorKey' => $this->char(32)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::RATINGS, ['articleId', 'visitorKey'], true);
        $this->createIndex(null, Table::RATINGS, ['helpful', 'dateCreated'], false);

        // A daily rollup rather than a row per view: the question is "is this article being read
        // more or less than last month", and nobody ever asks it to the second.
        $this->createTable(Table::VIEWS, [
            'id' => $this->primaryKey(),
            'articleId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'date' => $this->date()->notNull(),
            'views' => $this->integer()->unsigned()->defaultValue(0)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::VIEWS, ['articleId', 'siteId', 'date'], true);
        $this->createIndex(null, Table::VIEWS, ['date'], false);
    }

    private function createAnalyticsTables(): void
    {
        $this->createTable(Table::SEARCHES, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'query' => $this->string(255)->notNull(),
            // Folded case, collapsed whitespace, stripped punctuation. Grouped on, so it is
            // stored rather than computed: `Refund?` and `refund` are the same failed search and
            // no amount of SQL will agree unless somebody decided that up front.
            'normalizedQuery' => $this->string(255)->notNull(),
            'resultCount' => $this->integer()->unsigned()->defaultValue(0)->notNull(),
            // Null means they searched and opened nothing — a different failure from finding
            // nothing, and often a more interesting one.
            'clickedArticleId' => $this->integer(),
            'visitorKey' => $this->char(32),
            'userId' => $this->integer(),
            'source' => $this->string(16)->notNull()->defaultValue('kb'),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // The two indexes the reports actually use: "failed searches, worst first" leads with the
        // result count, and every report is scoped to a site and a date range.
        $this->createIndex(null, Table::SEARCHES, ['siteId', 'resultCount', 'dateCreated'], false);
        $this->createIndex(null, Table::SEARCHES, ['normalizedQuery', 'dateCreated'], false);
        $this->createIndex(null, Table::SEARCHES, ['dateCreated'], false);

        $this->createTable(Table::DEFLECTIONS, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'visitorKey' => $this->char(32)->notNull(),
            // What they had typed into the subject when the suggestions were fetched.
            'query' => $this->string(500),
            'categoryId' => $this->integer(),
            'suggestedIds' => $this->text(),
            'clickedArticleId' => $this->integer(),
            'outcome' => $this->string(16)->notNull()->defaultValue(DeflectionOutcome::Pending->value),
            'ticketId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::DEFLECTIONS, ['visitorKey', 'dateCreated'], false);
        $this->createIndex(null, Table::DEFLECTIONS, ['siteId', 'outcome', 'dateCreated'], false);
    }

    private function createTicketTables(): void
    {
        $this->createTable(Table::TICKETS, [
            'id' => $this->integer()->notNull(),
            // What everybody calls the ticket. Sequential and gap-free per install, allocated
            // inside the same transaction as the insert.
            'number' => $this->integer()->unsigned()->notNull(),
            // The requester's key to their own ticket. Never called `token`: Craft reserves that
            // query parameter and rejects an unrecognised one in `web\Application::init()`,
            // before any controller runs.
            'reference' => $this->char(32)->notNull(),
            'statusId' => $this->integer(),
            'priority' => $this->string(16)->notNull()->defaultValue(Priority::Normal->value),
            'categoryId' => $this->integer(),
            'agentId' => $this->integer(),
            'teamId' => $this->integer(),
            'requesterId' => $this->integer(),
            'requesterEmail' => $this->string()->notNull(),
            'requesterName' => $this->string(),
            // `submittedSiteId`, not `siteId`: `craft\base\ElementTrait` already declares that
            // property, and a selected column of the same name silently reassigns every ticket to
            // the wrong site.
            'submittedSiteId' => $this->integer(),
            'source' => $this->string(16)->notNull(),
            'message' => $this->mediumText(),
            'answers' => $this->mediumText(),
            'slaPolicyId' => $this->integer(),
            'firstResponseDue' => $this->dateTime(),
            'resolutionDue' => $this->dateTime(),
            'firstRespondedAt' => $this->dateTime(),
            'resolvedAt' => $this->dateTime(),
            'closedAt' => $this->dateTime(),
            'lastReplyAt' => $this->dateTime(),
            'lastReplyBy' => $this->string(16),
            'firstResponseBreached' => $this->boolean()->defaultValue(false)->notNull(),
            'resolutionBreached' => $this->boolean()->defaultValue(false)->notNull(),
            'escalatedAt' => $this->dateTime(),
            // When the clock was paused, and how long it has been paused for in total. Two
            // columns rather than one because a ticket can go pending more than once.
            'clockPausedAt' => $this->dateTime(),
            'pausedSeconds' => $this->integer()->unsigned()->defaultValue(0)->notNull(),
            'replyCount' => $this->integer()->unsigned()->defaultValue(0)->notNull(),
            'agentReplyCount' => $this->integer()->unsigned()->defaultValue(0)->notNull(),
            'deflectionId' => $this->integer(),
            // Set when the conversation has been handed to Pigeon, which owns threads properly.
            'pigeonThreadId' => $this->integer(),
            'satisfaction' => $this->tinyInteger(),
            'satisfactionComment' => $this->text(),
            'isSpam' => $this->boolean()->defaultValue(false)->notNull(),
            'mergedIntoId' => $this->integer(),
            'ipAddress' => $this->string(45),
            'userAgent' => $this->string(500),
            'submittedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, Table::TICKETS, ['number'], true);
        $this->createIndex(null, Table::TICKETS, ['reference'], true);
        $this->createIndex(null, Table::TICKETS, ['statusId', 'submittedAt'], false);
        $this->createIndex(null, Table::TICKETS, ['agentId', 'statusId'], false);
        $this->createIndex(null, Table::TICKETS, ['requesterEmail'], false);
        // The sweep asks "which unfinished tickets are past due", which is exactly this order.
        $this->createIndex(null, Table::TICKETS, ['firstResponseBreached', 'firstResponseDue'], false);
        $this->createIndex(null, Table::TICKETS, ['resolutionBreached', 'resolutionDue'], false);

        $this->createTable(Table::REPLIES, [
            'id' => $this->primaryKey(),
            'ticketId' => $this->integer()->notNull(),
            'userId' => $this->integer(),
            'authorName' => $this->string(),
            'authorEmail' => $this->string(),
            'body' => $this->mediumText()->notNull(),
            // An internal note is a reply nobody outside the desk may ever see. Same table on
            // purpose: the timeline is one sequence, and two tables would have to be merged in
            // PHP on every render and would eventually disagree about the order.
            'isNote' => $this->boolean()->defaultValue(false)->notNull(),
            'byAgent' => $this->boolean()->defaultValue(false)->notNull(),
            'cannedReplyId' => $this->integer(),
            'source' => $this->string(16),
            'ipAddress' => $this->string(45),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::REPLIES, ['ticketId', 'dateCreated'], false);
        $this->createIndex(null, Table::REPLIES, ['isNote'], false);

        $this->createTable(Table::ATTACHMENTS, [
            'id' => $this->primaryKey(),
            'ticketId' => $this->integer()->notNull(),
            'replyId' => $this->integer(),
            'assetId' => $this->integer(),
            'filename' => $this->string()->notNull(),
            'size' => $this->bigInteger()->unsigned()->defaultValue(0)->notNull(),
            'kind' => $this->string(32),
            'uploadedBy' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::ATTACHMENTS, ['ticketId'], false);
        $this->createIndex(null, Table::ATTACHMENTS, ['replyId'], false);

        $this->createTable(Table::EVENTS, [
            'id' => $this->primaryKey(),
            'ticketId' => $this->integer()->notNull(),
            'type' => $this->string(32)->notNull(),
            'userId' => $this->integer(),
            'data' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::EVENTS, ['ticketId', 'dateCreated'], false);

        $this->createTable(Table::CANNED_REPLIES, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            // What an agent types to insert it. `/refund`, not a menu three clicks deep.
            'shortcut' => $this->string(64),
            'body' => $this->mediumText()->notNull(),
            'categoryId' => $this->integer(),
            'teamId' => $this->integer(),
            'enabled' => $this->boolean()->defaultValue(true)->notNull(),
            'useCount' => $this->integer()->unsigned()->defaultValue(0)->notNull(),
            'sortOrder' => $this->smallInteger()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::CANNED_REPLIES, ['shortcut'], false);
        $this->createIndex(null, Table::CANNED_REPLIES, ['name'], true);
    }

    /**
     * The foreign keys, all in one pass at the end.
     *
     * Because several of them point between tables created in different methods above, and a
     * migration that adds a key to a table it has not created yet fails in a way that leaves the
     * schema half-built.
     */
    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::ARTICLES_SITES, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');

        $this->addForeignKey(null, Table::CATEGORIES, ['parentId'], Table::CATEGORIES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::CATEGORIES, ['teamId'], Table::TEAMS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::ARTICLES, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        // Deleting a category must not delete the articles in it — that is a filing decision, and
        // the articles are the asset.
        $this->addForeignKey(null, Table::ARTICLES, ['categoryId'], Table::CATEGORIES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::ARTICLES, ['authorId'], CraftTable::USERS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::ARTICLE_RELATED, ['articleId'], Table::ARTICLES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::ARTICLE_RELATED, ['relatedId'], Table::ARTICLES, ['id'], 'CASCADE', null);

        $this->addForeignKey(null, Table::RATINGS, ['articleId'], Table::ARTICLES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::RATINGS, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, Table::RATINGS, ['userId'], CraftTable::USERS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::VIEWS, ['articleId'], Table::ARTICLES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::VIEWS, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');

        // A search outlives the article it found. Deleting an article must not rewrite the record
        // of what people were looking for when they found it.
        $this->addForeignKey(null, Table::SEARCHES, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, Table::SEARCHES, ['clickedArticleId'], Table::ARTICLES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::SEARCHES, ['userId'], CraftTable::USERS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::DEFLECTIONS, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, Table::DEFLECTIONS, ['categoryId'], Table::CATEGORIES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::DEFLECTIONS, ['clickedArticleId'], Table::ARTICLES, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::TEAM_MEMBERS, ['teamId'], Table::TEAMS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::TEAM_MEMBERS, ['userId'], CraftTable::USERS, ['id'], 'CASCADE', null);

        $this->addForeignKey(null, Table::TICKETS, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::TICKETS, ['statusId'], Table::STATUSES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::TICKETS, ['categoryId'], Table::CATEGORIES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::TICKETS, ['agentId'], CraftTable::USERS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::TICKETS, ['teamId'], Table::TEAMS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::TICKETS, ['requesterId'], CraftTable::USERS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::TICKETS, ['submittedSiteId'], CraftTable::SITES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::TICKETS, ['slaPolicyId'], Table::SLA_POLICIES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::TICKETS, ['deflectionId'], Table::DEFLECTIONS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::TICKETS, ['mergedIntoId'], Table::TICKETS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::DEFLECTIONS, ['ticketId'], Table::TICKETS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::REPLIES, ['ticketId'], Table::TICKETS, ['id'], 'CASCADE', null);
        // The reply survives its author's account being deleted. What was said to the customer is
        // still what was said to the customer after somebody leaves the company.
        $this->addForeignKey(null, Table::REPLIES, ['userId'], CraftTable::USERS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::REPLIES, ['cannedReplyId'], Table::CANNED_REPLIES, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::ATTACHMENTS, ['ticketId'], Table::TICKETS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::ATTACHMENTS, ['replyId'], Table::REPLIES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::ATTACHMENTS, ['assetId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::ATTACHMENTS, ['uploadedBy'], CraftTable::USERS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::EVENTS, ['ticketId'], Table::TICKETS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::EVENTS, ['userId'], CraftTable::USERS, ['id'], 'SET NULL', null);

        $this->addForeignKey(null, Table::CANNED_REPLIES, ['categoryId'], Table::CATEGORIES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::CANNED_REPLIES, ['teamId'], Table::TEAMS, ['id'], 'SET NULL', null);
    }

    public function safeDown(): bool
    {
        // The elements go first. The CASCADE runs the other way, so dropping the tables alone
        // would leave rows in `elements` pointing at an element type that no longer exists — and
        // every element index in the control panel then throws.
        $this->delete(CraftTable::ELEMENTS, ['type' => Ticket::class]);
        $this->delete(CraftTable::ELEMENTS, ['type' => Article::class]);

        // `csr_tickets` and `csr_deflections` point at each other — a ticket knows which
        // suggestions session produced it, and that session knows which ticket it became. There is
        // therefore no order in which these tables can be dropped, and the uninstall fails halfway
        // through with "Cannot delete or update a parent row", leaving half a schema behind and a
        // plugin that will not reinstall. Suspending integrity checks for the duration is the only
        // way round it, and is exactly what the setting is for.
        $this->db->createCommand()->checkIntegrity(false)->execute();

        $this->dropTableIfExists(Table::EVENTS);
        $this->dropTableIfExists(Table::ATTACHMENTS);
        $this->dropTableIfExists(Table::REPLIES);
        $this->dropTableIfExists(Table::TICKETS);
        $this->dropTableIfExists(Table::CANNED_REPLIES);
        $this->dropTableIfExists(Table::DEFLECTIONS);
        $this->dropTableIfExists(Table::SEARCHES);
        $this->dropTableIfExists(Table::VIEWS);
        $this->dropTableIfExists(Table::RATINGS);
        $this->dropTableIfExists(Table::ARTICLE_RELATED);
        $this->dropTableIfExists(Table::ARTICLES);
        $this->dropTableIfExists(Table::CATEGORIES);
        $this->dropTableIfExists(Table::ARTICLES_SITES);
        $this->dropTableIfExists(Table::FORM_FIELDS);
        $this->dropTableIfExists(Table::SLA_POLICIES);
        $this->dropTableIfExists(Table::TEAM_MEMBERS);
        $this->dropTableIfExists(Table::TEAMS);
        $this->dropTableIfExists(Table::STATUSES);

        $this->db->createCommand()->checkIntegrity(true)->execute();

        return true;
    }
}
