<?php

declare(strict_types=1);

namespace App\Discovery\Domain\ValueObject;

enum AuthenticationMethod: string
{
    case QrCode = 'qr_code';
    case EmailUrl = 'email_url';
    case ServerCode = 'server_code';

    public function label(): string
    {
        return match ($this) {
            self::QrCode => 'QR Code',
            self::EmailUrl => 'Email + URL',
            self::ServerCode => 'Server Code',
        };
    }
}
