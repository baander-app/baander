<?php

namespace App\Observers;

use App\Models\Image;
use App\Modules\BlurHash\Facades\BlurHash;

class ImageObserver
{
    public function creating(Image $image)
    {
        if ($image->path) {
            $image->blurhash = BlurHash::encode($image->path);
        }
    }

    public function saving(Image $image)
    {
        if ($image->wasChanged('path')) {
            $image->blurhash = BlurHash::encode($image->path);
        }
    }
}
