"""Image dimensions without PIL, which this environment does not have.

Only enough of each format to find width and height, because <img> needs
width and height attributes or the page reflows as photos arrive.
"""
import struct


def size(path):
    """(width, height, kind) or None if the format is not one we serve."""
    with open(path, 'rb') as f:
        head = f.read(32)
        if head[:8] == b'\x89PNG\r\n\x1a\n':
            w, h = struct.unpack('>II', head[16:24])
            return w, h, 'png'
        if head[:6] in (b'GIF87a', b'GIF89a'):
            w, h = struct.unpack('<HH', head[6:10])
            return w, h, 'gif'
        if head[:4] == b'RIFF' and head[8:12] == b'WEBP':
            f.seek(12)
            chunk = f.read(8)
            tag = chunk[:4]
            if tag == b'VP8X':
                b = f.read(10)
                w = 1 + int.from_bytes(b[4:7], 'little')
                h = 1 + int.from_bytes(b[7:10], 'little')
                return w, h, 'webp'
            if tag == b'VP8 ':
                b = f.read(10)
                w = int.from_bytes(b[6:8], 'little') & 0x3FFF
                h = int.from_bytes(b[8:10], 'little') & 0x3FFF
                return w, h, 'webp'
            if tag == b'VP8L':
                b = f.read(5)
                bits = int.from_bytes(b[1:5], 'little')
                return (bits & 0x3FFF) + 1, ((bits >> 14) & 0x3FFF) + 1, 'webp'
            return None
        if head[:2] == b'\xff\xd8':
            # JPEG: walk the segments to the start-of-frame, which carries the size.
            f.seek(2)
            while True:
                b = f.read(1)
                if not b:
                    return None
                if b != b'\xff':
                    continue
                while b == b'\xff':
                    b = f.read(1)
                marker = b[0]
                if marker in (0xD8, 0xD9) or 0xD0 <= marker <= 0xD7:
                    continue
                ln = f.read(2)
                if len(ln) < 2:
                    return None
                seglen = struct.unpack('>H', ln)[0]
                # SOF0..SOF15, excluding the non-frame markers in that range
                if 0xC0 <= marker <= 0xCF and marker not in (0xC4, 0xC8, 0xCC):
                    data = f.read(5)
                    h, w = struct.unpack('>HH', data[1:5])
                    return w, h, 'jpg'
                f.seek(seglen - 2, 1)
    return None
