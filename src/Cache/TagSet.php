<?php

namespace ByErikas\ClassicTaggableCache\Cache;

use Generator;
use Illuminate\Cache\RedisTagSet as BaseTagSet;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\LazyCollection;

class TagSet extends BaseTagSet
{
    public const TAG_PREFIX = "\0tags\0";
    public const KEY_PREFIX = "\0key\0";

    /**
     * Get the unique tag identifier for a given tag.
     *
     * @param  string  $name
     * @return string
     */
    public function tagId($name)
    {
        return self::TAG_PREFIX . $name;
    }

    /**
     * {@inheritDoc}
     */
    public function tagIds()
    {
        return array_map([$this, 'tagId'], $this->names);
    }

    public function tagNamePrefix()
    {
        return self::TAG_PREFIX . implode("\0", $this->names);
    }

    /**
     * Get the tag identifier key for a given tag.
     *
     * @param  string  $name
     * @return string
     */
    public function tagKey($name)
    {
        return self::TAG_PREFIX . $name;
    }

    /**
     * {@inheritDoc}
     */
    public function entries()
    {
        /** @disregard P1013 */
        $connection = $this->store->connection();

        $defaultCursorValue = match (true) {
            $connection instanceof PhpRedisConnection && version_compare(phpversion('redis'), '6.1.0', '>=') => null,
            default => '0',
        };

        $result = LazyCollection::make(function () use ($connection, $defaultCursorValue) {
            foreach ($this->getNamespaces() as $tagKey) {
                $cursor = $defaultCursorValue;

                do {
                    [$cursor, $entries] = $connection->zscan(
                        $this->store->getPrefix() . $tagKey,
                        $cursor,
                        ['match' => '*', 'count' => 1000]
                    );

                    if (! is_array($entries)) {
                        break;
                    }

                    $entries = array_unique(array_keys($entries));

                    if (count($entries) === 0) {
                        continue;
                    }

                    foreach ($entries as $entry) {
                        yield $entry;
                    }
                } while (((string) $cursor) !== $defaultCursorValue);
            }
        });

        return $result;
    }

    #region Helpers
    /**
     * Get all possible namespaces for the tagset being used
     */
    protected function getNamespaces(): array
    {
        $result = $this->tagIds();

        //Build all possible permutations to scan.
        foreach ($this->getPermutations($result) as $permutation) {
            $result[] = implode("", $permutation);
        }

        return array_unique($result);
    }

    /**
     * Builds a tag permutation generator.
     */
    private function getPermutations(array $elements): Generator
    {
        if (count($elements) <= 1) {
            yield $elements;
        } else {
            foreach ($this->getPermutations(array_slice($elements, 1)) as $permutation) {
                foreach (range(0, count($elements) - 1) as $i) {
                    yield array_merge(
                        array_slice($permutation, 0, $i),
                        [$elements[0]],
                        array_slice($permutation, $i)
                    );
                }
            }
        }
    }
    #endregion
}
