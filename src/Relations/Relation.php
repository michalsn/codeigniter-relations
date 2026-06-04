<?php

declare(strict_types=1);

namespace Michalsn\CodeIgniterRelations\Relations;

use Closure;
use CodeIgniter\Entity\Entity;
use CodeIgniter\Model;
use Michalsn\CodeIgniterRelations\Enums\RelationTypes;
use Michalsn\CodeIgniterRelations\Exceptions\RelationException;
use Michalsn\CodeIgniterRelations\Exceptions\RelationWriteException;

/**
 * Base Relation Class
 *
 * Abstract base class for all relation types. Provides common functionality
 * for eager loading, lazy loading, and managing relation queries.
 */
abstract class Relation
{
    /**
     * The type of this relation
     */
    public RelationTypes $type;

    /**
     * The related model instance
     */
    protected Model $model;

    /**
     * Optional query callback for custom constraints
     */
    protected ?Closure $queryCallback = null;

    /**
     * Nested relations to load on the related model
     */
    protected array $nestedRelations = [];

    /**
     * Parent ID set when calling from entity context
     * Used for fluent writes: $entity->relation()->insert()
     */
    protected int|string|null $contextParentId = null;

    /**
     * Parent entity reference when calling from entity context
     * Used to update parent entity properties in memory (e.g., foreign keys)
     */
    protected ?Entity $parentEntity = null;

    /**
     * The name of this relation as defined in the model
     * Set when accessed from entity context (e.g., 'country' for $user->country())
     */
    protected ?string $relationName = null;

    /**
     * Setter bound to Entity scope for attaching eager-loaded relations.
     */
    private static ?Closure $entityRelationSetter = null;

    /**
     * Constructor
     *
     * @param Model       $parentModel       The parent model instance
     * @param string      $relatedModelClass The related model class name
     * @param string|null $foreignKey        Optional foreign key override
     * @param string|null $primaryKey        Optional primary key override
     */
    public function __construct(
        protected Model $parentModel,
        string $relatedModelClass,
        protected ?string $foreignKey = null,
        protected ?string $primaryKey = null,
    ) {
        $this->model      = model($relatedModelClass);
        $this->primaryKey = $primaryKey ?? get_model_property($this->parentModel, 'primaryKey');
        $this->foreignKey = $foreignKey ?? get_foreign_key($this->parentModel);
    }

    /**
     * Apply relation query logic
     *
     * Adds a whereIn clause to the related model's query to load
     * only records that belong to the given parent IDs.
     *
     * @internal
     *
     * @param array  $parentIds  Parent model IDs to load relations for
     * @param string $foreignKey The foreign key to use in whereIn
     *
     * @return $this
     */
    public function applyRelation(array $parentIds, string $foreignKey): self
    {
        $this->model->whereIn($foreignKey, $parentIds);

        // Apply custom query callback if provided
        if ($this->queryCallback !== null) {
            ($this->queryCallback)($this->model);
        }

        return $this;
    }

    /**
     * Set a query callback for this relation
     *
     * Allows custom query constraints to be applied when loading the relation.
     * The callback receives the related model instance as a parameter.
     *
     * @internal
     *
     * @param Closure|null $callback The query callback
     *
     * @return $this
     */
    public function setQueryCallback(?Closure $callback): self
    {
        $this->queryCallback = $callback;

        return $this;
    }

    /**
     * Load nested relations on this relation
     *
     * @internal
     *
     * @param array $relations Array of nested relation configurations
     *
     * @return $this
     */
    public function setNestedRelations(array $relations): self
    {
        $this->nestedRelations = $relations;

        return $this;
    }

    /**
     * Get the relation type
     *
     * @return RelationTypes The relation type
     */
    public function getType(): RelationTypes
    {
        return $this->type;
    }

    /**
     * Get the related model instance
     *
     * @return Model The related model
     */
    public function getRelatedModel(): Model
    {
        return $this->model;
    }

    /**
     * Set the parent ID for entity-context writes
     *
     * When calling relation from an entity ($entity->relation()),
     * this stores the parent ID so write methods can auto-set foreign keys.
     *
     * @internal
     *
     * @param int|string $parentId The parent entity's ID
     *
     * @return $this
     */
    public function setParentId(int|string $parentId): self
    {
        $this->contextParentId = $parentId;

        return $this;
    }

    /**
     * Set the parent entity reference for entity-context writes
     *
     * When calling relation from an entity ($entity->relation()),
     * this stores a reference to the entity so write methods can update
     * its properties in memory (e.g., foreign keys in BelongsTo).
     *
     * @internal
     *
     * @param Entity $entity The parent entity
     *
     * @return $this
     */
    public function setParentEntity(Entity $entity): self
    {
        $this->parentEntity = $entity;

        return $this;
    }

    /**
     * Set the relation name for entity-context operations
     *
     * When calling relation from an entity ($entity->relation()),
     * this stores the relation method name to enable updating loaded relations.
     *
     * @internal
     *
     * @param string $name The relation method name
     *
     * @return $this
     */
    public function setRelationName(string $name): self
    {
        $this->relationName = $name;

        return $this;
    }

    /**
     * Extract primary keys from results
     *
     * Extracts and returns unique primary key values from an array of results.
     * Works with both array and object results.
     *
     * @param array $results The parent results
     *
     * @return array Array of unique primary key values
     */
    protected function extractPrimaryKeys(array $results): array
    {
        $ids = [];

        foreach ($results as $result) {
            $id = $this->getPrimaryKeyValue($result);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_unique($ids);
    }

    /**
     * Get primary key value from result
     *
     * Extracts the primary key value from a result record,
     * whether it's an array or object.
     *
     * @param array|object $result The result record
     *
     * @return mixed The primary key value or null if not found
     */
    protected function getPrimaryKeyValue(array|object $result): mixed
    {
        if (is_object($result)) {
            return $result->{$this->primaryKey} ?? null;
        }

        return $result[$this->primaryKey] ?? null;
    }

    /**
     * Attach relation data to parent result
     *
     * Attaches the loaded relation data to the parent result,
     * handling both array and object parent results.
     *
     * @param array|object $result       The parent result
     * @param string       $relationName The relation property name
     * @param mixed        $data         The relation data to attach
     */
    protected function attachRelationToResult(array|object &$result, string $relationName, mixed $data): void
    {
        if (is_object($result)) {
            if ($result instanceof Entity) {
                $this->setEntityRelation($result, $relationName, $data);

                return;
            }

            $result->{$relationName} = $data;
        } else {
            $result[$relationName] = $data;
        }
    }

    /**
     * Store relation data on an entity without triggering strict __set() implementations.
     */
    protected function setEntityRelation(Entity $entity, string $relationName, mixed $value): void
    {
        if (self::$entityRelationSetter === null) {
            self::$entityRelationSetter = Closure::bind(
                static function (Entity $target, string $name, mixed $relationValue): void {
                    /** @psalm-suppress InaccessibleProperty Bound to Entity scope below. */
                    $target->attributes[$name] = $relationValue;
                },
                null,
                Entity::class,
            );
        }

        (self::$entityRelationSetter)($entity, $relationName, $value);
    }

    /**
     * Eager load this relation for multiple parent results
     *
     * Loads the relation for all parent records in a single optimized query
     * to prevent N+1 query problems.
     *
     * @internal
     *
     * @param array  $results      The parent model results
     * @param string $returnType   The desired return type for related data
     * @param string $relationName The name of this relation
     *
     * @return array The results with relations attached
     */
    abstract public function eagerLoad(array $results, string $returnType, string $relationName): array;

    /**
     * Lazy load this relation for a single parent
     *
     * Loads the relation on-demand for a single parent record.
     * Called by entities when accessing a relation property.
     *
     * @internal
     *
     * @param array|object $parent The parent record
     *
     * @return mixed The related data (array, object, null, or array of objects)
     */
    abstract public function lazyLoad(array|object $parent): mixed;

    /**
     * Save (upsert) related data
     *
     * For singular relations: updates existing or creates new.
     * For plural relations: updates if ID present, inserts if not.
     * Uses contextParentId for foreign key.
     *
     * @param array|object $data The data to save (array or entity)
     *
     * @return false|object The saved entity or false on validation failure
     *
     * @throws RelationException|RelationWriteException If called without parent context or write fail
     */
    abstract public function save(array|object $data): false|object;

    /**
     * Save multiple related records
     *
     * Batch saves multiple records for plural relations (HasMany, MorphMany, etc.).
     * Each record is updated if ID present, inserted if not.
     * Uses contextParentId for foreign key.
     *
     * @param array $dataSet Array of records to save (arrays or entities)
     *
     * @return array Array of saved entities
     *
     * @throws RelationException|RelationWriteException If called without parent context or write fail
     */
    abstract public function saveMany(array $dataSet): array;
}
