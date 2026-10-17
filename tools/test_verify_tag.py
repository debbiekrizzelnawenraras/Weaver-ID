"""Tests for verify_tag.py. No reader and no server needed:

    python -m unittest test_verify_tag -v
"""
import json
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import reader_bridge as rb
import verify_tag as vt

UID = "04AABBCCDDEE80"
PWD = bytes.fromhex("A1B2C3D4")
PACK = bytes.fromhex("5A5A")
CODE = bytes.fromhex("0123456789ABCDEF")


def secured_tag(code=CODE):
    tag = rb.FakeNtag215(UID)
    rb.secure_ntag215(tag, UID, PWD, PACK, code)
    tag.new_session()                      # tag leaves the reader and comes back
    return tag


class ReadLikeTheApp(unittest.TestCase):
    def test_reads_uid_counter_and_code(self):
        uid, counter, code = vt.read_like_the_app(secured_tag())
        self.assertEqual(uid, UID)
        self.assertGreaterEqual(counter, 1)
        self.assertEqual(code, CODE.hex().upper())

    def test_one_tap_counts_once(self):
        tag = secured_tag()
        before = tag.counter
        vt.read_like_the_app(tag)
        self.assertEqual(tag.counter, before + 1)

    def test_a_tag_without_a_code_gives_none(self):
        tag = rb.FakeNtag215(UID)
        rb.secure_ntag215(tag, UID, PWD, PACK)
        tag.new_session()
        _, counter, code = vt.read_like_the_app(tag)
        self.assertIsNotNone(counter)
        self.assertIsNone(code)

    def test_a_plain_tag_has_no_counter_and_no_code(self):
        _, counter, code = vt.read_like_the_app(rb.FakeNtag215(UID))
        self.assertIsNone(counter)
        self.assertIsNone(code)


class Request(unittest.TestCase):
    def test_body_has_all_fields_the_app_sends(self):
        body = vt.build_request(UID, 7, "0123456789ABCDEF")
        self.assertEqual(body, {"uid": UID, "device_id": vt.DEVICE_ID, "counter": 7, "code": "0123456789ABCDEF"})

    def test_missing_fields_are_left_out_like_the_app_does(self):
        self.assertEqual(vt.build_request(UID, None, None), {"uid": UID, "device_id": vt.DEVICE_ID})


class FakeServer(BaseHTTPRequestHandler):
    seen = []

    def log_message(self, *args):
        pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        FakeServer.seen.append((self.path, body))
        if body["uid"] == "BAD":
            reply, status = {"message": "The uid field format is invalid."}, 422
        else:
            reply, status = {"result": "authenticated", "message": "Genuine.",
                             "product": {"title": "Blanket", "category": "Weaving"},
                             "association": {"name": "Loom Art", "municipality": "Bontoc"}}, 200
        data = json.dumps(reply).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)


class Post(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.httpd = ThreadingHTTPServer(("127.0.0.1", 0), FakeServer)
        cls.url = "http://127.0.0.1:%d" % cls.httpd.server_address[1]
        threading.Thread(target=cls.httpd.serve_forever, daemon=True).start()

    @classmethod
    def tearDownClass(cls):
        cls.httpd.shutdown()
        cls.httpd.server_close()

    def test_posts_to_api_verify_and_describes_the_answer(self):
        status, data = vt.post_verify(self.url + "/", vt.build_request(UID, 3, "0123456789ABCDEF"))
        self.assertEqual(status, 200)
        self.assertEqual(FakeServer.seen[-1][0], "/api/verify")
        text = vt.describe(status, data)
        self.assertIn("AUTHENTICATED", text)
        self.assertIn("Blanket", text)
        self.assertIn("Loom Art", text)

    def test_an_error_answer_is_readable(self):
        status, data = vt.post_verify(self.url, vt.build_request("BAD", None, None))
        self.assertEqual(status, 422)
        self.assertIn("HTTP 422", vt.describe(status, data))

    def test_non_json_answer_does_not_crash(self):
        self.assertIn("not JSON", vt.describe(500, None))


class Arguments(unittest.TestCase):
    def test_no_arguments_gives_the_defaults(self):
        opts, error = vt.parse_args([])
        self.assertIsNone(error)
        self.assertEqual(opts, {"dry_run": False, "no_code": False, "server": vt.DEFAULT_SERVER,
                                "code": None, "counter": None, "device": None})

    def test_known_flags_are_understood(self):
        opts, error = vt.parse_args(["--dry-run", "--no-code", "--server", "http://127.0.0.1:9000"])
        self.assertIsNone(error)
        self.assertTrue(opts["dry_run"])
        self.assertTrue(opts["no_code"])
        self.assertEqual(opts["server"], "http://127.0.0.1:9000")

    def test_the_typo_that_ran_the_wrong_test_is_refused(self):
        opts, error = vt.parse_args(["--no", "code"])
        self.assertIsNone(opts)
        self.assertIn("--no", error)

    def test_a_near_miss_suggests_the_right_flag(self):
        _, error = vt.parse_args(["--nocode"])
        self.assertIn("Did you mean --no-code?", error)

    def test_unknown_flag_is_refused(self):
        opts, error = vt.parse_args(["--verbose"])
        self.assertIsNone(opts)
        self.assertIn("--verbose", error)

    def test_a_stray_word_is_refused(self):
        opts, error = vt.parse_args(["code"])
        self.assertIsNone(opts)
        self.assertIn("'code'", error)

    def test_server_needs_an_address(self):
        self.assertIsNotNone(vt.parse_args(["--server"])[1])
        self.assertIsNotNone(vt.parse_args(["--server", "--dry-run"])[1])


class ForgeryOptions(unittest.TestCase):
    def test_code_counter_and_device_are_understood(self):
        opts, error = vt.parse_args(["--code", "0000000000000000", "--counter", "5000", "--device", "phone-2"])
        self.assertIsNone(error)
        self.assertEqual((opts["code"], opts["counter"], opts["device"]), ("0000000000000000", 5000, "phone-2"))

    def test_code_is_upper_cased_like_the_real_one(self):
        self.assertEqual(vt.parse_args(["--code", "0a1b2c3d4e5f6a7b"])[0]["code"], "0A1B2C3D4E5F6A7B")

    def test_a_code_of_the_wrong_shape_is_refused(self):
        for bad in (["--code"], ["--code", "123"], ["--code", "ZZZZZZZZZZZZZZZZ"], ["--code", "00000000000000000"]):
            self.assertIsNotNone(vt.parse_args(bad)[1], bad)

    def test_counter_zero_and_the_maximum_are_allowed(self):
        self.assertEqual(vt.parse_args(["--counter", "0"])[0]["counter"], 0)
        self.assertEqual(vt.parse_args(["--counter", "16777215"])[0]["counter"], 16777215)

    def test_a_bad_counter_is_refused(self):
        for bad in (["--counter"], ["--counter", "-1"], ["--counter", "abc"], ["--counter", "1.5"],
                    ["--counter", "16777216"], ["--counter", "--dry-run"]):
            self.assertIsNotNone(vt.parse_args(bad)[1], bad)

    def test_a_bad_device_name_is_refused(self):
        for bad in (["--device"], ["--device", "--dry-run"], ["--device", "x" * 101]):
            self.assertIsNotNone(vt.parse_args(bad)[1], bad)

    def test_no_code_and_code_together_are_refused(self):
        self.assertIn("cannot be used together", vt.parse_args(["--no-code", "--code", "0000000000000000"])[1])

    def test_a_near_miss_suggests_the_new_flags(self):
        self.assertIn("--counter", vt.parse_args(["--conter", "5"])[1])
        self.assertIn("--device", vt.parse_args(["--devise", "x"])[1])

    def test_the_request_carries_the_device_name(self):
        body = vt.build_request(UID, 1, None, "phone-2")
        self.assertEqual(body["device_id"], "phone-2")
        self.assertEqual(vt.build_request(UID, 1, None)["device_id"], vt.DEVICE_ID)


if __name__ == "__main__":
    unittest.main()
