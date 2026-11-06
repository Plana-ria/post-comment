#!/usr/bin/env python3
"""HTTP integration test for the real PHP entrypoint (development server only)."""
import csv
import html
import io
import re
import sys
from urllib.parse import urlencode
from urllib.request import build_opener, HTTPCookieProcessor, Request
from urllib.error import HTTPError
from http.cookiejar import CookieJar

BASE = "http://127.0.0.1:18901/"
SETUP = "a" * 64

def client():
    return build_opener(HTTPCookieProcessor(CookieJar()))

def request(opener, route="", values=None, expect=200):
    url = BASE + (("?" + route) if route else "")
    data = None if values is None else urlencode(values).encode("utf-8")
    req = Request(url, data=data, headers={"User-Agent": "post-comment-http-smoke"})
    try:
        with opener.open(req, timeout=10) as response:
            status, data = response.status, response.read()
    except HTTPError as err:
        status, data = err.code, err.read()
    if status != expect:
        raise AssertionError(f"HTTP {route}: expected {expect}, got {status}: {data[:450]!r}")
    return data.decode("utf-8-sig")

def token(markup):
    match = re.search(r'name="csrf" value="([0-9a-f]{64})"', markup)
    if not match:
        raise AssertionError("Form did not provide CSRF token")
    return match.group(1)

def must(fragment, content):
    if fragment not in content:
        raise AssertionError(f"Expected {fragment!r} in {content[:450]!r}")

admin = client()
setup = request(admin, "route=setup")
request(admin, "route=setup", {
    "csrf": token(setup), "setup_key": SETUP,
    "login_id": "owner", "secret": "very-long-system-admin-secret",
})
login = request(admin, "route=login")
request(admin, "route=login", {
    "csrf": token(login), "login_id": "owner",
    "secret": "very-long-system-admin-secret",
})
dashboard = request(admin, "route=home")
must("担当ルーム一覧", dashboard)
must("システム保守", dashboard)
rooms = request(admin, "route=rooms")
must("新規ルーム作成", rooms)

def create_room(slug, mode):
    form = request(admin, "route=room-create")
    return request(admin, "route=room-create", {
        "csrf":token(form), "name":"HTTP smoke "+slug,
        "slug":slug, "auth_mode":mode, "template":"standard",
    })

create_room("browser-room", "required")
participant = client()
registration = request(participant, "route=room&slug=browser-room")
must("新規ユーザー登録", registration)
registered = request(participant, "route=user-register", {
    "csrf":token(registration),"slug":"browser-room",
})
user_id = re.search(r"ユーザーID：<strong>([^<]+)</strong>",registered)
user_code = re.search(r"ログインコード：<strong>([^<]+)</strong>",registered)
if not user_id or not user_code:
    raise AssertionError("Random login code not shown exactly on registration")
form = request(participant, "route=room&slug=browser-room")
must("コメント", form)
sent = request(participant, "route=submit", {
    "csrf": token(form), "slug": "browser-room",
    "form_version":re.search(r'name="form_version" value="([a-f0-9]{32})"',form).group(1),
    "last_name":"TestSurname","first_name":"TestGiven",
    "affiliation":"TestAffiliation", "email":"test@example.com",
    "comment":"The test question",
})
must("コメントを送信しました", sent)
must("The test question", sent)
again = request(participant, "route=room&slug=browser-room")
if "コメントを送信しました" in again:
    raise AssertionError("Post confirmation should only be displayed once")
own = request(participant, "route=own-csv&slug=browser-room")
rows = list(csv.reader(io.StringIO(own)))
if rows[0] != ["time","comment"] or len(rows) != 2 or rows[1][1] != "The test question":
    raise AssertionError("Participant CSV is not exactly timestamp + comment")
if "TestSurname" in own or "TestAffiliation" in own or "test@example.com" in own:
    raise AssertionError("Participant CSV leaked private input values")
request(participant, "route=admin-csv&room_id=missing", expect=401)

admin_rooms = request(admin, "route=rooms")
link = re.search(r'\?route=room-admin&amp;room_id=([a-f0-9]{32})',admin_rooms)
if not link:
    raise AssertionError("Administrator room listing lacks a room ID")
room_id=link.group(1)
details=request(admin,"route=room-admin&room_id="+room_id)
must("The test question",details)
full=request(admin,"route=admin-csv&room_id="+room_id)
if "TestSurname" not in full or "TestAffiliation" not in full:
    raise AssertionError("Administrator CSV does not preserve form values")
import json
updates=json.loads(request(admin,"route=admin-updates&room_id="+room_id+"&after=0"))
if not any(c.get("comment_text")=="The test question" for c in updates["comments"]):
    raise AssertionError("Admin updates endpoint lost the post")

# A single system-wide identity must work in a second authenticated room.
create_room("browser-room-2","required")
other = client()
other_form=request(other,"route=room&slug=browser-room-2")
request(other,"route=user-login",{
    "csrf":token(other_form),"slug":"browser-room-2",
    "login_id":html.unescape(user_id.group(1)),
    "login_code":html.unescape(user_code.group(1)),
})
second=request(other,"route=room&slug=browser-room-2")
must("コメント",second)

# Anonymous sessions have scoped downloads without any login.
create_room("anonymous-room","none")
guest=client()
anonymous=request(guest,"route=room&slug=anonymous-room")
request(guest,"route=submit",{
    "csrf":token(anonymous),"slug":"anonymous-room",
    "form_version":re.search(r'name="form_version" value="([a-f0-9]{32})"',anonymous).group(1),
    "last_name":"AnonymousSurname","first_name":"Anon",
    "affiliation":"PrivateAffiliation","email":"",
    "comment":"Anonymous post",
})
anonymous_csv=request(guest,"route=own-csv&slug=anonymous-room")
if not anonymous_csv.startswith("time,comment") or "Anonymous post" not in anonymous_csv or "PrivateAffiliation" in anonymous_csv:
    raise AssertionError("Anonymous participant CSV failed")
print("HTTP integration tests passed")
