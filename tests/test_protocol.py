"""Tests für den Parser (ohne Hardware). Aufruf: python -m unittest discover -s tests"""

from __future__ import annotations

import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "plugin", "bin"))

from hargassner_protocol import (  # noqa: E402
    FrameSplitter,
    FrameValidator,
    build_topics,
    normalize_fields,
    slug,
)

# Echter Datensatz der Anlage vom 14.01.2026 (92 Werte inkl. "pm", tm/Datum/Uhrzeit am Ende)
REAL = (
    "pm 100 1 7.1 68 202 0.9 0.7 39.6 40.7 36.0 40.0 59 52 61 75 0.0 0.0 56437020.0 0 39.9 38.6 37.0 34.0 "
    "140 140 140 140.0 140.0 0.0 0.0 140 140 11 3 100 13 16 21 100 100 20 125 14 0.0 0.0 0.0 8.0 0.00 0.00 "
    "0.0 0.00 96 53 80 69 112 173 20 111 1 3 3 3 0 0 0 0 0 0 0 0 0 0 0 0 0 0 1 6 200 300 2129 9 ff1 5 ffff "
    "0 0 tm 2026-01-14 09:38:36"
)


def stream(times: int, sep: str = "\r\n") -> bytes:
    return (sep.join([REAL] * times) + sep).encode("ascii")


class SplitterTest(unittest.TestCase):
    def test_indices_match_old_scripts(self):
        frames = FrameSplitter().feed(stream(3))
        # Der letzte Datensatz ist erst fertig, wenn das nächste "pm" kommt
        self.assertEqual(len(frames), 2)
        frame = frames[0]
        self.assertEqual(len(frame), 92)
        self.assertEqual(frame[0], "pm")
        self.assertEqual(frame[3], "7.1")
        self.assertEqual(frame[18], "56437020.0")
        self.assertEqual(frame[82], "2129")
        self.assertEqual(frame[89], "tm")
        self.assertEqual(frame[90], "2026-01-14")
        self.assertEqual(frame[91], "09:38:36")

    def test_byte_wise_chunks_and_cr_only(self):
        splitter = FrameSplitter()
        frames = []
        for byte in stream(3, sep="\r"):
            frames += splitter.feed(bytes([byte]))
        self.assertEqual([len(f) for f in frames], [92, 92])

    def test_start_in_the_middle_of_a_record(self):
        data = stream(3)
        frames = FrameSplitter().feed(data[37:])  # Anschluss mitten im ersten Datensatz
        self.assertEqual([len(f) for f in frames], [92])

    def test_double_spaces_and_garbage_bytes(self):
        noisy = REAL.replace(" ", "  \x00 ", 5)
        frames = FrameSplitter().feed(f"{noisy}\r\n{REAL}\r\npm\r\n".encode("ascii"))
        self.assertEqual([len(f) for f in frames], [92, 92])

    def test_lost_start_marker_merges_records(self):
        broken = REAL.replace("pm", "p\x80", 1)  # Störung im "pm" -> wird zu "p"
        frames = FrameSplitter().feed(f"{REAL}\r\n{broken}\r\n{REAL}\r\npm\r\n".encode("latin-1"))
        self.assertEqual([len(f) for f in frames], [92 + 92, 92])


class ValidatorTest(unittest.TestCase):
    def test_locks_after_three_equal_records(self):
        v = FrameValidator()
        frame = REAL.split()
        self.assertEqual([v.check(frame) for _ in range(4)], [False, False, True, True])
        self.assertEqual(v.length, 92)
        self.assertEqual(v.rejected, 0)

    def test_rejects_merged_record(self):
        v = FrameValidator()
        frame = REAL.split()
        for _ in range(3):
            v.check(frame)
        self.assertFalse(v.check(frame + frame))
        self.assertEqual(v.rejected, 1)
        self.assertEqual(v.last_rejected_length, 184)
        self.assertTrue(v.check(frame))

    def test_relocks_after_firmware_change(self):
        v = FrameValidator(relock_after=5)
        frame = REAL.split()
        for _ in range(3):
            v.check(frame)
        longer = frame + ["0"]
        results = [v.check(longer) for _ in range(5)]
        self.assertEqual(results, [False, False, False, False, True])
        self.assertEqual(v.length, 93)
        self.assertEqual(v.relocked_from, 92)

    def test_fixed_length(self):
        v = FrameValidator(expected=92)
        self.assertTrue(v.check(REAL.split()))
        self.assertFalse(v.check(REAL.split()[:-1]))

    def test_too_short(self):
        v = FrameValidator()
        self.assertFalse(v.check(["pm", "1", "2"]))
        self.assertEqual(v.rejected, 1)


class TopicsTest(unittest.TestCase):
    def test_index_topics(self):
        topics = build_topics(REAL.split(), {})
        self.assertEqual(topics["idx_0"], "pm")
        self.assertEqual(topics["idx_91"], "09:38:36")
        self.assertEqual(len(topics), 92)

    def test_names_and_hex_bits(self):
        fields = normalize_fields({
            "4": "Kesseltemp",  # altes Format
            "84": {"name": "Digital 3", "type": "hex", "bits": {"0": "Pumpe HK1", "7": "Störlampe"}},
        })
        topics = build_topics(REAL.split(), fields)
        self.assertEqual(topics["kesseltemp"], "68")
        self.assertEqual(topics["digital_3"], "ff1")
        # 0xff1 = 1111 1111 0001
        self.assertEqual(topics["idx_84/bit_0"], "1")
        self.assertEqual(topics["idx_84/bit_1"], "0")
        self.assertEqual(topics["idx_84/bit_4"], "1")
        self.assertEqual(topics["idx_84/bit_11"], "1")
        self.assertEqual(topics["idx_84/bit_12"], "0")
        self.assertEqual(topics["pumpe_hk1"], "1")
        self.assertEqual(topics["stoerlampe"], "1")

    def test_decimal_bits(self):
        fields = {"82": {"name": "", "type": "dec", "bits": {}}}
        topics = build_topics(REAL.split(), fields)
        # 2129 = 0b1000_0101_0001
        self.assertEqual([topics[f"idx_82/bit_{b}"] for b in (0, 4, 6, 11)], ["1", "1", "1", "1"])
        self.assertEqual(topics["idx_82/bit_7"], "0")

    def test_switches(self):
        fields = normalize_fields({"4": "Kesseltemp"})
        only_names = build_topics(REAL.split(), fields, publish_index=False)
        self.assertEqual(only_names, {"kesseltemp": "68"})
        only_index = build_topics(REAL.split(), fields, publish_names=False)
        self.assertNotIn("kesseltemp", only_index)

    def test_reserved_names_are_ignored(self):
        fields = normalize_fields({"4": "Bridge", "5": "idx_3"})
        topics = build_topics(REAL.split(), fields, publish_index=False)
        self.assertEqual(topics, {})

    def test_php_list_format(self):
        # PHP schreibt Arrays mit Schlüsseln 0, 1, 2 als JSON-Liste
        fields = normalize_fields([{"name": "Header"}, {"name": "Leistung", "type": "dec", "bits": ["Bit null"]}])
        self.assertEqual(fields["1"]["bits"], {"0": "Bit null"})
        self.assertEqual(fields["1"]["type"], "dec")

    def test_slug(self):
        self.assertEqual(slug("Fremdwärme"), "fremdwaerme")
        self.assertEqual(slug("HK1 Soll"), "hk1_soll")
        self.assertEqual(slug("  Außen-Temp. (gem.) "), "aussen_temp_gem")


if __name__ == "__main__":
    unittest.main()
