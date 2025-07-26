<?php

namespace App\Http\Controllers\Api\Media;

use App\Http\Controllers\Controller;
use App\Models\Image;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Prefix;

#[Prefix('images')]
class ImageController extends Controller
{
    /**
     * Get an image asset
     *
     * @unauthenticated
     */
    #[Get('{image}', 'api.image.serve')]
    public function serve(Image $image)
    {
        return response()->file($image->getPath());
    }
}
