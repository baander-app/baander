<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Image;
use Illuminate\Http\Request;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Prefix;

#[Prefix('images')]
class ImageController extends Controller
{
    /**
     * Get image asset
     *
     * @unauthenticated
     */
    #[Get('{image}', 'api.image.serve')]
    public function serve(Image $image)
    {
        return response()->file($image->getPath());
    }
}
