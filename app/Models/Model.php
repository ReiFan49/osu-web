<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Models;

use App\Exceptions\ModelNotSavedException;
use App\Libraries\Transactions\AfterCommit;
use App\Libraries\Transactions\AfterRollback;
use App\Libraries\TransactionStateManager;
use App\Traits\MacroableModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as BaseModel;

abstract class Model extends BaseModel
{
    use MacroableModel;

    protected $connection = 'mysql';
    protected $guarded = [];

    public function getForeignKey()
    {
        if ($this->primaryKey === null || $this->primaryKey === 'id') {
            return parent::getForeignKey();
        }

        return $this->primaryKey;
    }

    public function getMacros()
    {
        $macros = $this->macros ?? [];
        $macros[] = 'realCount';
        $macros[] = 'last';

        return $macros;
    }

    /**
     * Locks the current model for update with `select for update`.
     *
     * @return $this
     */
    public function lockSelf()
    {
        return $this->lockForUpdate()->find($this->getKey());
    }

    public function macroLast()
    {
        return function ($baseQuery, $column = null) {
            $query = clone $baseQuery;

            return $query->orderBy($column ?? $this->getKeyName(), 'DESC')->first();
        };
    }

    public function macroRealCount()
    {
        return function ($baseQuery) {
            $query = clone $baseQuery;
            $query->getQuery()->orders = null;
            $query->getQuery()->offset = null;
            $query->limit(null);

            return min($query->count(), config('osu.pagination.max_count'));
        };
    }

    public function refresh()
    {
        if (isset($this->memoized)) {
            $this->memoized = [];
        }

        return parent::refresh();
    }

    public function scopeCursorSort($query, array $sort, ?array $cursor)
    {
        if (empty($cursor)) {
            foreach ($sort as $sortItem) {
                $query->orderBy($sortItem['column'], $sortItem['order']);
            }
        } else {
            $query->cursorWhere($cursor);
        }
    }

    public function scopeCursorWhere($query, array $cursors, bool $isFirst = true)
    {
        if (empty($cursors)) {
            return;
        }

        if ($isFirst) {
            foreach ($cursors as $cursor) {
                $query->orderBy($cursor['column'], $cursor['order']);
            }
        }

        $cursor = array_shift($cursors);

        $dir = strtoupper($cursor['order']) === 'DESC' ? '<' : '>';

        if (count($cursors) === 0) {
            $query->where($cursor['column'], $dir, $cursor['value']);
        } else {
            $query->where($cursor['column'], "{$dir}=", $cursor['value'])
                ->where(function ($q) use ($cursor, $dir, $cursors) {
                    $q->where($cursor['column'], $dir, $cursor['value'])
                        ->orWhere(function ($qq) use ($cursors) {
                            $qq->cursorWhere($cursors, false);
                        });
                });
        }
    }

    public function scopeReorderBy($query, $field, $order)
    {
        $query->getQuery()->orders = null;

        return $query->orderBy($field, $order);
    }

    public function scopeOrderByField($query, $field, $ids)
    {
        $size = count($ids);

        if ($size === 0) {
            return;
        }

        $bind = implode(',', array_fill(0, $size, '?'));
        $string = "FIELD({$field}, {$bind})";
        $values = array_map('strval', $ids);

        $query->orderByRaw($string, $values);
    }

    public function scopeNone($query)
    {
        $query->whereRaw('false');
    }

    public function scopeWithPresent($query, $column)
    {
        $query->whereNotNull($column)->where($column, '<>', '');
    }

    public function delete()
    {
        return $this->runAfterCommitWrapper(function () {
            return parent::delete();
        });
    }

    public function save(array $options = [])
    {
        return $this->runAfterCommitWrapper(function () use ($options) {
            return parent::save($options);
        });
    }

    public function saveOrExplode($options = [])
    {
        return $this->getConnection()->transaction(function () use ($options) {
            $result = $this->save($options);

            if ($result === false) {
                $message = method_exists($this, 'validationErrors') ?
                    $this->validationErrors()->toSentence() :
                    'failed saving model';

                throw new ModelNotSavedException($message);
            }

            return $result;
        });
    }

    public function dbName()
    {
        $connection = $this->connection ?? config('database.default');

        return config("database.connections.{$connection}.database");
    }

    public function tableName(bool $includeDbPrefix = false)
    {
        return ($includeDbPrefix ? $this->dbName().'.' : '').$this->getTable();
    }

    // Allows save/update/delete to work with composite primary keys.
    // Note this doesn't fix 'find' method and a bunch of other laravel things
    // which rely on getKeyName and getKey (and they themselves are broken as well).
    protected function setKeysForSaveQuery(Builder $query)
    {
        if (isset($this->primaryKeys)) {
            foreach ($this->primaryKeys as $key) {
                $query->where([$key => $this->original[$key] ?? null]);
            }

            return $query;
        } else {
            return parent::setKeysForSaveQuery($query);
        }
    }

    private function enlistCallbacks($model, $connection)
    {
        $transaction = resolve(TransactionStateManager::class)->current($connection);
        if ($model instanceof AfterCommit) {
            $transaction->addCommittable($model);
        }

        if ($model instanceof AfterRollback) {
            $transaction->addRollbackable($model);
        }

        return $transaction;
    }

    private function runAfterCommitWrapper(callable $fn)
    {
        $transaction = $this->enlistCallbacks($this, $this->connection);

        $result = $fn();

        if ($this instanceof AfterCommit && $transaction->isReal() === false) {
            $transaction->commit();
        }

        return $result;
    }
}
