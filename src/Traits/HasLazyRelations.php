<?php

declare(strict_types=1);

namespace Michalsn\CodeIgniterRelations\Traits;

use Closure;
use CodeIgniter\Model;

/**
 * Enables lazy loading of relations in entities.
 * When accessing a property that matches a relation method on the
 * corresponding model, the relation will be loaded on-demand.
 *
 * This trait includes HasEntityWrites, which provides entity save/delete
 * methods and the ability to call relation methods for write operations.
 *
 * Example:
 *   $user = model(UserModel::class)->find(1);
 *   $profile = $user->profile; // Lazy loads profile relation
 */
trait HasLazyRelations
{
    use HasModelDiscovery;
    use HasEntityWrites;

    /**
     * Track loaded relations with metadata
     *
     * Stores information about which relations have been loaded and how,
     * enabling refresh functionality and debugging.
     *
     * @var array<string, array{callback: Closure|null, type: string}>
     */
    private array $loadedRelations = [];

    /**
     * Override property access to enable lazy loading
     *
     * @param string $key The property name
     *
     * @return mixed The property value or loaded relation
     */
    public function __get(string $key)
    {
        if (array_key_exists($key, $this->attributes)) {
            return parent::__get($key);
        }

        $model = $this->getRelationModel();

        if ($model !== null && method_exists($model, $key)) {
            return isset($this->loadedRelations[$key])
                ? null
                : $this->handleRelation($key, $model);
        }

        return parent::__get($key);
    }

    /**
     * Load relation for the property
     *
     * Attempts to find the corresponding model and load the relation.
     * Stores metadata about the loaded relation for refresh functionality.
     *
     * @param string $name The relation name
     *
     * @return mixed The loaded relation data or null
     */
    private function handleRelation(string $name, Model $model): mixed
    {
        $relation = $model->{$name}();

        // Use the relation's own lazyLoad method which knows how to query correctly
        $result = $relation->lazyLoad($this);

        $this->attributes[$name] = $result;

        // Track this relation metadata
        $this->loadedRelations[$name] = [
            'callback' => null,
            'type'     => 'lazy',
        ];

        return $this->attributes[$name];
    }

    /**
     * Resolve the matching model without storing live model instances on the entity.
     */
    private function getRelationModel(): ?Model
    {
        $className = $this->findModelClass();

        return $className === null ? null : model($className);
    }

    /**
     * Mark a relation as loaded with metadata
     *
     * Called by eager loading to track which relations were loaded
     * and with what query constraints.
     *
     * @internal
     *
     * @param string       $name     The relation name
     * @param Closure|null $callback The query callback used
     * @param string       $type     The load type ('eager' or 'lazy')
     */
    public function setLoadedRelation(string $name, ?Closure $callback, string $type = 'eager'): void
    {
        $this->loadedRelations[$name] = [
            'callback' => $callback,
            'type'     => $type,
        ];
    }

    /**
     * Get metadata about loaded relations
     *
     * @return array<string, array{callback: Closure|null, type: string}>
     */
    public function getLoadedRelations(): array
    {
        return $this->loadedRelations;
    }

    /**
     * Check changed state while treating loaded relations separately from attributes.
     *
     * Child entity changes should be tracked on the child entity itself, not as
     * a changed relation attribute on the parent.
     */
    public function hasChanged(?string $key = null): bool
    {
        if ($key !== null) {
            return isset($this->loadedRelations[$key])
                ? false
                : parent::hasChanged($key);
        }

        $keys = array_unique([
            ...array_keys($this->attributes),
            ...array_keys($this->original),
        ]);

        foreach ($keys as $attributeKey) {
            if (isset($this->loadedRelations[$attributeKey])) {
                continue;
            }

            if (parent::hasChanged($attributeKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Refresh the entity from the database
     *
     * Reloads the entity's attributes and ALL loaded relations from the database.
     *
     * - Reloads entity attributes from database
     * - Reloads ALL eager-loaded relations
     * - Preserves query callbacks for eager-loaded relations
     * - Handles nested relations automatically (e.g., 'posts.comments')
     *
     * Example:
     *   $user = model(UserModel::class)->with('posts', 'posts.comments')->find(1);
     *   $user->refresh(); // Reloads user + posts + posts.comments
     */
    public function refresh(): self
    {
        // Get model class
        $className = $this->findModelClass();

        if ($className === null) {
            return $this;
        }

        $model = model($className);

        // Get primary key
        $primaryKey      = get_model_property($model, 'primaryKey');
        $primaryKeyValue = $this->{$primaryKey} ?? null;

        if (in_array($primaryKeyValue, [null, 0, '0', ''], true)) {
            return $this;
        }

        // Build with() array for ALL eager-loaded relations (including nested)
        $withRelations = $this->gatherEagerRelations();

        // Apply with() if we have eager relations
        if ($withRelations !== []) {
            $model->with($withRelations);
        }

        // Reload the entity from database
        $refreshed = $model->find($primaryKeyValue);

        if ($refreshed === null) {
            return $this;
        }

        // Copy entity attributes
        $this->attributes = $refreshed->attributes;

        // Copy loaded relations metadata from refreshed entity
        $this->loadedRelations = $refreshed->loadedRelations;

        // Sync original state to prevent marking as changed
        $this->syncOriginal();

        return $this;
    }

    /**
     * Load or reload specific relations
     *
     * Loads the specified relations from the database without touching the entity's
     * attributes. Useful for selectively reloading relations after data changes.
     *
     * Unlike refresh() which reloads everything, load() only loads the specified
     * relations, leaving the entity attributes untouched.
     *
     * Supports multiple syntax styles:
     * - load('posts')
     * - load('posts', fn($q) => $q->where('status', 'published'))
     * - load(['posts', 'comments'])
     * - load(['posts' => fn($q) => $q->where('status', 'published')])
     * - load(['posts', 'posts.comments'])
     *
     * Example:
     *   $user = model(UserModel::class)->find(1);
     *   $user->load('posts'); // Load posts without touching user attributes
     *
     * @param array|string $relations Relation name(s) or array with callbacks
     * @param Closure|null $callback  Optional query callback when loading a string relation
     */
    public function load(array|string $relations, ?Closure $callback = null): self
    {
        // Get model class
        $className = $this->findModelClass();

        if ($className === null) {
            return $this;
        }

        $model = model($className);

        $model->loadRelationsOn($this, $relations, $callback);

        return $this;
    }

    /**
     * Recursively gather all eager-loaded relations including nested ones
     *
     * Walks through loaded relations and their nested relations to build
     * a complete with() array for refresh.
     *
     * Example:
     * - User has 'posts' loaded
     * - Each Post has 'comments' loaded
     * - Returns: ['posts', 'posts.comments']
     *
     * @internal
     *
     * @param string $prefix Prefix for nested relations (used in recursion)
     */
    public function gatherEagerRelations(string $prefix = ''): array
    {
        $withRelations = [];

        foreach ($this->loadedRelations as $relationName => $metadata) {
            // Only include eager-loaded relations
            if ($metadata['type'] !== 'eager') {
                continue;
            }

            // Build the full relation name (e.g., 'posts' or 'posts.comments')
            $fullName = $prefix === '' ? $relationName : "{$prefix}.{$relationName}";

            // Add this relation with its callback
            if ($metadata['callback'] !== null) {
                $withRelations[$fullName] = $metadata['callback'];
            } else {
                $withRelations[] = $fullName;
            }

            // Check if this relation has nested relations
            if (isset($this->attributes[$relationName])) {
                $relationData = $this->attributes[$relationName];

                // Handle single entity (hasOne, belongsTo)
                if (is_object($relationData) && method_exists($relationData, 'getLoadedRelations')) {
                    $nestedWithRelations = $relationData->gatherEagerRelations($fullName);
                    $withRelations       = array_merge($withRelations, $nestedWithRelations);
                }
                // Handle array of entities (hasMany, belongsToMany)
                elseif (is_array($relationData) && $relationData !== []) {
                    $first = $relationData[0] ?? null;

                    if (is_object($first) && method_exists($first, 'getLoadedRelations')) {
                        // Use the first entity to gather nested relations
                        // (assumes all entities in the array have the same relations loaded)
                        $nestedWithRelations = $first->gatherEagerRelations($fullName);
                        $withRelations       = array_merge($withRelations, $nestedWithRelations);
                    }
                }
            }
        }

        return $withRelations;
    }
}
