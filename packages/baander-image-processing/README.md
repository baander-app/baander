# Image processing

`baander/image-processing` converts local images with GD and encodes and decodes
BlurHash values. It is an internal Baander package in the
`Baander\ImageProcessing` namespace. Dependencies are PHP 8.5, `ext-gd` and PSR
logging; see [composer.json](composer.json).

[`GdImageConverter`](src/GdImageConverter.php) accepts explicit output paths.
`convertToWebp($source, $destination)` returns an existing WebP source unchanged;
`resizeToWebp($source, $destination, $maxWidth)` writes a WebP without enlarging
small images. Both publish new output through a temporary file and rename.
[`BlurHash`](src/BlurHash/BlurHash.php) handles pixels or GD images;
[`BlurHashGenerator`](src/BlurHash/BlurHashGenerator.php) adds file loading and logging.

The application registers these services in [services_packages.yaml](../../config/services_packages.yaml).
Its [conversion adapter](../../src/Media/Infrastructure/Converter/ImageConversionAdapter.php)
owns named sizes and output filenames. Storage, image authorization, derivative
locking and background jobs remain in the application.

GD conversion stays at `src/` root; [BlurHash](src/BlurHash/) contains the codec
and file helper. [Package tests](tests/) cover conversion and codec behavior.
Application adapter tests retain preset and filename checks; derivative worker
and authorization tests stay in the application's test tree.

The root [Unit suite](../../phpunit.xml.dist) discovers the package's tests.
Use the existing [backend runners](../../docs-book/part-2-developer-guide/testing/README.md)
from the repository root.

The package has no separate executable or build step.
