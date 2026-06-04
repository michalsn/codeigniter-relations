<?php

return [
    'throughRelationNotWritable'      => 'Cannot save data through {0} relation. Through relations are read-only. To modify related data, save directly through the intermediate model.',
    'morphToNotWritable'              => 'Cannot save data through morphTo relation. MorphTo is an inverse polymorphic relation and is read-only. To modify related data, save directly through the parent model.',
    'invalidMorphModel'               => 'Cannot load MorphTo relation: model class "{0}" does not exist or could not be instantiated.',
    'relationMethodNotFound'          => 'Relation method "{0}" not found on {1}.',
    'callbackWithArrayRelations'      => 'The callback argument cannot be used when loading relations with array syntax. Use relation names as array keys with callbacks as values instead.',
    'saveManyNotSupportedForSingular' => 'The saveMany() method is not supported for {0} relations. Use save() instead.',
    'missingParentContext'            => 'Cannot call {0} without parent context. Use $entity->relation()->{0} instead.',
    'methodNotSupported'              => 'The {0} method is not supported for {1} relations.',
    'primaryKeyNotAllowedInSave'      => 'Cannot use save() with existing records (primary key "{0}" found). Use attach() to link existing records, or remove the primary key to create a new record.',
    'primaryKeyNotAllowedInSaveMany'  => 'Cannot use saveMany() with existing records (primary key "{0}" found at index {1}). Use attach() to link existing records, or remove the primary key to create new records.',
    'batchOperationRolledBack'        => 'Batch {0} failed at record {1}. Transaction rolled back.',
    'batchWritePartialFailure'        => 'Batch operation completed with failures: {0} of {1} records failed. Successful IDs are available.',
    'modelClassNotFound'              => 'Model class not found for entity {0}',
    'cannotDeleteWithoutPrimaryKey'   => 'Cannot delete entity without primary key value',
    'methodNotARelation'              => 'Method "{0}" on {1} is not a relation method',
    'cannotCallRelationWithoutId'     => 'Cannot call relation "{0}" on entity without ID (primary key: {1})',
    'parentMissingKey'                => 'Parent entity must have a {0} value',
    'idRequiredWithModelClass'        => 'When passing a model class as first parameter, the ID must be provided as second parameter.',
];
