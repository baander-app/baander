<?php

namespace App\Extensions;

class ArrExt
{
    public static function dotKeys(array $arr): array
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($arr));
        $res = [];

        foreach ($iterator as $leaf) {
            $keys = [];

            foreach (range(0, $iterator->getDepth()) as $depth) {
                $keys[] = $iterator->getSubIterator($depth)->key();
            }

            $res[implode('.', $keys)] = $leaf;
        }

        return $res;
    }
}
