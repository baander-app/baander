<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Pagination;

use App\Shared\Domain\Model\Cursor;
use App\Shared\Domain\Model\CursorDirection;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;

final readonly class CursorCodec
{
    public function __construct(
        private readonly JsonEncoder $jsonEncoder,
    ) {
    }

    public function encode(Cursor $cursor): string
    {
        $json = $this->jsonEncoder->encode([
            'direction' => $cursor->getDirection()->value,
            'values' => $cursor->getValues(),
        ], 'json');

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public function decode(string $cursor): ?Cursor
    {
        // Restore base64 padding
        $remainder = strlen($cursor) % 4;
        if ($remainder > 0) {
            $cursor .= str_repeat('=', 4 - $remainder);
        }

        // Reverse base64url to standard base64
        $base64 = strtr($cursor, '-_', '+/');

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            return null;
        }

        try {
            $data = $this->jsonEncoder->decode($decoded, 'json');
        } catch (NotEncodableValueException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        if (!isset($data['direction'], $data['values'])) {
            return null;
        }

        if (!is_string($data['direction']) || !is_array($data['values'])) {
            return null;
        }

        try {
            $direction = CursorDirection::from($data['direction']);
        } catch (\ValueError) {
            return null;
        }

        return Cursor::create($direction, $data['values']);
    }
}
