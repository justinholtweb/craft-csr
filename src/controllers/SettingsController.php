<?php

declare(strict_types=1);

namespace justinholtweb\csr\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\csr\elements\Article;
use justinholtweb\csr\elements\Ticket;
use justinholtweb\csr\enums\Priority;
use justinholtweb\csr\models\ArticleSiteSettings;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\models\Settings;
use justinholtweb\csr\Plugin;
use yii\web\Response;

/**
 * CSR's settings screens.
 *
 * Split across several pages rather than one, because a single screen holding the knowledge base,
 * the support form, spam, notifications, opening hours and two field layouts is a screen nobody
 * reads — they scroll to the switch they came for and never learn the rest exists.
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requireAdmin();

        return true;
    }

    public function actionGeneral(): Response
    {
        return $this->screen('general', [
            'pigeonInstalled' => Plugin::getInstance()->pigeon->isInstalled(),
        ]);
    }

    public function actionKnowledgeBase(): Response
    {
        return $this->screen('knowledge-base');
    }

    public function actionSupportForm(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->screen('support-form', [
            'priorityOptions' => Priority::options(),
            'volumeOptions' => $this->volumeOptions(),
            'allowsCustomFields' => Edition::allowsCustomFormFields($plugin->isPro()),
        ]);
    }

    public function actionNotifications(): Response
    {
        return $this->screen('notifications', [
            'allowsSlas' => Edition::allowsSlas(Plugin::getInstance()->isPro()),
            'allowsBusinessHours' => Edition::allowsBusinessHours(Plugin::getInstance()->isPro()),
            'weekdays' => Settings::WEEKDAYS,
            'timezoneOptions' => $this->timezoneOptions(),
        ]);
    }

    public function actionSpam(): Response
    {
        return $this->screen('spam');
    }

    public function actionSites(): Response
    {
        $plugin = Plugin::getInstance();
        $existing = $plugin->articles->getAllSiteSettings();
        $siteSettings = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings[(int)$site->id] = $existing[(int)$site->id] ?? new ArticleSiteSettings([
                'siteId' => (int)$site->id,
                'uriFormat' => trim($plugin->getSettings()->kbPath, '/') . '/{slug}',
                'baseUri' => trim($plugin->getSettings()->kbPath, '/'),
            ]);
        }

        return $this->renderTemplate('csr/settings/_sites', [
            'settings' => $plugin->getSettings(),
            'siteSettings' => $siteSettings,
            'sites' => Craft::$app->getSites()->getAllSites(),
            'selectedTab' => 'sites',
        ]);
    }

    public function actionSaveSites(): ?Response
    {
        $this->requirePostRequest();

        $posted = Craft::$app->getRequest()->getBodyParam('sites', []);
        $all = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $row = $posted[$site->uid] ?? $posted[$site->id] ?? null;

            if (!is_array($row)) {
                continue;
            }

            $all[] = new ArticleSiteSettings([
                'siteId' => (int)$site->id,
                'enabledByDefault' => (bool)($row['enabledByDefault'] ?? true),
                'uriFormat' => ($row['uriFormat'] ?? '') !== '' ? $row['uriFormat'] : null,
                'template' => ($row['template'] ?? '') !== '' ? $row['template'] : null,
                'baseUri' => ($row['baseUri'] ?? '') !== '' ? trim((string)$row['baseUri'], '/') : null,
            ]);
        }

        if (!Plugin::getInstance()->articles->saveSiteSettings($all)) {
            Craft::$app->getSession()->setError(Craft::t('csr', 'Couldn’t save the site settings.'));

            return null;
        }

        return $this->asSuccess(
            Craft::t('csr', 'Site settings saved.'),
            redirect: UrlHelper::cpUrl('csr/settings/sites'),
        );
    }

    // Field layouts
    // -------------------------------------------------------------------------

    public function actionArticleFields(): Response
    {
        return $this->renderTemplate('csr/settings/_fields', [
            'settings' => Plugin::getInstance()->getSettings(),
            'fieldLayout' => Plugin::getInstance()->articles->getFieldLayout(),
            'selectedTab' => 'article-fields',
            'saveAction' => 'csr/settings/save-article-fields',
            'heading' => Craft::t('csr', 'Article fields'),
            'instructions' => Craft::t('csr', 'The body of an article is the site’s own fields. CSR only owns the things it has to reason about — the category, who may read it, and the phrases it should be found by.'),
        ]);
    }

    public function actionSaveArticleFields(): ?Response
    {
        $this->requirePostRequest();

        $layout = Craft::$app->getFields()->assembleLayoutFromPost();
        $layout->type = Article::class;

        Plugin::getInstance()->articles->saveFieldLayout($layout);

        return $this->asSuccess(
            Craft::t('csr', 'Article fields saved.'),
            redirect: UrlHelper::cpUrl('csr/settings/article-fields'),
        );
    }

    public function actionTicketFields(): Response
    {
        return $this->renderTemplate('csr/settings/_fields', [
            'settings' => Plugin::getInstance()->getSettings(),
            'fieldLayout' => Craft::$app->getFields()->getLayoutByType(Ticket::class),
            'selectedTab' => 'ticket-fields',
            'saveAction' => 'csr/settings/save-ticket-fields',
            'heading' => Craft::t('csr', 'Ticket fields'),
            'instructions' => Craft::t('csr', 'What *your team* records about a ticket after it arrives — an account number, an internal reference. The customer never sees these; the questions they answer are on the support form.'),
        ]);
    }

    public function actionSaveTicketFields(): ?Response
    {
        $this->requirePostRequest();

        $layout = Craft::$app->getFields()->assembleLayoutFromPost();
        $layout->type = Ticket::class;

        Craft::$app->getProjectConfig()->set(
            \justinholtweb\csr\services\Tickets::CONFIG_FIELD_LAYOUT_KEY,
            [$layout->uid => $layout->getConfig()],
            'Update the CSR ticket field layout',
        );

        return $this->asSuccess(
            Craft::t('csr', 'Ticket fields saved.'),
            redirect: UrlHelper::cpUrl('csr/settings/ticket-fields'),
        );
    }

    // Saving the plugin settings
    // -------------------------------------------------------------------------

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $posted = Craft::$app->getRequest()->getBodyParam('settings', []);

        // Merged onto what is already stored rather than replacing it: each screen posts only its
        // own switches, and a settings model saved from a partial payload would reset every value
        // the current screen does not render.
        $settings->setAttributes($posted, false);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            Craft::$app->getSession()->setError(Craft::t('csr', 'Couldn’t save the settings.'));
            Craft::$app->getUrlManager()->setRouteParams(['settings' => $settings]);

            return null;
        }

        $plugin->search->refresh();
        $plugin->slas->refresh();

        return $this->asSuccess(
            Craft::t('csr', 'Settings saved.'),
            redirect: Craft::$app->getRequest()->getReferrer() ?: UrlHelper::cpUrl('csr/settings/general'),
        );
    }

    /** @param array<string, mixed> $variables */
    private function screen(string $tab, array $variables = []): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('csr/settings/_' . $tab, $variables + [
            'settings' => $plugin->getSettings(),
            'selectedTab' => $tab,
            'isPro' => $plugin->isPro(),
        ]);
    }

    /** @return array<int, array{label: string, value: string}> */
    private function volumeOptions(): array
    {
        $options = [['label' => Craft::t('csr', 'Attachments off'), 'value' => '']];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $options[] = ['label' => $volume->name, 'value' => (string)$volume->uid];
        }

        return $options;
    }

    /** @return array<int, array{label: string, value: string}> */
    private function timezoneOptions(): array
    {
        $options = [['label' => Craft::t('csr', 'The system time zone'), 'value' => '']];

        foreach (timezone_identifiers_list() as $timezone) {
            $options[] = ['label' => $timezone, 'value' => $timezone];
        }

        return $options;
    }
}
