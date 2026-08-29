<?php

declare(strict_types=1);

namespace justinholtweb\csr;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterEmailMessagesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\ProjectConfig;
use craft\services\SystemMessages;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\models\Settings;
use justinholtweb\csr\services\Analytics;
use justinholtweb\csr\services\Articles;
use justinholtweb\csr\services\CannedReplies;
use justinholtweb\csr\services\Categories;
use justinholtweb\csr\services\Deflection;
use justinholtweb\csr\services\Exporter;
use justinholtweb\csr\services\Forms;
use justinholtweb\csr\services\Notifications;
use justinholtweb\csr\services\Pigeon;
use justinholtweb\csr\services\Ratings;
use justinholtweb\csr\services\Replies;
use justinholtweb\csr\services\Schema;
use justinholtweb\csr\services\Search;
use justinholtweb\csr\services\Slas;
use justinholtweb\csr\services\Spam;
use justinholtweb\csr\services\Statuses;
use justinholtweb\csr\services\Teams;
use justinholtweb\csr\services\Tickets;
use justinholtweb\csr\twig\CsrVariable;
use justinholtweb\csr\widgets\DeskWidget;
use yii\base\Event;

/**
 * CSR — a knowledge base and a support desk for Craft CMS.
 *
 * The argument the plugin makes is one sentence: **the cheapest support ticket is the one nobody
 * raises.** Everything follows from it. Articles are elements so they are real pages with real
 * URLs that Google can find; the search box records what it could not answer so somebody knows
 * what to write next; the support form shows likely answers before it will take a message, and
 * counts how often that worked; and the tickets that do arrive have a clock on them.
 *
 * @property-read Articles $articles
 * @property-read Categories $categories
 * @property-read Search $search
 * @property-read Ratings $ratings
 * @property-read Deflection $deflection
 * @property-read Tickets $tickets
 * @property-read Replies $replies
 * @property-read Statuses $statuses
 * @property-read Slas $slas
 * @property-read Teams $teams
 * @property-read Forms $forms
 * @property-read CannedReplies $cannedReplies
 * @property-read Notifications $notifications
 * @property-read Analytics $analytics
 * @property-read Exporter $exporter
 * @property-read Spam $spam
 * @property-read Pigeon $pigeon
 * @property-read Schema $schema
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_VIEW_ARTICLES = 'csr:viewArticles';
    public const PERMISSION_MANAGE_ARTICLES = 'csr:manageArticles';
    public const PERMISSION_DELETE_ARTICLES = 'csr:deleteArticles';
    public const PERMISSION_MANAGE_CATEGORIES = 'csr:manageCategories';
    public const PERMISSION_VIEW_TICKETS = 'csr:viewTickets';
    public const PERMISSION_MANAGE_TICKETS = 'csr:manageTickets';
    public const PERMISSION_DELETE_TICKETS = 'csr:deleteTickets';
    public const PERMISSION_EXPORT = 'csr:export';
    public const PERMISSION_VIEW_REPORTS = 'csr:viewReports';
    public const PERMISSION_MANAGE_CANNED_REPLIES = 'csr:manageCannedReplies';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'csr';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'articles' => Articles::class,
                'categories' => Categories::class,
                'search' => Search::class,
                'ratings' => Ratings::class,
                'deflection' => Deflection::class,
                'tickets' => Tickets::class,
                'replies' => Replies::class,
                'statuses' => Statuses::class,
                'slas' => Slas::class,
                'teams' => Teams::class,
                'forms' => Forms::class,
                'cannedReplies' => CannedReplies::class,
                'notifications' => Notifications::class,
                'analytics' => Analytics::class,
                'exporter' => Exporter::class,
                'spam' => Spam::class,
                'pigeon' => Pigeon::class,
                'schema' => Schema::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerElementTypes();
        $this->registerCpRoutes();
        $this->registerSiteRoutes();
        $this->registerPermissions();
        $this->registerProjectConfig();
        $this->registerSystemMessages();
        $this->registerWidgets();
        $this->registerTwig();
        $this->registerGarbageCollection();
    }

    /** Whether the Pro feature set is available. Every edition check goes through here. */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    /**
     * Seed the things a help desk cannot work without.
     *
     * Here rather than in the migration, because project-config writes from a migration are
     * buffered and can land before the plugin's own row exists — the change then vanishes
     * silently while every call still returns true.
     */
    protected function afterInstall(): void
    {
        parent::afterInstall();

        if (Craft::$app->getProjectConfig()->getIsApplyingExternalChanges()) {
            // The config is arriving from somewhere else and already contains the statuses, site
            // settings and categories this would create. Seeding on top of it would produce
            // duplicates with different uids on every environment.
            return;
        }

        $this->statuses->installDefaults();
        $this->articles->installDefaultSiteSettings();
        $this->categories->createDefaults();
    }

    /**
     * Take CSR's own configuration with it.
     *
     * The migration drops the tables; without this the *project config* keeps every status, team,
     * SLA policy, form field and per-site setting the install created. Reinstalling then produces
     * a plugin whose YAML describes ten statuses and whose database has five — and whose site
     * settings never come back at all, because writing the value that is already there is a no-op
     * and fires no handler. The symptom is a fresh install with no article URLs and no obvious
     * reason why.
     */
    protected function afterUninstall(): void
    {
        parent::afterUninstall();

        if (!Craft::$app->getProjectConfig()->getIsApplyingExternalChanges()) {
            Craft::$app->getProjectConfig()->remove('csr', 'Remove the CSR configuration');
        }
    }

    // Elements
    // -------------------------------------------------------------------------

    private function registerElementTypes(): void
    {
        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, static function (RegisterComponentTypesEvent $event) {
            $event->types[] = Article::class;
            $event->types[] = Ticket::class;
        });
    }

    // Routes
    // -------------------------------------------------------------------------

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, static function (RegisterUrlRulesEvent $event) {
            $event->rules['csr'] = 'csr/tickets/index';

            $event->rules['csr/articles'] = 'csr/articles/index';
            $event->rules['csr/articles/new'] = 'csr/articles/edit';
            $event->rules['csr/articles/<articleId:\d+>'] = 'csr/articles/edit';

            $event->rules['csr/categories'] = 'csr/categories/index';
            $event->rules['csr/categories/new'] = 'csr/categories/edit';
            $event->rules['csr/categories/<categoryId:\d+>'] = 'csr/categories/edit';

            $event->rules['csr/tickets'] = 'csr/tickets/index';
            $event->rules['csr/tickets/new'] = 'csr/tickets/edit';
            $event->rules['csr/tickets/<ticketId:\d+>'] = 'csr/tickets/edit';

            $event->rules['csr/canned-replies'] = 'csr/canned-replies/index';
            $event->rules['csr/canned-replies/new'] = 'csr/canned-replies/edit';
            $event->rules['csr/canned-replies/<replyId:\d+>'] = 'csr/canned-replies/edit';

            $event->rules['csr/reports'] = 'csr/reports/index';
            $event->rules['csr/reports/searches'] = 'csr/reports/searches';
            $event->rules['csr/reports/articles'] = 'csr/reports/articles';
            $event->rules['csr/reports/desk'] = 'csr/reports/desk';
            $event->rules['csr/reports/deflection'] = 'csr/reports/deflection';

            $event->rules['csr/settings'] = 'csr/settings/general';
            $event->rules['csr/settings/general'] = 'csr/settings/general';
            $event->rules['csr/settings/knowledge-base'] = 'csr/settings/knowledge-base';
            $event->rules['csr/settings/sites'] = 'csr/settings/sites';
            $event->rules['csr/settings/support-form'] = 'csr/settings/support-form';
            $event->rules['csr/settings/notifications'] = 'csr/settings/notifications';
            $event->rules['csr/settings/spam'] = 'csr/settings/spam';
            $event->rules['csr/settings/article-fields'] = 'csr/settings/article-fields';
            $event->rules['csr/settings/ticket-fields'] = 'csr/settings/ticket-fields';

            $event->rules['csr/settings/statuses'] = 'csr/statuses/index';
            $event->rules['csr/settings/statuses/new'] = 'csr/statuses/edit';
            $event->rules['csr/settings/statuses/<statusId:\d+>'] = 'csr/statuses/edit';

            $event->rules['csr/settings/teams'] = 'csr/teams/index';
            $event->rules['csr/settings/teams/new'] = 'csr/teams/edit';
            $event->rules['csr/settings/teams/<teamId:\d+>'] = 'csr/teams/edit';

            $event->rules['csr/settings/slas'] = 'csr/slas/index';
            $event->rules['csr/settings/slas/new'] = 'csr/slas/edit';
            $event->rules['csr/settings/slas/<policyId:\d+>'] = 'csr/slas/edit';

            $event->rules['csr/settings/form-fields'] = 'csr/form-fields/index';
            $event->rules['csr/settings/form-fields/new'] = 'csr/form-fields/edit';
            $event->rules['csr/settings/form-fields/<fieldId:\d+>'] = 'csr/form-fields/edit';
        });
    }

    /**
     * The front-end routes, built from the paths in the settings.
     *
     * Categories live under `<kb>/category/<handle>` rather than `<kb>/<handle>` on purpose:
     * articles are at `<kb>/{slug}`, and a flat namespace would make a category called "billing"
     * and an article slugged "billing" the same URL — resolved differently depending on which of
     * Craft's two lookups happens to run first, which is exactly the kind of bug that appears
     * eighteen months later on one site.
     */
    private function registerSiteRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function (RegisterUrlRulesEvent $event) {
            $settings = $this->getSettings();

            if ($settings->kbEnabled) {
                $kb = trim($settings->kbPath, '/');

                if ($kb !== '') {
                    $event->rules[$kb] = 'csr/kb/index';
                    $event->rules[$kb . '/search'] = 'csr/kb/search';
                    $event->rules[$kb . '/category/<handle:[^\/]+>'] = 'csr/kb/category';
                }
            }

            if ($settings->supportEnabled) {
                $support = trim($settings->supportPath, '/');

                if ($support !== '') {
                    $event->rules[$support] = 'csr/support/form';
                }

                if ($settings->portalEnabled) {
                    $portal = trim($settings->portalPath, '/');

                    if ($portal !== '' && $portal !== $support) {
                        $event->rules[$portal] = 'csr/portal/view';
                    }
                }
            }
        });
    }

    // Permissions
    // -------------------------------------------------------------------------

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, static function (RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('csr', 'CSR'),
                'permissions' => [
                    self::PERMISSION_VIEW_ARTICLES => [
                        'label' => Craft::t('csr', 'View knowledge base articles'),
                        'nested' => [
                            self::PERMISSION_MANAGE_ARTICLES => [
                                'label' => Craft::t('csr', 'Write and edit articles'),
                                'nested' => [
                                    self::PERMISSION_DELETE_ARTICLES => ['label' => Craft::t('csr', 'Delete articles')],
                                ],
                            ],
                            self::PERMISSION_MANAGE_CATEGORIES => ['label' => Craft::t('csr', 'Manage categories')],
                        ],
                    ],
                    self::PERMISSION_VIEW_TICKETS => [
                        'label' => Craft::t('csr', 'View tickets'),
                        'nested' => [
                            self::PERMISSION_MANAGE_TICKETS => [
                                'label' => Craft::t('csr', 'Answer and manage tickets'),
                                'nested' => [
                                    self::PERMISSION_DELETE_TICKETS => ['label' => Craft::t('csr', 'Delete tickets')],
                                ],
                            ],
                            self::PERMISSION_EXPORT => ['label' => Craft::t('csr', 'Export tickets and searches')],
                            self::PERMISSION_MANAGE_CANNED_REPLIES => ['label' => Craft::t('csr', 'Manage canned replies')],
                        ],
                    ],
                    self::PERMISSION_VIEW_REPORTS => ['label' => Craft::t('csr', 'View reports')],
                ],
            ];
        });
    }

    // Project config
    // -------------------------------------------------------------------------

    private function registerProjectConfig(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        $projectConfig
            ->onAdd(Statuses::CONFIG_KEY . '.{uid}', [$this->statuses, 'handleChangedStatus'])
            ->onUpdate(Statuses::CONFIG_KEY . '.{uid}', [$this->statuses, 'handleChangedStatus'])
            ->onRemove(Statuses::CONFIG_KEY . '.{uid}', [$this->statuses, 'handleDeletedStatus']);

        $projectConfig
            ->onAdd(Teams::CONFIG_KEY . '.{uid}', [$this->teams, 'handleChangedTeam'])
            ->onUpdate(Teams::CONFIG_KEY . '.{uid}', [$this->teams, 'handleChangedTeam'])
            ->onRemove(Teams::CONFIG_KEY . '.{uid}', [$this->teams, 'handleDeletedTeam']);

        $projectConfig
            ->onAdd(Slas::CONFIG_KEY . '.{uid}', [$this->slas, 'handleChangedPolicy'])
            ->onUpdate(Slas::CONFIG_KEY . '.{uid}', [$this->slas, 'handleChangedPolicy'])
            ->onRemove(Slas::CONFIG_KEY . '.{uid}', [$this->slas, 'handleDeletedPolicy']);

        $projectConfig
            ->onAdd(Forms::CONFIG_KEY . '.{uid}', [$this->forms, 'handleChangedField'])
            ->onUpdate(Forms::CONFIG_KEY . '.{uid}', [$this->forms, 'handleChangedField'])
            ->onRemove(Forms::CONFIG_KEY . '.{uid}', [$this->forms, 'handleDeletedField']);

        $projectConfig
            ->onAdd(Articles::CONFIG_SITES_KEY . '.{uid}', [$this->articles, 'handleChangedSiteSettings'])
            ->onUpdate(Articles::CONFIG_SITES_KEY . '.{uid}', [$this->articles, 'handleChangedSiteSettings'])
            ->onRemove(Articles::CONFIG_SITES_KEY . '.{uid}', [$this->articles, 'handleDeletedSiteSettings']);

        $projectConfig
            ->onAdd(Articles::CONFIG_FIELD_LAYOUT_KEY, [$this->articles, 'handleChangedFieldLayout'])
            ->onUpdate(Articles::CONFIG_FIELD_LAYOUT_KEY, [$this->articles, 'handleChangedFieldLayout'])
            ->onRemove(Articles::CONFIG_FIELD_LAYOUT_KEY, [$this->articles, 'handleChangedFieldLayout']);

        $projectConfig
            ->onAdd(Tickets::CONFIG_FIELD_LAYOUT_KEY, [$this->tickets, 'handleChangedFieldLayout'])
            ->onUpdate(Tickets::CONFIG_FIELD_LAYOUT_KEY, [$this->tickets, 'handleChangedFieldLayout'])
            ->onRemove(Tickets::CONFIG_FIELD_LAYOUT_KEY, [$this->tickets, 'handleChangedFieldLayout']);

        Event::on(ProjectConfig::class, ProjectConfig::EVENT_REBUILD, function (RebuildConfigEvent $event) {
            $event->config['csr'] = [
                'statuses' => $this->statuses->rebuildProjectConfig(),
                'teams' => $this->teams->rebuildProjectConfig(),
                'slaPolicies' => $this->slas->rebuildProjectConfig(),
                'formFields' => $this->forms->rebuildProjectConfig(),
                'articleSites' => $this->articles->rebuildSiteSettingsConfig(),
            ];
        });
    }

    private function registerSystemMessages(): void
    {
        Event::on(SystemMessages::class, SystemMessages::EVENT_REGISTER_MESSAGES, static function (RegisterEmailMessagesEvent $event) {
            foreach (Notifications::systemMessages() as $message) {
                $event->messages[] = $message;
            }
        });
    }

    private function registerWidgets(): void
    {
        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, static function (RegisterComponentTypesEvent $event) {
            $event->types[] = DeskWidget::class;
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, static function (Event $event) {
            $event->sender->set('csr', CsrVariable::class);
        });
    }

    /**
     * Housekeeping, hooked to Craft's own garbage collection.
     *
     * Retention rather than tidiness: search rows and deflection sessions carry no personal data
     * beyond a salted hash, but a table nobody prunes outgrows its usefulness, and a support desk
     * that promised to delete resolved tickets after a year has to actually do it.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function () {
            $settings = $this->getSettings();

            $this->search->prune();
            $this->deflection->closeStaleSessions();
            $this->deflection->prune($settings->searchRetentionDays);
            $this->articles->pruneViews(max($settings->popularWindowDays * 12, 365));
            $this->articles->deleteOrphans();
            $this->tickets->closeResolved();
            $this->tickets->prune();
        });
    }

    // Control panel
    // -------------------------------------------------------------------------

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if ($item === null) {
            return null;
        }

        $user = Craft::$app->getUser();
        $subNav = [];

        if ($user->checkPermission(self::PERMISSION_VIEW_TICKETS)) {
            $subNav['tickets'] = ['label' => Craft::t('csr', 'Tickets'), 'url' => 'csr/tickets'];
        }

        if ($user->checkPermission(self::PERMISSION_VIEW_ARTICLES)) {
            $subNav['articles'] = ['label' => Craft::t('csr', 'Articles'), 'url' => 'csr/articles'];
            $subNav['categories'] = ['label' => Craft::t('csr', 'Categories'), 'url' => 'csr/categories'];
        }

        if ($this->isPro() && $user->checkPermission(self::PERMISSION_MANAGE_CANNED_REPLIES)) {
            $subNav['cannedReplies'] = ['label' => Craft::t('csr', 'Canned replies'), 'url' => 'csr/canned-replies'];
        }

        if ($user->checkPermission(self::PERMISSION_VIEW_REPORTS)) {
            $subNav['reports'] = ['label' => Craft::t('csr', 'Reports'), 'url' => 'csr/reports'];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subNav['settings'] = ['label' => Craft::t('app', 'Settings'), 'url' => 'csr/settings'];
        }

        if ($subNav === []) {
            return null;
        }

        $item['subnav'] = $subNav;

        // The badge is the number of tickets nobody has answered yet — not the number that are
        // open. "Open" on a busy desk is a permanent three-digit number that everybody stops
        // seeing; "waiting for a first reply" is a number that should be zero by the end of the
        // day, and a badge showing it is worth glancing at.
        if ($user->checkPermission(self::PERMISSION_VIEW_TICKETS)) {
            try {
                $waiting = (int)Ticket::find()->openOnly()->awaitingFirstResponse()->count();

                if ($waiting > 0) {
                    $item['badgeCount'] = $waiting;
                }
            } catch (\Throwable) {
                // The nav is drawn before the schema exists during an install, and a broken
                // control panel is a worse outcome than a missing badge.
            }
        }

        return $item;
    }

    /**
     * Settings live on CSR's own screens, not on the plugin settings page.
     *
     * There are eleven of them and several are element-index-shaped, which the plugin settings
     * page cannot hold. Redirecting is safe here because the destination is *not*
     * `settings/plugins/csr` — redirecting to that URL from this method is an infinite loop, since
     * that is the URL Craft renders `settingsHtml()` at in the first place.
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('csr/settings/general'));
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /** What Pro buys, in one place, for the screens that need to say so. */
    public function edition(): string
    {
        return $this->isPro() ? self::EDITION_PRO : self::EDITION_LITE;
    }

    /** Whether a Pro-only feature is available, by the name of its `Edition` method. */
    public function allows(string $feature): bool
    {
        $method = 'allows' . ucfirst($feature);

        return method_exists(Edition::class, $method)
            ? Edition::$method($this->isPro())
            : $this->isPro();
    }
}
