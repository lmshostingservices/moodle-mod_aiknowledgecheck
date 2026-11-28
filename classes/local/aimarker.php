<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_aiknowledgecheck\local;

/**
 * Machine-readable marking of AI-generated pictures and narration (EU AI Act Article 50(2)).
 *
 * Every picture and voice clip this activity stores was made by AI, so each one is marked in the file itself with
 * the IPTC digital source type "trainedAlgorithmicMedia" before it is saved:
 *
 * - JPEG: an XMP packet in an APP1 segment.
 * - PNG: an XMP packet in an iTXt chunk (keyword XML:com.adobe.xmp).
 * - Ogg Opus: comments in the OpusTags header.
 *
 * Marking is idempotent: content that already carries the marker is returned unchanged. Anything this class cannot
 * parse safely (another format, a malformed file) is returned unchanged, never damaged. Metadata can be stripped by
 * later editing, so the plugin also labels the content visibly and in the page markup.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class aimarker {
    /** IPTC NewsCodes digital source type for content made by a generative model. */
    const SOURCE_TYPE = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

    /** Name recorded as the generating tool. */
    const TOOL = 'AI Knowledge Check (LMS Labs)';

    /** Prefix of the Ogg comment that carries the marker. */
    const OGG_KEY = 'AI_GENERATED=';

    /**
     * Mark a data: URL holding an AI-generated picture.
     *
     * @param string $dataurl A data:image/...;base64 URL.
     * @return string The same URL, with the picture marked when its format is supported.
     */
    public static function mark_image_dataurl(string $dataurl): string {
        if (!preg_match('#^data:image/(png|jpe?g);base64,(.*)$#is', trim($dataurl), $m)) {
            return $dataurl;
        }
        $bytes = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
        if ($bytes === false || $bytes === '') {
            return $dataurl;
        }
        $kind = strtolower($m[1]);
        $marked = ($kind === 'png') ? self::mark_png($bytes) : self::mark_jpeg($bytes);
        if ($marked === $bytes) {
            return $dataurl;
        }
        $mime = ($kind === 'png') ? 'image/png' : 'image/jpeg';
        return 'data:' . $mime . ';base64,' . base64_encode($marked);
    }

    /**
     * Mark one AI-generated voice clip, given as base64 or as a data:audio URL.
     *
     * @param string $clip Base64 audio or a data:audio/...;base64 URL.
     * @return string The clip in the same form, marked when it is Ogg Opus.
     */
    public static function mark_audio_clip(string $clip): string {
        $prefix = '';
        $payload = trim($clip);
        if (preg_match('#^(data:audio/[a-z0-9.+-]+(?:;[a-z0-9=.+-]+)*;base64,)(.*)$#is', $payload, $m)) {
            $prefix = $m[1];
            $payload = $m[2];
        }
        $bytes = base64_decode(preg_replace('/\s+/', '', $payload), true);
        if ($bytes === false || strlen($bytes) < 64) {
            return $clip;
        }
        $marked = self::mark_ogg_opus($bytes);
        if ($marked === $bytes) {
            return $clip;
        }
        return $prefix . base64_encode($marked);
    }

    /**
     * Whether raw bytes already carry the marker.
     *
     * @param string $bytes File contents.
     * @return bool
     */
    public static function is_marked(string $bytes): bool {
        return strpos($bytes, 'trainedAlgorithmicMedia') !== false;
    }

    /**
     * The XMP packet written into pictures.
     *
     * @return string
     */
    public static function xmp_packet(): string {
        return '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>' .
            '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">' .
            '<rdf:Description rdf:about="" xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/"' .
            ' xmlns:xmp="http://ns.adobe.com/xap/1.0/" xmlns:dc="http://purl.org/dc/elements/1.1/"' .
            ' Iptc4xmpExt:DigitalSourceType="' . self::SOURCE_TYPE . '" xmp:CreatorTool="' . self::TOOL . '">' .
            '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">AI-generated image</rdf:li></rdf:Alt></dc:description>' .
            '</rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
    }

    /**
     * Insert an XMP APP1 segment after the JPEG start-of-image marker (and after JFIF/EXIF if present).
     *
     * @param string $bytes JPEG file.
     * @return string Marked JPEG, or the input when it is not a JPEG or is already marked.
     */
    public static function mark_jpeg(string $bytes): string {
        if (substr($bytes, 0, 2) !== "\xFF\xD8" || self::is_marked($bytes)) {
            return $bytes;
        }
        $payload = "http://ns.adobe.com/xap/1.0/\x00" . self::xmp_packet();
        $length = strlen($payload) + 2;
        if ($length > 0xFFFF) {
            return $bytes;
        }
        $segment = "\xFF\xE1" . pack('n', $length) . $payload;

        // Keep APP0 (JFIF) and APP1 (EXIF) first, as readers expect; insert after them.
        $pos = 2;
        $len = strlen($bytes);
        while ($pos + 4 <= $len && $bytes[$pos] === "\xFF") {
            $marker = ord($bytes[$pos + 1]);
            if ($marker !== 0xE0 && $marker !== 0xE1) {
                break;
            }
            $seglen = unpack('n', substr($bytes, $pos + 2, 2))[1];
            if ($seglen < 2 || $pos + 2 + $seglen > $len) {
                return $bytes;
            }
            $pos += 2 + $seglen;
        }
        return substr($bytes, 0, $pos) . $segment . substr($bytes, $pos);
    }

    /**
     * Insert an XMP iTXt chunk straight after the PNG IHDR chunk.
     *
     * @param string $bytes PNG file.
     * @return string Marked PNG, or the input when it is not a PNG or is already marked.
     */
    public static function mark_png(string $bytes): string {
        $signature = "\x89PNG\r\n\x1a\n";
        if (substr($bytes, 0, 8) !== $signature || self::is_marked($bytes) || strlen($bytes) < 33) {
            return $bytes;
        }
        if (substr($bytes, 12, 4) !== 'IHDR') {
            return $bytes;
        }
        $ihdrlen = unpack('N', substr($bytes, 8, 4))[1];
        $after = 8 + 12 + $ihdrlen;
        if ($after > strlen($bytes)) {
            return $bytes;
        }
        // Keyword, null, compression flag 0, method 0, empty language tag, null, empty translated keyword, null, text.
        $data = "XML:com.adobe.xmp\x00\x00\x00\x00\x00" . self::xmp_packet();
        $chunk = pack('N', strlen($data)) . 'iTXt' . $data . pack('N', crc32('iTXt' . $data));
        return substr($bytes, 0, $after) . $chunk . substr($bytes, $after);
    }

    /**
     * Add marker comments to the OpusTags header of an Ogg Opus stream.
     *
     * Only the common layout is rewritten: the OpusTags packet alone on the stream's second page. Any other layout is
     * returned unchanged.
     *
     * @param string $bytes Ogg file.
     * @return string Marked Ogg, or the input when it cannot be marked safely.
     */
    public static function mark_ogg_opus(string $bytes): string {
        if (substr($bytes, 0, 4) !== 'OggS' || self::is_marked($bytes)) {
            return $bytes;
        }
        $first = self::ogg_page($bytes, 0);
        if ($first === null || substr($first['body'], 0, 8) !== 'OpusHead') {
            return $bytes;
        }
        $second = self::ogg_page($bytes, $first['end']);
        if ($second === null || substr($second['body'], 0, 8) !== 'OpusTags') {
            return $bytes;
        }
        // The page must hold exactly one complete packet.
        $lacing = $second['lacing'];
        if (!$lacing || end($lacing) === 255 || array_sum($lacing) !== strlen($second['body'])) {
            return $bytes;
        }
        $tags = $second['body'];
        $off = 8;
        $vendorlen = unpack('V', substr($tags, $off, 4))[1];
        $off += 4 + $vendorlen;
        if ($off + 4 > strlen($tags)) {
            return $bytes;
        }
        $count = unpack('V', substr($tags, $off, 4))[1];
        $commentsstart = $off + 4;
        $pos = $commentsstart;
        for ($i = 0; $i < $count; $i++) {
            if ($pos + 4 > strlen($tags)) {
                return $bytes;
            }
            $clen = unpack('V', substr($tags, $pos, 4))[1];
            $pos += 4 + $clen;
        }
        if ($pos > strlen($tags)) {
            return $bytes;
        }
        $added = '';
        foreach ([self::OGG_KEY . 'trainedAlgorithmicMedia', 'AI_GENERATOR=' . self::TOOL] as $comment) {
            $added .= pack('V', strlen($comment)) . $comment;
        }
        $newtags = substr($tags, 0, $off) . pack('V', $count + 2) . substr($tags, $commentsstart, $pos - $commentsstart) .
            $added . substr($tags, $pos);

        // Rebuild the lacing table for the new packet size (a 255 value means "continues").
        $size = strlen($newtags);
        $newlacing = array_fill(0, intdiv($size, 255), 255);
        $newlacing[] = $size % 255;
        if (count($newlacing) > 255) {
            return $bytes;
        }
        $header = substr($second['header'], 0, 22) . "\x00\x00\x00\x00" . chr(count($newlacing));
        $page = $header . implode('', array_map('chr', $newlacing)) . $newtags;
        $crc = self::ogg_crc($page);
        $page = substr($page, 0, 22) . pack('V', $crc) . substr($page, 26);

        return substr($bytes, 0, $first['end']) . $page . substr($bytes, $second['end']);
    }

    /**
     * Parse one Ogg page.
     *
     * @param string $bytes Stream.
     * @param int $start Offset of the page.
     * @return array|null header, lacing values, body and end offset; null if malformed.
     */
    private static function ogg_page(string $bytes, int $start): ?array {
        if (substr($bytes, $start, 4) !== 'OggS' || $start + 27 > strlen($bytes)) {
            return null;
        }
        $nsegs = ord($bytes[$start + 26]);
        $lacingstr = substr($bytes, $start + 27, $nsegs);
        if (strlen($lacingstr) !== $nsegs) {
            return null;
        }
        $lacing = $nsegs ? array_map('ord', str_split($lacingstr)) : [];
        $bodylen = array_sum($lacing);
        $bodystart = $start + 27 + $nsegs;
        if ($bodystart + $bodylen > strlen($bytes)) {
            return null;
        }
        return [
            'header' => substr($bytes, $start, 27),
            'lacing' => $lacing,
            'body' => substr($bytes, $bodystart, $bodylen),
            'end' => $bodystart + $bodylen,
        ];
    }

    /**
     * Ogg page checksum: CRC-32, polynomial 0x04C11DB7, no reflection, initial value 0.
     *
     * @param string $page Page with its checksum field zeroed.
     * @return int
     */
    public static function ogg_crc(string $page): int {
        static $table = null;
        if ($table === null) {
            $table = [];
            for ($i = 0; $i < 256; $i++) {
                $r = $i << 24;
                for ($j = 0; $j < 8; $j++) {
                    $r = ($r & 0x80000000) ? ((($r << 1) ^ 0x04C11DB7) & 0xFFFFFFFF) : (($r << 1) & 0xFFFFFFFF);
                }
                $table[$i] = $r;
            }
        }
        $crc = 0;
        $len = strlen($page);
        for ($i = 0; $i < $len; $i++) {
            $crc = ((($crc << 8) & 0xFFFFFFFF) ^ $table[(($crc >> 24) & 0xFF) ^ ord($page[$i])]);
        }
        return $crc;
    }
}
