<?php

namespace App\Models;

use App\Observers\ImageObserver;
use App\Packages\Http\Concerns\DirectStreamableFile;
use App\Packages\Nanoid\Concerns\HasNanoPublicId;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;

#[ObservedBy(ImageObserver::class)]
class Image extends BaseModel implements DirectStreamableFile
{
    use HasFactory, HasNanoPublicId;

    protected $fillable = [
        'public_id',
        'path',
        'extension',
        'size',
        'mime_type',
        'width',
        'height',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function imageable()
    {
        return $this->morphTo();
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getMimeType(): string
    {
        return $this->mime_type;
    }
}
