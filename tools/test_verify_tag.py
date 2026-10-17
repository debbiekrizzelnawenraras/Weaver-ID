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
        self.assertEqual(opts, {"dry_run": False, "no_code": False, "server": vt.DEFAULT_SERVER})

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


if __name__ == "__main__":
    unittest.main()
