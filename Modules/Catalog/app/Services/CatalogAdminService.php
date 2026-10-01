<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Catalog\Http\CatalogApiException;
use Modules\Catalog\Models\CatalogModel;
use Modules\Catalog\Models\CatalogNode;
use Modules\Catalog\Models\CatalogProduct;
use Modules\Catalog\Models\LatexTemplate;
use Modules\Catalog\Models\SeriesField;
use Modules\Catalog\Models\TypstTemplate;

/** Authorization and form adaptation; the imported services remain the write authority. */
class CatalogAdminService
{
    public function __construct(
        private HierarchyService $hierarchy,
        private ProductService $products,
        private SeriesFieldService $fields,
        private SeriesAttributeService $metadata,
        private TypstService $typst,
        private LatexTemplateService $latex,
    ) {}

    public function saveNode(User $actor, array $data, ?CatalogNode $record = null): CatalogNode
    {
        $this->authorize($actor, $record === null ? 'create' : 'update', $record ?? CatalogNode::class);
        $data = $this->validate($data, [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['category', 'series'])],
            'parent_id' => ['nullable', 'integer', Rule::exists(CatalogNode::class, 'id')->where('type', 'category')],
            'display_order' => ['required', 'integer', 'between:-2147483648,2147483647'],
            'typst_templating_enabled' => ['sometimes', 'boolean'],
        ]);
        if ($data['type'] === 'series' && empty($data['parent_id'])) {
            $this->invalid('parent_id', 'Series must have a parent category.');
        }
        // Legacy APIs keep their original contracts; new admin edits must not create cycles.
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        $visited = [];
        while ($parentId !== null) {
            if ($parentId === $record?->getKey() || isset($visited[$parentId])) {
                $this->invalid('parent_id', 'A node cannot be moved into its own descendants.');
            }
            $visited[$parentId] = true;
            $parentId = CatalogNode::query()->findOrFail($parentId)->parent_id;
        }
        $result = $this->domain(fn (): array => $this->hierarchy->saveNode([
            'id' => $record?->getKey(), 'name' => $data['name'], 'type' => $data['type'],
            'parentId' => $data['parent_id'] ?? null, 'displayOrder' => $data['display_order'],
        ]), ['parentId' => 'parent_id']);
        if ($data['type'] === 'series' && array_key_exists('typst_templating_enabled', $data)) {
            $this->hierarchy->setTypstTemplatingEnabled((int) $result['id'], (bool) $data['typst_templating_enabled']);
        }

        return CatalogNode::query()->findOrFail($result['id']);
    }

    public function saveProduct(User $actor, array $data, ?CatalogProduct $record = null): CatalogProduct
    {
        $this->authorize($actor, $record === null ? 'create' : 'update', $record ?? CatalogProduct::class);
        $data['sku'] = is_string($data['sku'] ?? null) ? trim($data['sku']) : ($data['sku'] ?? null);
        $rules = [
            'series_id' => ['required', 'integer', Rule::exists(CatalogNode::class, 'id')->where('type', 'series')],
            'sku' => ['required', 'string', 'max:128', Rule::unique(CatalogProduct::class, 'sku')
                ->where('series_id', $data['series_id'] ?? null)->ignore($record?->getKey())],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:16000'],
            'attribute_values' => ['sometimes', 'array'],
        ];
        $data = $this->validate($data, $rules);
        if ($record !== null && (int) $record->series_id !== (int) $data['series_id']) {
            $this->invalid('series_id', 'An existing product must remain in its series.');
        }
        $values = $this->adaptValues(
            $this->getProductFields((int) $data['series_id']),
            $data['attribute_values'] ?? [], 'attribute_values',
        );
        $result = $this->domain(fn (): array => $this->products->saveProduct([
            'id' => $record?->getKey(), 'series_id' => $data['series_id'], 'sku' => $data['sku'],
            'name' => $data['name'], 'description' => $data['description'] ?? null, 'customValues' => $values,
        ]), ['custom_field_values' => 'name']);

        return CatalogProduct::query()->findOrFail($result['id']);
    }

    public function saveField(User $actor, array $data, ?SeriesField $record = null): SeriesField
    {
        $this->authorize($actor, $record === null ? 'create' : 'update', $record ?? SeriesField::class);
        $data = $this->validate($data, [
            'series_id' => ['required', 'integer', Rule::exists(CatalogNode::class, 'id')->where('type', 'series')],
            'field_key' => ['required', 'string', 'max:64'],
            'label' => ['required', 'string', 'max:255'],
            'field_type' => ['required', Rule::in(['text', 'number', 'file'])],
            'field_scope' => ['required', Rule::in([SeriesFieldService::SCOPE_PRODUCT, SeriesFieldService::SCOPE_SERIES])],
            'default_value' => ['nullable', 'string', 'max:16000'],
            'sort_order' => ['required', 'integer', 'between:-2147483648,2147483647'],
            'is_required' => ['required', 'boolean'],
            'is_public_portal_hidden' => ['required', 'boolean'],
            'is_backend_portal_hidden' => ['required', 'boolean'],
        ]);
        if ($record !== null && (int) $record->series_id !== (int) $data['series_id']) {
            $this->invalid('series_id', 'Field series cannot be changed after creation.');
        }
        if ($record !== null && $record->field_scope !== $data['field_scope']) {
            $this->invalid('field_scope', 'Field scope cannot be changed after creation.');
        }
        $result = $this->domain(fn (): array => $this->fields->saveField([
            'id' => $record?->getKey(), 'seriesId' => $data['series_id'], 'fieldKey' => $data['field_key'],
            'label' => $data['label'], 'fieldType' => $data['field_type'], 'fieldScope' => $data['field_scope'],
            'defaultValue' => $data['default_value'] ?? null, 'sortOrder' => $data['sort_order'],
            'isRequired' => $data['is_required'], 'publicPortalHidden' => $data['is_public_portal_hidden'],
            'backendPortalHidden' => $data['is_backend_portal_hidden'],
        ]), ['fieldKey' => 'field_key', 'fieldScope' => 'field_scope', 'seriesId' => 'series_id']);

        return SeriesField::query()->findOrFail($result['id']);
    }

    public function saveTypstTemplate(User $actor, array $data, ?TypstTemplate $record = null): TypstTemplate
    {
        $this->authorize($actor, $record === null ? 'create' : 'update', $record ?? TypstTemplate::class);
        $data = $this->validate($data, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:16000'],
            'typst_content' => ['required', 'string', 'max:1000000'],
            'is_global' => ['required', 'boolean'],
            'series_id' => ['nullable', 'integer', Rule::exists(CatalogNode::class, 'id')->where('type', 'series')],
        ]);
        $global = (bool) $data['is_global'];
        $seriesId = $global ? null : ($data['series_id'] ?? null);
        if (! $global && $seriesId === null) {
            $this->invalid('series_id', 'Select a series for this template.');
        }
        if ($record !== null && ($global !== $record->is_global || $seriesId != $record->series_id)) {
            $this->invalid('series_id', 'Template scope cannot be changed after creation.');
        }
        $description = $data['description'] ?? '';
        $result = $this->domain(function () use ($record, $global, $seriesId, $data, $description): array {
            if ($record === null) {
                return $global
                    ? $this->typst->createGlobalTemplate($data['title'], $description, $data['typst_content'])
                    : $this->typst->createSeriesTemplate((int) $seriesId, $data['title'], $description, $data['typst_content']);
            }

            return ($global
                ? $this->typst->updateGlobalTemplate((int) $record->getKey(), $data['title'], $description, $data['typst_content'])
                : $this->typst->updateSeriesTemplate((int) $record->getKey(), (int) $seriesId, $data['title'], $description, $data['typst_content'])) ?? [];
        });

        return TypstTemplate::query()->findOrFail($result['id']);
    }

    public function saveLatexTemplate(User $actor, array $data, ?LatexTemplate $record = null): LatexTemplate
    {
        $this->authorize($actor, $record === null ? 'create' : 'update', $record ?? LatexTemplate::class);
        $data = $this->validate($data, [
            'title' => ['required', 'string', 'max:255'],
            // The legacy service requires a nonempty description; its API defect remains scoped there.
            'description' => ['required', 'string', 'max:16000'],
            'latex_source' => ['required', 'string', 'max:1000000'],
        ]);
        $payload = ['title' => $data['title'], 'description' => $data['description'], 'latex' => $data['latex_source']];
        $result = $this->domain(fn (): array => $record === null
            ? $this->latex->createTemplate($payload)
            : $this->latex->updateTemplate((int) $record->getKey(), $payload), ['latex' => 'latex_source']);

        return LatexTemplate::query()->findOrFail($result['id']);
    }

    public function delete(User $actor, CatalogModel $record): void
    {
        $this->authorize($actor, 'delete', $record);
        if ($record instanceof CatalogNode && $record->type === 'series') {
            foreach (['typst_templates', 'typst_variables', 'typst_series_preferences', 'latex_templates', 'latex_variables'] as $table) {
                if (DB::connection($record->getConnectionName())->table($table)->where('series_id', $record->getKey())->exists()) {
                    $this->invalid('name', 'Remove the series templates, variables and preferences before deleting this series.');
                }
            }
        }
        if ($record instanceof SeriesField && $record->field_type === 'file') {
            foreach (['product_custom_field_value', 'series_custom_field_value'] as $table) {
                if (DB::connection($record->getConnectionName())->table($table)
                    ->where('series_custom_field_id', $record->getKey())->whereNotNull('value')->exists()) {
                    $this->invalid('field_key', 'Clear uploaded field values through the Catalog interface before deleting this field.');
                }
            }
        }
        $this->domain(function () use ($record): void {
            match (true) {
                $record instanceof CatalogNode => $this->hierarchy->deleteNode((int) $record->getKey()),
                $record instanceof CatalogProduct => $this->products->deleteProduct((int) $record->getKey()),
                $record instanceof SeriesField => $this->fields->deleteField((int) $record->getKey()),
                $record instanceof TypstTemplate => $this->typst->deleteTemplate((int) $record->getKey()),
                $record instanceof LatexTemplate => $this->latex->deleteTemplate((int) $record->getKey()),
                default => abort(404),
            };
        });
    }

    public function getProductFields(int $seriesId): array
    {
        return $this->fields->listFields($seriesId, SeriesFieldService::SCOPE_PRODUCT);
    }

    public function getMetadataFields(int $seriesId): array
    {
        return $this->fields->listFields($seriesId, SeriesFieldService::SCOPE_SERIES);
    }

    public function productValues(CatalogProduct $record): array
    {
        $payload = $this->products->listProducts((int) $record->series_id);
        foreach ($payload['products'] as $product) {
            if ((int) $product['id'] === (int) $record->getKey()) {
                return $this->valuesById($payload['fields'], $product['customValues']);
            }
        }

        return [];
    }

    public function seriesValues(CatalogNode $record): array
    {
        $payload = $this->metadata->getAttributes((int) $record->getKey());

        return $this->valuesById($payload['definitions'], $payload['values']);
    }

    public function saveMetadata(User $actor, CatalogNode $record, array $values): void
    {
        $this->authorize($actor, 'update', $record);
        abort_unless($record->type === 'series', 404);
        $definitions = $this->getMetadataFields((int) $record->getKey());
        $adapted = $this->adaptValues($definitions, $values, 'metadata_values');
        $fieldMap = [];
        foreach ($definitions as $field) {
            $fieldMap[$field['fieldKey']] = 'metadata_values.'.$field['id'];
        }
        $this->domain(fn (): array => $this->metadata->saveAttributes([
            'seriesId' => (int) $record->getKey(), 'values' => $adapted,
        ]), $fieldMap);
    }

    private function valuesById(array $definitions, array $values): array
    {
        $result = [];
        foreach ($definitions as $field) {
            $value = $values[$field['fieldKey']] ?? $field['defaultValue'] ?? null;
            $result[$field['id']] = is_array($value) ? ($value['filename'] ?? $value['relativePath'] ?? $value['path'] ?? '') : $value;
        }

        return $result;
    }

    private function adaptValues(array $definitions, array $values, string $prefix): array
    {
        $result = [];
        foreach ($definitions as $field) {
            // Existing file paths are read only in admin; the original upload workflow owns files.
            if ($field['fieldType'] === 'file') {
                continue;
            }
            if (! array_key_exists($field['id'], $values)) {
                continue;
            }
            $rules = [($field['isRequired'] ?? false) ? 'required' : 'nullable'];
            $rules[] = $field['fieldType'] === 'number' ? 'numeric' : 'string';
            $rules[] = $field['fieldType'] === 'number' ? 'between:-1.0e308,1.0e308' : 'max:16000';
            $this->validate([$prefix => [$field['id'] => $values[$field['id']]]], [$prefix.'.'.$field['id'] => $rules]);
            $result[$field['fieldKey']] = $values[$field['id']];
        }

        return $result;
    }

    private function authorize(User $actor, string $ability, CatalogModel|string $subject): void
    {
        // This check precedes Gate's protected Super Admin bypass.
        abort_unless(app('modules')->isEnabled('Catalog'), 404);
        Gate::forUser($actor)->authorize($ability, $subject);
    }

    private function validate(array $data, array $rules): array
    {
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            $messages = [];
            foreach ($validator->errors()->messages() as $key => $errors) {
                $messages['data.'.$key] = $errors;
            }
            throw ValidationException::withMessages($messages);
        }

        return $validator->validated();
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages(['data.'.$field => $message]);
    }

    private function domain(Closure $operation, array $fieldMap = []): mixed
    {
        try {
            return $operation();
        } catch (CatalogApiException $exception) {
            if ($exception->getStatusCode() >= 500) {
                throw $exception;
            }
            $messages = [];
            foreach ($exception->getDetails() as $field => $message) {
                $messages['data.'.($fieldMap[$field] ?? $field)] = is_string($message) ? $message : $exception->getMessage();
            }
            throw ValidationException::withMessages($messages ?: ['data.name' => $exception->getMessage()]);
        }
    }
}
