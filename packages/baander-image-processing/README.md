# Image processing

`baander/image-processing` converts local images with GD and encodes and decodes
BlurHash values. It is an internal Baander package in the
`Baander\ImageProcessing` namespace. Dependencies are PHP 8.5, `ext-gd` and PSR
logging; see [composer.json](composer.json).

[`GdImageConverter`](src/GdImageConverter.php) accepts explicit output paths.
`convertToWebp($source, $destination)` returns an existing WebP source unchanged;
`resizeToWebp($source, $destination, $maxWidth)` writes a WebP without enlarging
small images. Both publish new output through a temporary file and rename.
[`BlurHash`](src/BlurHash.php) handles pixels or GD images;
[`BlurHashGenerator`](src/BlurHashGenerator.php) adds file loading and logging.

The application registers these services in [services.yaml](../../config/services.yaml).
Its [conversion adapter](../../src/Media/Infrastructure/Converter/GdImageConverter.php)
owns named sizes and output filenames. Storage, image authorization, derivative
locking and background jobs remain in the application.

Production embedding belongs to the application's
[TypePHP configuration](../../tools/typephp/application.yml). The package has no
separate executable or build step.
