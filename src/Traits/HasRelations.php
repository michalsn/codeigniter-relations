<?php

declare(strict_types=1);

namespace Michalsn\CodeIgniterRelations\Traits;

use Closure;
use Michalsn\CodeIgniterRelations\Exceptions\RelationException;
use Michalsn\CodeIgniterRelations\Relations\BelongsTo;
use Michalsn\CodeIgniterRelations\Relations\BelongsToMany;
use Michalsn\CodeIgniterRelations\Relations\HasMany;
use Michalsn\CodeIgniterRelations\Relations\HasManyThrough;
use Michalsn\CodeIgniterRelations\Relations\HasOne;
use Michalsn\CodeIgniterRelations\Relations\HasOneThrough;
use Michalsn\CodeIgniterRelations\Relations\MorphMany;
use Michalsn\CodeIgniterRelations\Relations\MorphOne;
use Michalsn\CodeIgniterRelations\Relations\MorphTo;

/**
 * Enables eager loading of relations in models.
 *
 * Models using this trait can define relations and load them efficiently
 * using the with() method.
 */
trait HasRelations
{
    /**
     * Relations to be eager loaded
     * Format: ['relationName' => ?Closure, 'nested.relation' => ?Closure]
     */
    protected array $with = [];

    protected function initialize()
    {
        $this->initRelations();
    }

    /**
     * Initialize the relations system
     *
     * Registers the afterFind hook to automatically load eager relations.
     * Should be called in the model's initialize() method.
     */
    protected function initRelations(): void
    {
        $this->afterFind[] = 'loadEagerRelations';

        $this->updateOnlyChanged = false;
    }

    /**
     * Queue relations for eager loading
     *
     * Supports multiple syntax styles:
     * - with('posts')
     * - with('posts', fn($m) => $m->where('status', 'published'))
     * - with(['posts', 'profile'])
     * - with(['posts' => fn($m) => $m->where('status', 'published')])
     * - with(['posts', 'posts.comments' => fn($m) => ...])
     *
     * @param array|string $relations Relation name(s) or array with callbacks
     * @param Closure|null $callback  Optional query callback
     */
    public function with($relations, ?Closure $callback = null): self
    {
        // Handle array syntax: ['posts.comments' => callback, 'profile']
        if (is_array($relations)) {
            foreach ($relations as $key => $value) {
                if ($value instanceof Closure) {
                    // ['posts' => callback]
                    $this->with[$key] = $value;
                } elseif (is_string($value)) {
                    // ['posts']
                    $this->with[$value] = null;
                } elseif (is_string($key)) {
                    // [0 => 'posts'] (numeric key with string value)
                    $this->with[$key] = null;
                }
            }
        }
        // Handle string with callback: 'posts.comments', callback
        elseif (is_string($relations)) {
            $this->with[$relations] = $callback;
        }

        return $this;
    }

    /**
     * Load relations on existing entities
     *
     * Helper method to load relations on already-loaded entities without re-querying
     * the entities themselves. Used internally by the load() method on entities.
     *
     * @internal
     *
     * @param mixed        $entities  Single entity or array of entities
     * @param array|string $relations Relation name(s) or array with callbacks
     * @param Closure|null $callback  Optional query callback when loading a string relation
     *
     * @return mixed The entities with relations loaded
     */
    public function loadRelationsOn(mixed $entities, array|string $relations, ?Closure $callback = null): mixed
    {
        if (is_array($relations) && $callback !== null) {
            throw RelationException::forCallbackWithArrayRelations();
        }

        // Normalize relations to array
        if (is_string($relations)) {
            $relations = $callback === null ? [$relations] : [$relations => $callback];
        }

        // Build with array from relations
        $withArray = [];

        foreach ($relations as $key => $value) {
            if ($value instanceof Closure) {
                // ['posts' => callback]
                $withArray[$key] = $value;
            } elseif (is_string($value)) {
                // ['posts']
                $withArray[$value] = null;
            } elseif (is_string($key)) {
                // [0 => 'posts']
                $withArray[$key] = null;
            }
        }

        // Parse into nested structure
        $parsed = $this->parseWithRelations($withArray);

        // Load relations on the entities
        return $this->eagerLoadRelations($entities, $parsed, $this->tempReturnType);
    }

    /**
     * Extract nested relations for a given parent relation
     *
     * For example, if with(['posts', 'posts.comments', 'posts.tags']),
     * and relationName is 'posts', this returns ['comments' => null, 'tags' => null]
     *
     * @param string $relationName The parent relation name
     *
     * @return array Nested relations with their callbacks
     */
    protected function extractNestedRelations(string $relationName): array
    {
        $nested = [];
        $prefix = $relationName . '.';

        foreach ($this->with as $key => $callback) {
            if (str_starts_with((string) $key, $prefix)) {
                // Strip prefix: 'posts.comments' → 'comments'
                $nestedKey          = substr((string) $key, strlen($prefix));
                $nested[$nestedKey] = $callback;
            }
        }

        return $nested;
    }

    /**
     * Reset relation state after loading
     */
    protected function resetRelationState(): void
    {
        $this->with = [];
    }

    /**
     * AfterFind event handler - loads eager relations
     *
     * This method is automatically called after find operations
     * when relations have been queued with with().
     *
     * @param array $eventData The event data from CodeIgniter's model events
     *
     * @return array Modified event data with relations loaded
     */
    protected function loadEagerRelations(array $eventData): array
    {
        if ($this->with === [] || $eventData['data'] === [] || $eventData['data'] === null) {
            return $eventData;
        }

        $returnType = $this->tempReturnType;

        // Parse nested relations
        $relations = $this->parseWithRelations($this->with);

        // Reset for next query
        $this->with = [];

        // Load relations
        $eventData['data'] = $this->eagerLoadRelations(
            $eventData['data'],
            $relations,
            $returnType,
        );

        // Sync original state to prevent eager-loaded relations from being marked as "changed"
        $this->syncRelationOriginals($eventData, $relations);

        return $eventData;
    }

    /**
     * Parse the with array into nested structure
     *
     * Converts dot notation into a nested array structure for efficient loading.
     *
     * Input:  ['posts' => null, 'posts.comments' => callback, 'profile' => null]
     * Output: [
     *   'posts' => [
     *     'callback' => null,
     *     'nested' => ['comments' => ['callback' => callback, 'nested' => []]]
     *   ],
     *   'profile' => ['callback' => null, 'nested' => []]
     * ]
     *
     * @param array $with The with array to parse
     *
     * @return array Parsed nested structure
     */
    protected function parseWithRelations(array $with): array
    {
        $parsed = [];

        foreach ($with as $name => $callback) {
            $parts   = explode('.', (string) $name);
            $current = &$parsed;

            foreach ($parts as $i => $part) {
                if (! isset($current[$part])) {
                    $current[$part] = ['callback' => null, 'nested' => []];
                }

                // If this is the last part, set the callback
                if ($i === count($parts) - 1) {
                    $current[$part]['callback'] = $callback;
                } else {
                    $current = &$current[$part]['nested'];
                }
            }
        }

        return $parsed;
    }

    /**
     * Actually load the eager relations
     *
     * Recursively loads all relations and their nested relations.
     *
     * @internal
     *
     * @param mixed  $results    The parent model results
     * @param array  $relations  Parsed relation configuration
     * @param string $returnType The desired return type
     *
     * @return mixed Results with relations attached
     */
    public function eagerLoadRelations($results, array $relations, string $returnType)
    {
        // Handle null and empty array result early
        if ($results === null || $results === []) {
            return $results;
        }

        // Handle single result
        $single  = ! is_array($results) || ! isset($results[0]);
        $results = $single ? [$results] : $results;

        foreach ($relations as $name => $config) {
            if (! method_exists($this, $name)) {
                throw RelationException::forRelationMethodNotFound($name, static::class);
            }

            // Get relation instance
            $relation = $this->{$name}();
            $relation->setRelationName($name);

            // Apply callback if provided
            if ($config['callback'] !== null) {
                $relation->setQueryCallback($config['callback']);
            }

            // Set nested relations
            if ($config['nested'] !== []) {
                $relation->setNestedRelations($config['nested']);
            }

            // Eager load
            $results = $relation->eagerLoad($results, $returnType, $name);

            foreach ($results as $result) {
                if (is_object($result) && method_exists($result, 'setLoadedRelation')) {
                    $result->setLoadedRelation($name, $config['callback'], 'eager');
                }
            }
        }

        return $single ? $results[0] : $results;
    }

    /**
     * Recursively sync original state for entities and all nested relations
     *
     * This prevents eager-loaded relations from being marked as "changed" in entities.
     * Uses depth-first traversal (syncs children before parents) and skips entities
     * that have no relations loaded for optimal performance.
     *
     * @param array|mixed $eventData Event data array with 'data' and 'singleton' keys, or raw results for recursive calls
     * @param array       $relations The parsed relations structure with nested config
     */
    protected function syncRelationOriginals($eventData, array $relations): void
    {
        // Optimization: Early return if no relations were loaded for this level
        // This skips leaf entities (deepest level with no further relations)
        if ($relations === []) {
            return;
        }

        // Determine if this is the initial call (with eventData) or a recursive call (with raw results)
        if (is_array($eventData) && isset($eventData['singleton'])) {
            // Initial call: extract data based on singleton flag
            $results = $eventData['singleton']
                ? [$eventData['data']]
                : $eventData['data'];
        } else {
            // Recursive call: handle raw results (single entity or array)
            $single  = ! is_array($eventData) || ! isset($eventData[0]);
            $results = $single ? [$eventData] : $eventData;
        }

        foreach ($results as $entity) {
            // Only sync entity objects that have syncOriginal() method
            if (! is_object($entity) || ! method_exists($entity, 'syncOriginal')) {
                continue;
            }

            // First, recursively sync nested relations (depth-first: children before parent)
            foreach ($relations as $relationName => $config) {
                if (isset($entity->{$relationName})) {
                    $relatedData = $entity->{$relationName};
                    // Recursively sync nested relations if they exist
                    if (! empty($config['nested'])) {
                        $this->syncRelationOriginals($relatedData, $config['nested']);
                    } else {
                        // No nested relations - will hit early return optimization
                        $this->syncRelationOriginals($relatedData, []);
                    }
                }
            }

            // Then sync this entity's original state (includes the relation properties)
            $entity->syncOriginal();
        }
    }

    // Relation definition helpers

    /**
     * Define a has-one relationship
     *
     * @param string      $related    The related model class
     * @param string|null $foreignKey Optional foreign key override
     * @param string|null $localKey   Optional local key override
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        return new HasOne($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define a has-many relationship
     *
     * @param string      $related    The related model class
     * @param string|null $foreignKey Optional foreign key override
     * @param string|null $localKey   Optional local key override
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        return new HasMany($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define a belongs-to relationship
     *
     * @param string      $related    The related model class
     * @param string|null $foreignKey Optional foreign key override
     * @param string|null $ownerKey   Optional owner key override
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        return new BelongsTo($this, $related, $foreignKey, $ownerKey);
    }

    /**
     * Define a belongs-to-many relationship
     *
     * @param string      $related         The related model class
     * @param string|null $pivotTable      Optional pivot table override
     * @param string|null $parentPivotKey  Optional parent pivot key override
     * @param string|null $relatedPivotKey Optional related pivot key override
     * @param string|null $parentKey       Optional parent key override
     * @param string|null $relatedKey      Optional related key override
     */
    protected function belongsToMany(
        string $related,
        ?string $pivotTable = null,
        ?string $parentPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
    ): BelongsToMany {
        return new BelongsToMany(
            $this,
            $related,
            $pivotTable,
            $parentPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
        );
    }

    /**
     * Define a has-one-through relationship
     *
     * @param string      $related        The final related model class
     * @param string      $through        The intermediate model class
     * @param string|null $firstKey       Foreign key on intermediate table
     * @param string|null $secondKey      Foreign key on final table
     * @param string|null $localKey       Local key on parent table
     * @param string|null $secondLocalKey Local key on intermediate table
     */
    protected function hasOneThrough(
        string $related,
        string $through,
        ?string $firstKey = null,
        ?string $secondKey = null,
        ?string $localKey = null,
        ?string $secondLocalKey = null,
    ): HasOneThrough {
        return new HasOneThrough($this, $related, $through, $firstKey, $secondKey, $localKey, $secondLocalKey);
    }

    /**
     * Define a has-many-through relationship
     *
     * @param string      $related        The final related model class
     * @param string      $through        The intermediate model class
     * @param string|null $firstKey       Foreign key on intermediate table
     * @param string|null $secondKey      Foreign key on final table
     * @param string|null $localKey       Local key on parent table
     * @param string|null $secondLocalKey Local key on intermediate table
     */
    protected function hasManyThrough(
        string $related,
        string $through,
        ?string $firstKey = null,
        ?string $secondKey = null,
        ?string $localKey = null,
        ?string $secondLocalKey = null,
    ): HasManyThrough {
        return new HasManyThrough($this, $related, $through, $firstKey, $secondKey, $localKey, $secondLocalKey);
    }

    /**
     * Define a morph-one relationship
     *
     * @param string      $related  The related model class
     * @param string      $name     The morph name (e.g., 'imageable')
     * @param string|null $type     Optional type field override
     * @param string|null $id       Optional id field override
     * @param string|null $localKey Optional local key override
     */
    protected function morphOne(
        string $related,
        string $name,
        ?string $type = null,
        ?string $id = null,
        ?string $localKey = null,
    ): MorphOne {
        return new MorphOne($this, $related, $name, $type, $id, $localKey);
    }

    /**
     * Define a morph-many relationship
     *
     * @param string      $related  The related model class
     * @param string      $name     The morph name (e.g., 'commentable')
     * @param string|null $type     Optional type field override
     * @param string|null $id       Optional id field override
     * @param string|null $localKey Optional local key override
     */
    protected function morphMany(
        string $related,
        string $name,
        ?string $type = null,
        ?string $id = null,
        ?string $localKey = null,
    ): MorphMany {
        return new MorphMany($this, $related, $name, $type, $id, $localKey);
    }

    /**
     * Define a morph-to relationship (inverse of morph)
     *
     * Unlike other relations, morphTo doesn't take a related model class
     * since it can morph to multiple different types.
     *
     * @param string      $name The morph name (e.g., 'commentable')
     * @param string|null $type Optional type field override
     * @param string|null $id   Optional id field override
     */
    protected function morphTo(
        string $name,
        ?string $type = null,
        ?string $id = null,
    ): MorphTo {
        return new MorphTo($this, $name, $type, $id);
    }
}
