Product photographs go here.

Name them exactly:
    standard-kit.jpg     (or .png / .webp)
    mini-kit.jpg         (or .png / .webp)

Then run:  python3 tools/product-art.py

Every drawing on the site is replaced by the photo, with width, height and alt
filled in automatically so the page does not shift while it loads. Run it again
after replacing a photo. Keep each file under 400 KB -- the script warns above
that. Do not reference images on another domain: verify-site.sh rejects it.
