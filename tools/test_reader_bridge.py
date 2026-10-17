"""Tests for the tag-securing part of the reader bridge. No reader needed:

    python -m unittest test_reader_bridge -v
"""
import json
import threading
import unittest
import urllib.error
import urllib.request
from http.server import ThreadingHTTPServer

import reader_bridge as rb

UID = "04AABBCCDDEE80"
PWD = bytes.fromhex("A1B2C3D4")
PACK = bytes.fromhex("5A5A")


class SecureTag(unittest.TestCase):
    def test_secures_a_fresh_tag(self):
        tag = rb.FakeNtag215(UID)
        counter = rb.secure_ntag215(tag, UID, PWD, PACK)
        self.assertGreaterEqual(counter, 1)
        self.assertEqual(bytes(tag.mem[0x83]), b"\x00\x00\x00\x04")      # AUTH0 = page 4
        self.assertEqual(tag.mem[0x84][0], 0x10)                          # counter on, PROT 0, CFGLCK 0
        self.assertEqual(bytes(tag.mem[0x85]), PWD)
        self.assertEqual(bytes(tag.mem[0x86][0:2]), PACK)

    def test_never_sets_the_permanent_lock(self):
        tag = rb.FakeNtag215(UID)
        rb.secure_ntag215(tag, UID, PWD, PACK)
        self.assertEqual(tag.mem[0x84][0] & rb.ACCESS_CFGLCK, 0)

    def test_after_securing_anyone_can_read_but_not_write(self):
        tag = rb.FakeNtag215(UID)
        rb.secure_ntag215(tag, UID, PWD, PACK)
        tag.new_session()                                                 # tag leaves the field
        self.assertEqual(len(tag.read16(4)), 16)                          # phone can read
        with self.assertRaises(IOError):
            tag.write(4, b"\x01\x02\x03\x04")                             # stranger cannot write
        tag.thru(b"\x1b" + PWD)                                           # desk can
        tag.write(4, b"\x01\x02\x03\x04")

    def test_counter_goes_up_with_every_tap(self):
        tag = rb.FakeNtag215(UID)
        first = rb.secure_ntag215(tag, UID, PWD, PACK)
        seen = []
        for _ in range(3):
            tag.new_session()
            tag.read16(4)
            seen.append(int.from_bytes(tag.thru(b"\x39\x02"), "little"))
        self.assertEqual(seen, [first + 1, first + 2, first + 3])

    def test_a_second_tag_has_its_own_counter(self):
        original = rb.FakeNtag215(UID)
        rb.secure_ntag215(original, UID, PWD, PACK)
        for _ in range(20):
            original.new_session()
            original.read16(4)
        copy = rb.FakeNtag215(UID)                  # a blank copy with the same UID
        copy.mem = [bytearray(p) for p in original.mem]   # even with identical memory
        copy.counter = 0                            # the counter is not part of the memory
        copy.new_session()
        copy.read16(4)
        seen_copy = int.from_bytes(copy.thru(b"\x39\x02"), "little")
        seen_original = int.from_bytes(original.thru(b"\x39\x02"), "little")
        self.assertLess(seen_copy, seen_original)

    def test_can_run_again_on_a_tag_it_already_secured(self):
        tag = rb.FakeNtag215(UID)
        rb.secure_ntag215(tag, UID, PWD, PACK)
        tag.new_session()
        counter = rb.secure_ntag215(tag, UID, PWD, PACK)
        self.assertGreaterEqual(counter, 2)

    def test_refuses_a_tag_with_another_password(self):
        tag = rb.FakeNtag215(UID)
        rb.secure_ntag215(tag, UID, PWD, PACK)
        tag.new_session()
        with self.assertRaises(rb.SecureError):
            rb.secure_ntag215(tag, UID, bytes.fromhex("00000001"), PACK)

    def test_refuses_a_tag_whose_configuration_is_locked(self):
        tag = rb.FakeNtag215(UID)
        tag.mem[0x84][0] = rb.ACCESS_CFGLCK
        with self.assertRaises(rb.SecureError):
            rb.secure_ntag215(tag, UID, PWD, PACK)
        self.assertEqual(bytes(tag.mem[0x85]), bytes(4))                  # nothing was written

    def test_refuses_other_chips(self):
        tag = rb.FakeNtag215(UID)
        tag.version = bytes([0x00, 0x04, 0x04, 0x02, 0x01, 0x00, 0x0F, 0x03])   # NTAG213
        with self.assertRaises(rb.SecureError):
            rb.secure_ntag215(tag, UID, PWD, PACK)
        self.assertEqual(bytes(tag.mem[0x83]), b"\x00\x00\x00\xff")

    def test_refuses_the_wrong_tag(self):
        tag = rb.FakeNtag215(UID)
        with self.assertRaises(rb.SecureError):
            rb.secure_ntag215(tag, "04112233445566", PWD, PACK)
        self.assertEqual(bytes(tag.mem[0x85]), bytes(4))

    def test_bad_password_length(self):
        with self.assertRaises(rb.SecureError):
            rb.secure_ntag215(rb.FakeNtag215(UID), UID, b"\x01\x02", PACK)


CODE = bytes.fromhex("0011223344556677")


class TagCode(unittest.TestCase):
    def test_record_round_trip(self):
        record = rb.build_code_record(CODE)
        self.assertEqual(len(record), 28)
        self.assertEqual(record[:5], bytes([0x03, 0x16, 0xD4, 0x0B, 0x08]))
        self.assertEqual(rb.parse_code_record(record), "0011223344556677")

    def test_parse_ignores_other_memory(self):
        self.assertIsNone(rb.parse_code_record(bytes(28)))
        self.assertIsNone(rb.parse_code_record(b"\x03\x10\xd1\x01\x0c\x55" + bytes(22)))
        self.assertIsNone(rb.parse_code_record(b""))

    def test_code_must_be_eight_bytes(self):
        with self.assertRaises(ValueError):
            rb.build_code_record(b"\x01\x02")

    def test_securing_writes_the_code_and_the_phone_can_read_it(self):
        tag = rb.FakeNtag215(UID)
        rb.secure_ntag215(tag, UID, PWD, PACK, CODE)
        tag.new_session()                                                 # phone: no password
        raw = tag.read16(4) + tag.read16(8)
        self.assertEqual(rb.parse_code_record(raw), "0011223344556677")

    def test_a_stranger_cannot_change_the_code(self):
        tag = rb.FakeNtag215(UID)
        rb.secure_ntag215(tag, UID, PWD, PACK, CODE)
        tag.new_session()
        with self.assertRaises(IOError):
            tag.write(9, b"\xFF\xFF\xFF\xFF")

    def test_page_three_is_never_written(self):
        tag = rb.FakeNtag215(UID)
        tag.mem[3][:] = b"\xe1\x10\x3e\x00"
        rb.secure_ntag215(tag, UID, PWD, PACK, CODE)
        self.assertEqual(bytes(tag.mem[3]), b"\xe1\x10\x3e\x00")

    def test_securing_again_writes_the_code_on_an_old_secured_tag(self):
        tag = rb.FakeNtag215(UID)
        rb.secure_ntag215(tag, UID, PWD, PACK)                            # secured before the code existed
        tag.new_session()
        rb.secure_ntag215(tag, UID, PWD, PACK, CODE)
        tag.new_session()
        self.assertEqual(rb.parse_code_record(tag.read16(4) + tag.read16(8)), "0011223344556677")

    def test_without_a_code_nothing_is_written(self):
        tag = rb.FakeNtag215(UID)
        rb.secure_ntag215(tag, UID, PWD, PACK)
        tag.new_session()
        self.assertIsNone(rb.parse_code_record(tag.read16(4) + tag.read16(8)))

    def test_a_tag_that_refuses_the_write_gives_a_readable_error(self):
        class Refuses(rb.FakeNtag215):
            def write(self, page, data):
                if 4 <= page <= 10:
                    raise IOError("refused")
                super().write(page, data)
        with self.assertRaises(rb.SecureError):
            rb.secure_ntag215(Refuses(UID), UID, PWD, PACK, CODE)


class HttpEndpoint(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        rb.READER = rb.Reader(UID)                                       # fake reader with a fake tag
        cls.server = ThreadingHTTPServer(("127.0.0.1", 0), rb.Handler)
        cls.port = cls.server.server_address[1]
        threading.Thread(target=cls.server.serve_forever, daemon=True).start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()

    def post(self, body, origin="http://localhost:8000"):
        req = urllib.request.Request(
            "http://127.0.0.1:%d/secure" % self.port, data=json.dumps(body).encode(),
            headers={"Content-Type": "application/json", "Origin": origin}, method="POST")
        try:
            with urllib.request.urlopen(req) as res:
                return res.status, json.load(res)
        except urllib.error.HTTPError as err:
            return err.code, json.load(err)

    def test_secure_ok(self):
        rb.READER.fake_tag = rb.FakeNtag215(UID)
        code, out = self.post({"uid": UID, "password": "A1B2C3D4", "pack": "5A5A"})
        self.assertEqual(code, 200)
        self.assertTrue(out["ok"])
        self.assertGreaterEqual(out["counter"], 1)

    def test_secure_with_code_reports_it_was_written(self):
        rb.READER.fake_tag = rb.FakeNtag215(UID)
        code, out = self.post({"uid": UID, "password": "A1B2C3D4", "pack": "5A5A", "code": "0011223344556677"})
        self.assertEqual(code, 200)
        self.assertTrue(out["code_written"])
        rb.READER.fake_tag.new_session()
        raw = rb.READER.fake_tag.read16(4) + rb.READER.fake_tag.read16(8)
        self.assertEqual(rb.parse_code_record(raw), "0011223344556677")

    def test_secure_without_code_says_none_was_written(self):
        rb.READER.fake_tag = rb.FakeNtag215(UID)
        code, out = self.post({"uid": UID, "password": "A1B2C3D4", "pack": "5A5A"})
        self.assertEqual(code, 200)
        self.assertFalse(out["code_written"])

    def test_bad_code_is_rejected(self):
        self.assertEqual(self.post({"uid": UID, "password": "A1B2C3D4", "pack": "5A5A", "code": "0011"})[0], 400)

    def test_wrong_tag_gives_a_readable_error(self):
        rb.READER.fake_tag = rb.FakeNtag215(UID)
        code, out = self.post({"uid": "04112233445566", "password": "A1B2C3D4", "pack": "5A5A"})
        self.assertEqual(code, 422)
        self.assertIn("not the tag", out["error"])

    def test_rejects_bad_input(self):
        self.assertEqual(self.post({"uid": "zz", "password": "A1B2C3D4", "pack": "5A5A"})[0], 400)
        self.assertEqual(self.post({"uid": UID, "password": "A1", "pack": "5A5A"})[0], 400)
        self.assertEqual(self.post({"uid": UID, "password": "nothex!!", "pack": "5A5A"})[0], 400)

    def test_rejects_other_websites(self):
        code, _ = self.post({"uid": UID, "password": "A1B2C3D4", "pack": "5A5A"}, origin="https://evil.example")
        self.assertEqual(code, 403)

    def test_preflight_allows_post_from_localhost(self):
        req = urllib.request.Request("http://127.0.0.1:%d/secure" % self.port, method="OPTIONS",
                                     headers={"Origin": "http://localhost:8000"})
        with urllib.request.urlopen(req) as res:
            self.assertEqual(res.status, 204)
            self.assertIn("POST", res.headers["Access-Control-Allow-Methods"])


if __name__ == "__main__":
    unittest.main()
