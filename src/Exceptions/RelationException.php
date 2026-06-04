<?php

declare(strict_types=1);

namespace Michalsn\CodeIgniterRelations\Exceptions;

use RuntimeException;

final class RelationException extends RuntimeException
{
    public static function forThroughRelationNotWritable(string $relationType): static
    {
        return new self(lang('Relations.throughRelationNotWritable', [$relationType]));
    }

    public static function forMorphToNotWritable(): static
    {
        return new self(lang('Relations.morphToNotWritable'));
    }

    public static function forInvalidMorphModel(string $modelClass): static
    {
        return new self(lang('Relations.invalidMorphModel', [$modelClass]));
    }

    public static function forRelationMethodNotFound(string $methodName, string $modelClass): static
    {
        return new self(lang('Relations.relationMethodNotFound', [$methodName, $modelClass]));
    }

    public static function forCallbackWithArrayRelations(): static
    {
        return new self(lang('Relations.callbackWithArrayRelations'));
    }

    public static function forSaveManyNotSupportedForSingular(string $relationType): static
    {
        return new self(lang('Relations.saveManyNotSupportedForSingular', [$relationType]));
    }

    public static function forMissingParentContext(string $methodName): static
    {
        return new self(lang('Relations.missingParentContext', [$methodName]));
    }

    public static function forMethodNotSupported(string $methodName, string $relationType): static
    {
        return new self(lang('Relations.methodNotSupported', [$methodName, $relationType]));
    }

    public static function forPrimaryKeyNotAllowedInSave(string $primaryKey): static
    {
        return new self(lang('Relations.primaryKeyNotAllowedInSave', [$primaryKey]));
    }

    public static function forPrimaryKeyNotAllowedInSaveMany(string $primaryKey, int $index): static
    {
        return new self(lang('Relations.primaryKeyNotAllowedInSaveMany', [$primaryKey, $index]));
    }

    public static function forModelClassNotFound(string $entityClass): static
    {
        return new self(lang('Relations.modelClassNotFound', [$entityClass]));
    }

    public static function forCannotDeleteWithoutPrimaryKey(): static
    {
        return new self(lang('Relations.cannotDeleteWithoutPrimaryKey'));
    }

    public static function forMethodNotARelation(string $method, string $modelClass): static
    {
        return new self(lang('Relations.methodNotARelation', [$method, $modelClass]));
    }

    public static function forCannotCallRelationWithoutId(string $method, string $primaryKey): static
    {
        return new self(lang('Relations.cannotCallRelationWithoutId', [$method, $primaryKey]));
    }

    public static function forParentMissingKey(string $keyName): static
    {
        return new self(lang('Relations.parentMissingKey', [$keyName]));
    }

    public static function forIdRequiredWithModelClass(): static
    {
        return new self(lang('Relations.idRequiredWithModelClass'));
    }
}
