<?php

declare(strict_types=1);

namespace Modules\Catalog\Filament\Resources;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Modules\Catalog\Filament\Concerns\HasCatalogDynamicFields;
use Modules\Catalog\Models\CatalogModel;
use Modules\Catalog\Models\CatalogNode;
use Modules\Catalog\Services\CatalogAdminService;

abstract class CatalogResource extends Resource
{
    use HasCatalogDynamicFields;

    public static function canViewAny(): bool
    {
        return static::catalogModuleEnabled() && parent::canViewAny();
    }

    public static function canAccess(): bool
    {
        return static::catalogModuleEnabled() && static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::catalogModuleEnabled() && parent::canCreate();
    }

    public static function canView(Model $record): bool
    {
        return static::catalogModuleEnabled() && parent::canView($record);
    }

    public static function canEdit(Model $record): bool
    {
        return static::catalogModuleEnabled() && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::catalogModuleEnabled() && parent::canDelete($record);
    }

    public static function getNavigationItems(): array
    {
        return static::canViewAny() ? parent::getNavigationItems() : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    abstract public static function saveRecord(User $actor, array $data, ?CatalogModel $record = null): CatalogModel;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateRecordFormData(array $data, CatalogModel $record): array
    {
        return $data;
    }

    public static function authenticatedUser(): User
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            throw new AuthorizationException;
        }

        return $actor;
    }

    protected static function catalogModuleEnabled(): bool
    {
        return app('modules')->isEnabled('Catalog');
    }

    /** @return array<int, string> */
    protected static function seriesOptions(): array
    {
        return CatalogNode::query()
            ->where('type', 'series')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function categoryOptions(?CatalogNode $record = null): array
    {
        $categories = CatalogNode::query()
            ->where('type', 'category')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name']);

        $excludedIds = [];
        if ($record instanceof CatalogNode && $record->type === 'category') {
            $childrenByParent = [];
            foreach ($categories as $category) {
                $parentId = $category->parent_id !== null ? (int) $category->parent_id : null;
                if ($parentId !== null) {
                    $childrenByParent[$parentId][] = (int) $category->getKey();
                }
            }

            $pendingIds = [(int) $record->getKey()];
            while ($pendingIds !== []) {
                $excludedId = array_pop($pendingIds);
                if (isset($excludedIds[$excludedId])) {
                    continue;
                }

                $excludedIds[$excludedId] = true;
                $childIds = $childrenByParent[$excludedId] ?? [];
                if ($childIds !== []) {
                    array_push($pendingIds, ...$childIds);
                }
            }
        }

        $options = [];
        foreach ($categories as $category) {
            $categoryId = (int) $category->getKey();
            if (! isset($excludedIds[$categoryId])) {
                $options[$categoryId] = (string) $category->name;
            }
        }

        return $options;
    }

    protected static function editRecordAction(): Action
    {
        $resource = static::class;

        return Action::make('edit')
            ->authorize(fn (CatalogModel $record): bool => Auth::user()?->can('update', $record) ?? false)
            ->url(fn (CatalogModel $record): string => $resource::getUrl('edit', ['record' => $record]))
            ->icon(Heroicon::PencilSquare);
    }

    protected static function viewRecordAction(): Action
    {
        $resource = static::class;

        return Action::make('view')
            ->authorize(fn (CatalogModel $record): bool => Auth::user()?->can('view', $record) ?? false)
            ->url(fn (CatalogModel $record): string => $resource::getUrl('view', ['record' => $record]))
            ->icon(Heroicon::Eye);
    }

    protected static function deleteRecordAction(): Action
    {
        return Action::make('delete')
            ->authorize(fn (CatalogModel $record): bool => Auth::user()?->can('delete', $record) ?? false)
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (CatalogModel $record): void {
                app(CatalogAdminService::class)->delete(static::authenticatedUser(), $record);
            });
    }
}
