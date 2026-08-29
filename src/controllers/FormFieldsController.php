<?php

declare(strict_types=1);

namespace justinholtweb\csr\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\csr\enums\FieldType;
use justinholtweb\csr\models\Edition;
use justinholtweb\csr\models\FormField;
use justinholtweb\csr\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/** The site's own questions on the support form. Pro. */
class FormFieldsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requireAdmin();

        if (!Edition::allowsCustomFormFields(Plugin::getInstance()->isPro())) {
            throw new ForbiddenHttpException('Custom form fields are a Pro feature.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('csr/settings/form-fields/_index', [
            'fields' => Plugin::getInstance()->forms->getAllFields(),
        ]);
    }

    public function actionEdit(?int $fieldId = null, ?FormField $formField = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($formField === null) {
            if ($fieldId !== null) {
                $formField = $plugin->forms->getFieldById($fieldId);

                if ($formField === null) {
                    throw new NotFoundHttpException('Field not found.');
                }
            } else {
                $formField = new FormField();
            }
        }

        $categoryOptions = [];

        foreach ($plugin->categories->getAllCategories() as $category) {
            $categoryOptions[] = ['label' => $category->name, 'value' => (string)$category->uid];
        }

        return $this->renderTemplate('csr/settings/form-fields/_edit', [
            'formField' => $formField,
            'isNew' => $formField->id === null,
            'typeOptions' => FieldType::options(),
            'categoryOptions' => $categoryOptions,
            'title' => $formField->id !== null ? $formField->name : Craft::t('csr', 'New question'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $fieldId = $request->getBodyParam('fieldId');

        $formField = $fieldId ? $plugin->forms->getFieldById((int)$fieldId) : new FormField();

        if ($formField === null) {
            throw new NotFoundHttpException('Field not found.');
        }

        $formField->name = (string)$request->getBodyParam('name');
        $formField->handle = (string)($request->getBodyParam('handle') ?: StringHelper::toCamelCase($formField->name));
        $formField->type = (string)$request->getBodyParam('type', FieldType::Text->value);
        $formField->instructions = $request->getBodyParam('instructions') ?: null;
        $formField->placeholder = $request->getBodyParam('placeholder') ?: null;
        $formField->required = (bool)$request->getBodyParam('required');
        $formField->enabled = (bool)$request->getBodyParam('enabled', true);
        $formField->setOptions($request->getBodyParam('options') ?? []);
        $formField->setCategoryUids($request->getBodyParam('categoryUids') ?? []);

        if (!$plugin->forms->saveField($formField)) {
            Craft::$app->getSession()->setError(Craft::t('csr', 'Couldn’t save the question.'));
            Craft::$app->getUrlManager()->setRouteParams(['formField' => $formField]);

            return null;
        }

        return $this->asSuccess(
            Craft::t('csr', 'Question saved.'),
            ['formField' => $formField],
            UrlHelper::cpUrl('csr/settings/form-fields'),
        );
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        Plugin::getInstance()->forms->deleteFieldById($id);

        return $this->asSuccess(Craft::t('csr', 'Question deleted. The answers people gave it are kept.'));
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $uids = Craft::$app->getRequest()->getRequiredBodyParam('ids');
        Plugin::getInstance()->forms->reorderFields((array)$uids);

        return $this->asSuccess(Craft::t('csr', 'Questions reordered.'));
    }
}
