<?php

namespace TrafficOps\LaravelCloud\Relations;

use Illuminate\Database\Eloquent\Relations\MorphMany;

final class StringMorphMany extends MorphMany
{
    public function getParentKey(): string
    {
        return (string) parent::getParentKey();
    }

    public function addEagerConstraints(array $models): void
    {
        $keys = array_map('strval', $this->getKeys($models, $this->localKey));
        $this->whereInEager('whereIn', $this->foreignKey, $keys, $this->getRelationQuery());
        $this->getRelationQuery()->where($this->morphType, $this->morphClass);
    }
}
