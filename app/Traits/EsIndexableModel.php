<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Traits;

use App\Libraries\Elasticsearch\Es;
use Log;

trait EsIndexableModel
{
    use EsIndexable;

    abstract public static function esIndexingQuery();

    public static function esIndexIntoNew($batchSize = 1000, $name = null, callable $progress = null)
    {
        $newIndex = $name ?? static::esIndexName().'_'.time();
        Log::info("Creating new index {$newIndex}");
        static::esCreateIndex($newIndex);

        $options = [
            'index' => $newIndex,
        ];

        static::esReindexAll($batchSize, 0, $options, $progress);

        return $newIndex;
    }

    public static function esReindexAll($batchSize = 1000, $fromId = 0, array $options = [], callable $progress = null)
    {
        $dummy = new static();
        $startTime = time();

        $baseQuery = static::esIndexingQuery()->where($dummy->getKeyName(), '>', $fromId);
        $count = 0;

        $baseQuery->chunkById($batchSize, function ($models) use ($options, &$count, $progress) {
            $actions = [];

            foreach ($models as $model) {
                $next = $model;
                // bulk API am speshul.
                $metadata = [
                    '_id' => $model->getEsId(),
                    'routing' => $model->esRouting(),
                ];

                if (!$model->esShouldIndex()) {
                    $actions[] = ['delete' => $metadata];
                } else {
                    // index requires action and metadata followed by data on the next line.
                    $actions[] = ['index' => $metadata];
                    $actions[] = $model->toEsJson();
                }
            }

            if ($actions !== []) {
                $result = Es::getClient()->bulk([
                    'index' => $options['index'] ?? static::esIndexName(),
                    'type' => static::esType(),
                    'body' => $actions,
                    'client' => ['timeout' => 0],
                ]);

                $count += count($result['items']);
            }

            Log::info(static::class." next: {$models->last()->getKey()}");
            if ($progress) {
                $progress($count);
            }
        });

        $duration = time() - $startTime;
        Log::info(static::class." Indexed {$count} records in {$duration} s.");
    }

    /**
     * The value for routing.
     * Override to provide a routing value; null by default.
     *
     * @return string|null
     */
    public function esRouting()
    {
        // null will be omitted when used as routing.
    }

    public function esDeleteDocument(array $options = [])
    {
        $document = array_merge([
            'index' => static::esIndexName(),
            'type' => static::esType(),
            'routing' => $this->esRouting(),
            'id' => $this->getEsId(),
            'client' => ['ignore' => 404],
        ], $options);

        return Es::getClient()->delete($document);
    }

    public function esIndexDocument(array $options = [])
    {
        if (!$this->esShouldIndex()) {
            return $this->esDeleteDocument($options);
        }

        $document = array_merge([
            'index' => static::esIndexName(),
            'type' => static::esType(),
            'routing' => $this->esRouting(),
            'id' => $this->getEsId(),
            'body' => $this->toEsJson(),
        ], $options);

        return Es::getClient()->index($document);
    }

    public function esShouldIndex()
    {
        return true;
    }

    public function getEsId()
    {
        return $this->getKey();
    }

    abstract public function toEsJson();
}
