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

/**
 * Tests for machine-readable marking of AI-generated pictures and narration (EU AI Act Article 50(2)).
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aiknowledgecheck;

use mod_aiknowledgecheck\local\aimarker;

/**
 * Marker tests.
 *
 * @covers \mod_aiknowledgecheck\local\aimarker
 */
final class aimarker_test extends \advanced_testcase {
    /**
     * A small picture in the given format.
     *
     * @param string $format jpeg or png.
     * @return string File bytes.
     */
    private function picture(string $format): string {
        $im = imagecreatetruecolor(32, 16);
        imagefill($im, 0, 0, 0x0f6cbf);
        ob_start();
        if ($format === 'png') {
            imagepng($im);
        } else {
            imagejpeg($im);
        }
        return ob_get_clean();
    }

    public function test_jpeg_is_marked_and_still_decodes(): void {
        $jpeg = $this->picture('jpeg');
        $marked = aimarker::mark_jpeg($jpeg);
        $this->assertNotSame($jpeg, $marked);
        $this->assertTrue(aimarker::is_marked($marked));
        $this->assertStringContainsString(aimarker::SOURCE_TYPE, $marked);
        $this->assertNotFalse(imagecreatefromstring($marked));
        $this->assertSame($marked, aimarker::mark_jpeg($marked), 'Marking twice changes nothing.');
    }

    public function test_png_is_marked_with_a_valid_chunk(): void {
        $png = $this->picture('png');
        $marked = aimarker::mark_png($png);
        $this->assertTrue(aimarker::is_marked($marked));
        $this->assertNotFalse(imagecreatefromstring($marked));
        // The iTXt chunk's CRC must be right, or strict readers reject the file.
        $pos = strpos($marked, 'iTXt');
        $length = unpack('N', substr($marked, $pos - 4, 4))[1];
        $crc = unpack('N', substr($marked, $pos + 4 + $length, 4))[1];
        $this->assertSame(crc32(substr($marked, $pos, 4 + $length)), $crc);
    }

    public function test_data_url_keeps_its_form(): void {
        $url = 'data:image/jpeg;base64,' . base64_encode($this->picture('jpeg'));
        $marked = aimarker::mark_image_dataurl($url);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $marked);
        $this->assertTrue(aimarker::is_marked(base64_decode(substr($marked, 23))));
    }

    public function test_stored_inline_pictures_are_marked(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/aiknowledgecheck/lib.php');
        $url = 'data:image/png;base64,' . base64_encode($this->picture('png'));
        $stored = mod_aiknowledgecheck_sanitize_image_url($url);
        $this->assertTrue(aimarker::is_marked(base64_decode(substr($stored, 22))));
        // Links a teacher pastes are left alone.
        $this->assertSame('https://example.com/a.png', mod_aiknowledgecheck_sanitize_image_url('https://example.com/a.png'));
    }

    public function test_unsupported_or_broken_content_is_never_damaged(): void {
        $this->assertSame('data:image/png;base64,AAAA', aimarker::mark_image_dataurl('data:image/png;base64,AAAA'));
        $this->assertSame('data:image/gif;base64,R0lGOD', aimarker::mark_image_dataurl('data:image/gif;base64,R0lGOD'));
        $this->assertSame('bm90IGF1ZGlv', aimarker::mark_audio_clip('bm90IGF1ZGlv'));
        $this->assertSame('OggS-truncated', aimarker::mark_ogg_opus('OggS-truncated'));
    }

    public function test_ogg_opus_narration_is_marked_with_valid_checksums(): void {
        $ogg = file_get_contents(__DIR__ . '/fixtures/tone.ogg');

        // The checksum routine matches the encoder's on an untouched page.
        $first = substr($ogg, 0, 27 + ord($ogg[26]) + array_sum(array_map('ord', str_split(substr($ogg, 27, ord($ogg[26]))))));
        $stored = unpack('V', substr($first, 22, 4))[1];
        $this->assertSame($stored, aimarker::ogg_crc(substr($first, 0, 22) . "\0\0\0\0" . substr($first, 26)));

        $marked = aimarker::mark_ogg_opus($ogg);
        $this->assertStringContainsString(aimarker::OGG_KEY . 'trainedAlgorithmicMedia', $marked);
        $this->assertSame($marked, aimarker::mark_ogg_opus($marked));

        // Every page of the marked stream still carries a correct checksum.
        $pos = 0;
        $pages = 0;
        while ($pos < strlen($marked)) {
            $this->assertSame('OggS', substr($marked, $pos, 4));
            $nsegs = ord($marked[$pos + 26]);
            $body = array_sum(array_map('ord', str_split(substr($marked, $pos + 27, $nsegs))));
            $page = substr($marked, $pos, 27 + $nsegs + $body);
            $crc = unpack('V', substr($page, 22, 4))[1];
            $this->assertSame($crc, aimarker::ogg_crc(substr($page, 0, 22) . "\0\0\0\0" . substr($page, 26)));
            $pos += strlen($page);
            $pages++;
        }
        $this->assertGreaterThan(2, $pages);

        $clip = 'data:audio/ogg;base64,' . base64_encode($ogg);
        $this->assertStringStartsWith('data:audio/ogg;base64,', aimarker::mark_audio_clip($clip));
        $this->assertSame(base64_encode($marked), aimarker::mark_audio_clip(base64_encode($ogg)));
    }
}
