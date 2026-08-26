Product photographs.

standard-kit.webp   Starlink Standard (Gen 3 / V4) -- dish and retail box
mini-kit.webp       Starlink Mini -- dish with the kickstand out

To replace one, optimise it first (there is no PIL or ImageMagick here, so
Chromium does it -- it also crops away the white studio margin):

    ./tools/optimise-image.sh ~/new-photo.jpg site/assets/img/products/standard-kit.webp
    python3 tools/product-art.py

product-art.py reads the dimensions from the file header and writes the local
path, width, height, alt and lazy loading into every page that shows that kit,
so nothing reflows while the photo loads. .jpg and .png work too; .webp is
picked first when several exist.

Never reference an image on another domain -- verify-site.sh rejects it. That
is how the site lost all its photography once already.
