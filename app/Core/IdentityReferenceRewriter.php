<?php

declare(strict_types=1);

namespace App\Core;

final class IdentityReferenceRewriter
{
    public static function replaceValues(mixed $value, string $oldIdentity, string $newIdentity, int &$count): mixed
    {
        if (is_string($value)) {
            if ($value !== $oldIdentity) {
                return $value;
            }

            $count++;

            return $newIdentity;
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::replaceValues($item, $oldIdentity, $newIdentity, $count);
        }

        return $value;
    }
}
